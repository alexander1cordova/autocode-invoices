<?php
// ============================================================
// AutoCode Invoices — client self-intake (public, no login).
// Opened via a single-use private link: intake.php?token=...
// The client fills in their info + vehicle (year/make/model required, VIN optional) + problem,
// optionally up to 5 vehicle photos (camera or library).
// On submit: client + vehicle + job (estimate) are created, the link
// is marked consumed, any valid photos are attached to the job,
// and the thank-you page shows. Refreshing or
// reopening a consumed link shows the thank-you page again — the form
// can never be submitted twice from the same link.
// Everything on this page is in English (client-facing).
// ============================================================
require_once __DIR__ . '/includes/functions.php';

$token = (string)(get_param('token') ?? '');
$link = find_intake_link($token);
if (!$link) {
    http_response_code(404);
    intake_shell('Invalid link', function () {
        ?>
        <div class="card center">
          <div class="bigcheck">🔗</div>
          <h2 class="doc-title">This link is invalid or has expired.</h2>
          <p>Please contact AutoCode at (702) 608-0202 for a new link.</p>
        </div>
        <?php
    });
    exit;
}

$done = !empty($link['consumed_at']) || get_param('done') === '1';

/* ---------- Form submission ---------- */
$errors = [];
$old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done) {
    // Re-check the link: still valid and not consumed (double-submit safe).
    $link = find_intake_link($token);
    if (!$link || !empty($link['consumed_at'])) {
        $done = true;
    } elseif (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // The upload exceeded the server's post_max_size, so PHP emptied
        // $_POST/$_FILES. Photos are optional — invite a lighter retry
        // instead of failing with confusing "required" errors.
        $errors[] = 'The photos were too large to send. Please try again with fewer or smaller photos — or send the form without photos.';
    } else {
        $req = ['first_name', 'last_name', 'phone', 'address', 'year', 'make', 'model', 'problem'];
        foreach (['first_name', 'last_name', 'phone', 'address', 'email',
                  'vin', 'year', 'make', 'model', 'plate', 'mileage', 'color',
                  'problem'] as $f) {
            $old[$f] = trim((string)($_POST[$f] ?? ''));
        }
        $labels = ['first_name' => 'First name', 'last_name' => 'Last name',
                   'phone' => 'Phone', 'address' => 'Address',
                   'year' => 'Year', 'make' => 'Make', 'model' => 'Model',
                   'problem' => 'Problem description'];
        foreach ($req as $f) {
            if ($old[$f] === '') {
                $errors[] = $labels[$f] . ' is required.';
            }
        }
        if (!$errors) {
            $photos_saved = 0;
            try {
                db()->beginTransaction();
                $st = db()->prepare(
                    'INSERT INTO clients (first_name, last_name, phone, email, address, notes)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$old['first_name'], $old['last_name'], $old['phone'],
                              $old['email'], $old['address'], 'Added via client intake link.']);
                $client_id = (int)db()->lastInsertId();

                $vehicle_id = 0;
                $st = db()->prepare(
                    'INSERT INTO vehicles (client_id, year, make, model, vin, plate, mileage, color)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$client_id, $old['year'], $old['make'], $old['model'],
                              strtoupper($old['vin']), $old['plate'], $old['mileage'], $old['color']]);
                $vehicle_id = (int)db()->lastInsertId();

                $st = db()->prepare(
                    "INSERT INTO jobs (client_id, vehicle_id, problem_title, status)
                     VALUES (?, ?, ?, 'estimate')"
                );
                $st->execute([$client_id, $vehicle_id ?: null, $old['problem']]);
                $job_id = (int)db()->lastInsertId();
                db()->commit();

                consume_intake_link($token, $client_id);

                // Optional vehicle photos (max 5). They must never fail the
                // intake: each file is validated on its own and skipped
                // quietly if it is not a usable image.
                $photos_saved = save_intake_photos($job_id);
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                error_log('Intake submit failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong saving your information. Please try again.';
            }
        }
        if (!$errors) {
            // Notify Migue: a client just completed the intake form.
            $vlabel = trim(implode(' ', array_filter([$old['year'], $old['make'], $old['model']])));
            notify_owner(
                'Nuevo registro de cliente: ' . trim($old['first_name'] . ' ' . $old['last_name']),
                "Un cliente acaba de completar el formulario de registro.\n\n" .
                'Nombre: ' . trim($old['first_name'] . ' ' . $old['last_name']) . "\n" .
                'Telefono: ' . $old['phone'] . "\n" .
                'Vehiculo: ' . ($vlabel !== '' ? $vlabel : '—') . "\n" .
                'VIN: ' . strtoupper($old['vin']) . "\n" .
                'Problema: ' . $old['problem'] . "\n" .
                'Fotos: ' . $photos_saved . "\n\n" .
                'Ver el trabajo: ' . app_base_url() . '/job.php?id=' . $job_id
            );
            redirect('intake.php?token=' . $token . '&done=1&photos=' . $photos_saved);
        }
    }
}

/* ---------- Optional vehicle photos (max 5, never block the intake) ---------- */

/**
 * Save the client's optional vehicle photos onto the new job.
 * Returns how many were saved. Each file is validated on its own;
 * unusable files are skipped quietly so a photo problem can never
 * break the intake submission (the link is single-use — no retry).
 */
function save_intake_photos(int $job_id): int
{
    $files = $_FILES['photos'] ?? null;
    if (!$files || !is_array($files['tmp_name'] ?? null)) {
        return 0;
    }
    $saved = 0;
    $n = min(count($files['tmp_name']), 5);
    for ($i = 0; $i < $n; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        try {
            save_job_photo($job_id, [
                'name'     => (string)$files['name'][$i],
                'type'     => (string)$files['type'][$i],
                'tmp_name' => (string)$files['tmp_name'][$i],
                'error'    => (int)$files['error'][$i],
                'size'     => (int)$files['size'][$i],
            ]);
            $saved++;
        } catch (Throwable $e) {
            error_log('Intake photo skipped for job ' . $job_id . ': ' . $e->getMessage());
        }
    }
    return $saved;
}

/* ---------- Thank-you page (also shown for consumed links) ---------- */
if ($done) {
    $np = max(0, (int)get_param('photos'));
    intake_shell('Thank you', function () use ($np) {
        ?>
        <div class="card center">
          <div class="bigcheck">✅</div>
          <h2 class="doc-title">Thank you!</h2>
          <p class="thanks">Thanks for joining the AutoCode system.<br>We&rsquo;ll be in touch as soon as possible.</p>
          <?php if ($np > 0): ?>
          <p class="hint">We received your <?= $np ?> photo<?= $np === 1 ? '' : 's' ?> — thank you.</p>
          <?php endif; ?>
          <p class="hint">AutoCode · (702) 608-0202</p>
        </div>
        <?php
    });
    exit;
}

/* ---------- Intake form ---------- */
intake_shell('New Customer Info', function () use ($token, $errors, $old) {
    $v = function (string $f) use ($old): string {
        return e((string)($old[$f] ?? ''));
    };
    ?>
    <div class="card">
      <h2 class="doc-title">Tell us about you and your vehicle</h2>
      <p class="hint">Fill in the form below and we&rsquo;ll take it from there. Fields marked * are required.</p>
      <?php if ($errors): ?>
        <div class="err">
          <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <form method="post" action="intake.php?token=<?= e($token) ?>" autocomplete="on" enctype="multipart/form-data" id="intake_form">
        <h3>Your information</h3>
        <div class="frow">
          <div><label class="flabel" for="i_first">First name <span class="req">*</span></label>
            <input type="text" id="i_first" name="first_name" required value="<?= $v('first_name') ?>"></div>
          <div><label class="flabel" for="i_last">Last name <span class="req">*</span></label>
            <input type="text" id="i_last" name="last_name" required value="<?= $v('last_name') ?>"></div>
        </div>
        <label class="flabel" for="i_phone">Phone <span class="req">*</span></label>
        <input type="tel" id="i_phone" name="phone" required value="<?= $v('phone') ?>">
        <label class="flabel" for="i_addr">Address <span class="req">*</span></label>
        <input type="text" id="i_addr" name="address" required value="<?= $v('address') ?>">

        <h3>Vehicle</h3>
        <label class="flabel" for="i_vin">VIN <span class="hint">(optional)</span></label>
        <input type="text" id="i_vin" name="vin" maxlength="17" value="<?= $v('vin') ?>"
               placeholder="17 characters" style="text-transform:uppercase">
        <p class="hint">If you have it: the 17-character VIN is on your dashboard (driver&rsquo;s side) or door sticker.</p>
        <div class="frow">
          <div><label class="flabel" for="i_year">Year <span class="req">*</span></label>
            <input type="text" id="i_year" name="year" required inputmode="numeric" value="<?= $v('year') ?>"></div>
          <div><label class="flabel" for="i_make">Make <span class="req">*</span></label>
            <input type="text" id="i_make" name="make" required placeholder="Ford" value="<?= $v('make') ?>"></div>
          <div><label class="flabel" for="i_model">Model <span class="req">*</span></label>
            <input type="text" id="i_model" name="model" required placeholder="F-150" value="<?= $v('model') ?>"></div>
        </div>

        <h3>Photos of the vehicle <span class="hint">(optional)</span></h3>
        <p class="hint">Up to 5 photos — damage, warning lights on the dash, or the problem area. This helps us prepare before we arrive. Not required.</p>
        <style>
          #photo_thumbs{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 4px}
          .photo-thumb{position:relative;width:84px;height:84px}
          .photo-thumb img{width:84px;height:84px;object-fit:cover;border-radius:8px;border:1px solid #d5dde5;display:block}
          .photo-x{position:absolute;top:-9px;right:-9px;width:26px;height:26px;border-radius:50%;border:2px solid #fff;background:#c0392b;color:#fff;font-size:15px;line-height:1;cursor:pointer;padding:0}
        </style>
        <div class="photo-upload-row">
          <label class="btn">📷 Take a photo
            <input type="file" id="ip_cam" name="photos[]" accept="image/*" capture="environment" hidden>
          </label>
          <label class="btn secondary">🖼 Choose from library
            <input type="file" id="ip_lib" name="photos[]" accept="image/*" multiple hidden>
          </label>
        </div>
        <div id="photo_thumbs"></div>
        <p class="hint" id="photo_count"></p>

        <h3>What&rsquo;s going on with the vehicle?</h3>
        <label class="flabel" for="i_prob">Describe the problem <span class="req">*</span></label>
        <textarea id="i_prob" name="problem" required rows="4"
          placeholder="e.g. Car cranks but won&rsquo;t start, started after the battery was replaced…"><?= $v('problem') ?></textarea>

        <div class="actions">
          <button class="btn big green" type="submit"
                  onclick="this.disabled=true;this.form.submit();">Send</button>
        </div>
      </form>
      <script>
      // Optional vehicle photos: downscale on the phone (max 1600px, JPEG)
      // so uploads stay small on mobile data, and enforce max 5 total.
      // If anything here fails, the originals are sent and the server
      // validates/resizes them — the intake itself never breaks.
      (function () {
        var cam = document.getElementById('ip_cam');
        var lib = document.getElementById('ip_lib');
        var count = document.getElementById('photo_count');
        var thumbs = document.getElementById('photo_thumbs');
        var MAX = 5;
        function entries() {
          var out = [];
          [cam, lib].forEach(function (input) {
            Array.prototype.forEach.call(input.files, function (f) {
              out.push({ input: input, file: f });
            });
          });
          return out;
        }
        function total() { return entries().length; }
        function removeEntry(input, file) {
          var dt = new DataTransfer();
          Array.prototype.forEach.call(input.files, function (f) {
            if (f !== file) dt.items.add(f);
          });
          input.files = dt.files;
          render();
        }
        function render() {
          thumbs.innerHTML = '';
          entries().forEach(function (en) {
            var wrap = document.createElement('div');
            wrap.className = 'photo-thumb';
            var img = document.createElement('img');
            img.src = URL.createObjectURL(en.file);
            img.alt = 'Selected photo';
            var x = document.createElement('button');
            x.type = 'button';
            x.className = 'photo-x';
            x.textContent = '\u00d7';
            x.setAttribute('aria-label', 'Remove this photo');
            x.addEventListener('click', function () {
              URL.revokeObjectURL(img.src);
              removeEntry(en.input, en.file);
            });
            wrap.appendChild(img);
            wrap.appendChild(x);
            thumbs.appendChild(wrap);
          });
          var t = total();
          count.textContent = t ? (t + ' of ' + MAX + ' photos — tap \u00d7 to remove one') : '';
        }
        function downscale(f) {
          return new Promise(function (resolve) {
            if (!f.type || f.type.indexOf('image/') !== 0) return resolve(f);
            var img = new Image();
            img.onload = function () {
              try {
                var M = 1600, s = Math.min(1, M / Math.max(img.width, img.height));
                var w = Math.max(1, Math.round(img.width * s));
                var h = Math.max(1, Math.round(img.height * s));
                var c = document.createElement('canvas');
                c.width = w; c.height = h;
                c.getContext('2d').drawImage(img, 0, 0, w, h);
                c.toBlob(function (b) {
                  URL.revokeObjectURL(img.src);
                  resolve(b ? new File([b], (f.name || 'photo').replace(/\.[^.]*$/, '') + '.jpg', { type: 'image/jpeg' }) : f);
                }, 'image/jpeg', 0.82);
              } catch (e) { resolve(f); }
            };
            img.onerror = function () { resolve(f); };
            img.src = URL.createObjectURL(f);
          });
        }
        function handle(input) {
          var files = Array.prototype.slice.call(input.files);
          var room = MAX - (total() - files.length);
          files = files.slice(0, Math.max(0, room));
          var dt = new DataTransfer();
          (async function () {
            for (var i = 0; i < files.length; i++) {
              try { dt.items.add(await downscale(files[i])); }
              catch (e) { dt.items.add(files[i]); }
            }
            input.files = dt.files;
            render();
          })();
        }
        if (cam && lib && thumbs && window.DataTransfer) {
          cam.addEventListener('change', function () { handle(cam); });
          lib.addEventListener('change', function () { handle(lib); });
          render();
        }
      })();
      </script>
    </div>
    <?php
});
exit;

/* ---------- Page shell (same brand look as the share pages) ---------- */
function intake_shell(string $title, callable $body): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — AutoCode</title>
<meta property="og:title" content="<?= e($title) ?> — AutoCode">
<meta property="og:site_name" content="AutoCode">
<link rel="stylesheet" href="assets/css/style.css?v=20261006">
<style>
.rband{background:#004070;color:#fff;padding:18px 16px;text-align:center}
.rband h1{margin:0;font-size:22px;letter-spacing:.5px;color:#fff}
.rband p{margin:4px 0 0;font-size:13px;opacity:.9}
.rwrap{max-width:640px;margin:0 auto;padding:16px}
.card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.07);margin-bottom:14px}
.center{text-align:center}
.doc-title{color:#004070;margin:0 0 6px;font-size:20px}
h3{color:#004070;font-size:16px;margin:18px 0 4px}
.thanks{font-size:17px}
.bigcheck{font-size:44px}
.hint{font-size:13px;color:#69737d}
.err{background:#fdecec;color:#b3261e;border-radius:8px;padding:10px 12px;font-size:14px;margin-bottom:12px}
.rfoot{text-align:center;color:#69737d;font-size:12px;padding:18px}
input[type=text],input[type=tel],input[type=email],textarea{width:100%;padding:12px;border:1px solid #ccd6e0;border-radius:8px;font-size:16px;box-sizing:border-box;font-family:inherit}
textarea{min-height:96px;resize:vertical}
label.flabel{display:block;font-weight:600;margin:10px 0 4px;color:#1a2430}
label.flabel .req{color:#c0392b;font-weight:700}
.frow{display:grid;grid-template-columns:1fr;gap:0 10px}
@media(min-width:560px){.frow{grid-template-columns:1fr 1fr}}
.frow.three{grid-template-columns:1fr}
@media(min-width:560px){.frow.three{grid-template-columns:1fr 1fr 1fr}}
.actions{margin-top:16px}
.btn.big{width:100%;font-size:17px;padding:14px 22px}
.photo-upload-row{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0}
.photo-upload-row .btn{cursor:pointer}
.btn.secondary{background:#64748b}
@media(max-width:560px){.photo-upload-row .btn{flex:1 1 100%;text-align:center}}
</style>
</head>
<body>
<div class="rband"><h1>AutoCode</h1><p>4240 E Lake Mead Blvd, Suite 110, Las Vegas, NV 89115 · (702) 608-0202</p></div>
<div class="rwrap">
<?php $body(); ?>
<div class="rfoot">Private intake link · AutoCode · (702) 608-0202</div>
</div>
</body>
</html>
    <?php
}
