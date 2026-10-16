<?php
// AutoCode Invoices — the living job document.
// Tabs: Estimate | Authorization | Invoice. Signatures, snapshots, PDFs.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$job_id = (int)get_param('id');
$job = $job_id ? get_job($job_id) : null;
if (!$job) {
    http_response_code(404);
    exit('Job not found.');
}
$status = (string)$job['status'];

// Which tabs are reachable at this status
$tabs_allowed = [
    'estimate'      => true,
    'authorization' => $status !== 'estimate',
    'invoice'       => in_array($status, ['invoiced', 'paid'], true),
];
$tab = get_param('tab');
if (!isset($tabs_allowed[$tab]) || !$tabs_allowed[$tab]) {
    $tab = $status === 'estimate' ? 'estimate'
        : ($status === 'authorized' ? 'authorization' : 'invoice');
}

// Finalized (immutable) stages
$snap_est  = get_snapshot($job_id, 'estimate');
$snap_auth = get_snapshot($job_id, 'authorization');
$snap_inv  = get_snapshot($job_id, 'invoice');

// Direct (quick) invoice: created straight into "invoiced" status, so no
// estimate or authorization ever existed — hide those tabs to avoid confusion.
$is_direct_invoice = $status === 'invoiced' && !$snap_auth;
if ($is_direct_invoice) {
    $tabs_allowed['estimate'] = false;
    $tabs_allowed['authorization'] = false;
    if (!isset($tabs_allowed[$tab]) || !$tabs_allowed[$tab]) {
        $tab = 'invoice';
    }
}

/* ---------------- POST actions ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $redirect_anchor = ''; // e.g. jump back to the created client link
    try {
        switch ($action) {
            case 'add_line': {
                $stage_added = $status === 'estimate' ? 'estimate'
                    : ($status === 'authorized' ? 'authorization' : 'invoice');
                $locked = ($stage_added === 'estimate' && $snap_est)
                    || ($stage_added === 'authorization' && $snap_auth)
                    || ($stage_added === 'invoice' && $snap_inv);
                $ok_status = ($status === 'estimate')
                    || ($status === 'authorized' && $tab === 'authorization')
                    || ($status === 'invoiced');
                if (!$ok_status || $locked) {
                    throw new RuntimeException('Lines cannot be added at this stage.');
                }
                $desc = post('description');
                $qty  = (float)post('qty', '1');
                $price = (float)post('unit_price', '0');
                $kind = post('kind') === 'part' ? 'part' : 'service';
                if ($desc === '') {
                    throw new RuntimeException('Line description is required.');
                }
                if ($qty <= 0) {
                    throw new RuntimeException('Quantity must be greater than zero.');
                }
                $st = db()->prepare(
                    'INSERT INTO job_lines (job_id, kind, description, qty, unit_price, stage_added)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$job_id, $kind, $desc, $qty, $price, $stage_added]);
                set_flash('Line added.');
                break;
            }

            case 'update_line': {
                $line_ok = ($status === 'estimate' && !$snap_est)
                    || ($status === 'authorized' && !$snap_auth)
                    || ($status === 'invoiced' && !$snap_inv);
                if (!$line_ok) {
                    throw new RuntimeException('Lines cannot be edited at this stage.');
                }
                $line_id = (int)post('line_id');
                $desc = post('description');
                $qty  = (float)post('qty', '1');
                $price = (float)post('unit_price', '0');
                if ($desc === '' || $qty <= 0) {
                    throw new RuntimeException('Invalid line data.');
                }
                $st = db()->prepare(
                    'UPDATE job_lines SET description = ?, qty = ?, unit_price = ?
                     WHERE id = ? AND job_id = ?'
                );
                $st->execute([$desc, $qty, $price, $line_id, $job_id]);
                set_flash('Line updated.');
                break;
            }

            case 'delete_line': {
                $line_id = (int)post('line_id');
                $can = ($status === 'estimate' && !$snap_est)
                    || ($status === 'authorized' && !$snap_auth)
                    || ($status === 'invoiced' && !$snap_inv);
                if (!$can) {
                    throw new RuntimeException('Lines cannot be deleted at this stage.');
                }
                $st = db()->prepare('DELETE FROM job_lines WHERE id = ? AND job_id = ?');
                $st->execute([$line_id, $job_id]);
                set_flash('Line deleted.');
                break;
            }

            case 'finalize_estimate': {
                if ($status !== 'estimate' || $snap_est) {
                    throw new RuntimeException('Estimate cannot be finalized now.');
                }
                if (!job_lines($job_id)) {
                    throw new RuntimeException('Add at least one line before finalizing.');
                }
                save_snapshot($job_id, 'estimate');
                set_flash('Estimate finalized. PDF is ready.');
                break;
            }

            case 'to_authorization': {
                if ($status !== 'estimate') {
                    throw new RuntimeException('Job is not in estimate stage.');
                }
                db()->prepare('UPDATE jobs SET status = "authorized" WHERE id = ?')->execute([$job_id]);
                set_flash('Job converted to Repair Authorization.');
                $tab = 'authorization';
                break;
            }

            case 'sign_authorization': {
                if ($status !== 'authorized' || $snap_auth) {
                    throw new RuntimeException('Authorization cannot be signed now.');
                }
                $name = post('signer_name');
                $sig  = (string)($_POST['sigdata'] ?? '');
                if ($name === '') {
                    throw new RuntimeException('Signer name is required.');
                }
                if ($sig === '') {
                    throw new RuntimeException('Signature is required.');
                }
                store_signature($job_id, 'authorization', $name, $sig);
                save_snapshot($job_id, 'authorization');
                set_flash('Repair Authorization signed and finalized.');
                break;
            }

            case 'to_invoice': {
                if ($status !== 'authorized' || !$snap_auth) {
                    throw new RuntimeException('Finalize the authorization before invoicing.');
                }
                db()->prepare('UPDATE jobs SET status = "invoiced" WHERE id = ?')->execute([$job_id]);
                set_flash('Job converted to Final Invoice.');
                $tab = 'invoice';
                break;
            }

            case 'finalize_invoice': {
                if ($status !== 'invoiced' || $snap_inv) {
                    throw new RuntimeException('Invoice cannot be finalized now.');
                }
                if (!job_lines($job_id)) {
                    throw new RuntimeException('Add at least one line before finalizing the invoice.');
                }
                save_snapshot($job_id, 'invoice');
                set_flash('Invoice finalized — it is now locked and ready to share.');
                break;
            }

            case 'mark_paid': {
                if ($status !== 'invoiced' || !$snap_inv) {
                    throw new RuntimeException('Only a finalized invoice can be marked as paid.');
                }
                db()->prepare('UPDATE jobs SET status = "paid" WHERE id = ?')->execute([$job_id]);
                set_flash('Job marked as paid.');
                break;
            }

            case 'save_payment': {
                save_job_payment($job_id, post('payment_method'), post('payment_notes'), $_FILES['payment_photo'] ?? []);
                set_flash('Payment info saved.');
                break;
            }

            case 'delete_payment_photo': {
                delete_job_payment_photo($job_id);
                set_flash('Receipt photo removed.');
                break;
            }

            case 'save_diagnosis': {
                save_job_diagnosis($job_id, post('diagnosis_result'));
                set_flash('Diagnostic results saved.');
                break;
            }

            case 'create_share_link': {
                $new_stage = post('link_stage');
                $link = create_share_link($job_id, $new_stage);
                set_flash('Client link created. Copy it and send it to your client.');
                $redirect_anchor = '#sharelink-' . $new_stage;
                break;
            }

            case 'upload_photos': {
                $files = $_FILES['photos'] ?? null;
                if (!$files || !is_array($files['tmp_name'] ?? null)) {
                    throw new RuntimeException('No photos selected.');
                }
                $n = count($files['tmp_name']);
                if ($n > 20) {
                    throw new RuntimeException('Upload at most 20 photos at a time.');
                }
                $ok = 0;
                $errs = [];
                for ($i = 0; $i < $n; $i++) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $one = [
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                    ];
                    try {
                        save_job_photo($job_id, $one, post('photo_category'));
                        $ok++;
                    } catch (RuntimeException $ex) {
                        $errs[] = $files['name'][$i] . ': ' . $ex->getMessage();
                    }
                }
                if ($ok === 0 && $errs) {
                    throw new RuntimeException('Could not save photos. ' . implode(' ', $errs));
                }
                set_flash($ok . ($ok === 1 ? ' photo added.' : ' photos added.'));
                if ($errs) {
                    set_flash_err('Some photos were skipped: ' . implode(' ', $errs));
                }
                break;
            }

            case 'set_photo_caption': {
                set_job_photo_caption((int)post('photo_id'), $job_id, post('caption'));
                set_flash('Caption saved.');
                break;
            }

            case 'set_photo_category': {
                set_job_photo_category((int)post('photo_id'), $job_id, post('category'));
                set_flash('Photo moved to ' . photo_category_label(post('category')) . '.');
                break;
            }

            case 'rotate_photo': {
                rotate_job_photo((int)post('photo_id'), $job_id);
                set_flash('Photo rotated 90°.');
                break;
            }

            case 'delete_photo': {
                delete_job_photo((int)post('photo_id'), $job_id);
                set_flash('Photo deleted.');
                break;
            }

            case 'reopen_estimate': {
                reopen_estimate($job_id);
                set_flash('Estimate reopened — edit it and finalize it again. The client link will show the new version.');
                break;
            }

            case 'delete_job': {
                $del_client = (int)$job['client_id'];
                delete_job($job_id);
                touch_client($del_client);
                set_flash('Job deleted.');
                redirect('jobs.php');
                break;
            }

            default:
                throw new RuntimeException('Unknown action.');
        }
        touch_client((int)$job['client_id']);
        touch_job($job_id);
    } catch (Throwable $t) {
        set_flash_err($t->getMessage());
    }
    redirect('job.php?id=' . $job_id . '&tab=' . $tab . $redirect_anchor);
}

/* ---------------- Render ---------------- */

$job     = get_job($job_id); // refresh after possible POST
$status  = (string)$job['status'];
$client  = get_client((int)$job['client_id']);
$vehicle = !empty($job['vehicle_id']) ? get_vehicle((int)$job['vehicle_id']) : null;
$lines   = job_lines($job_id);
$biz     = business_info();
$totals  = job_totals($lines, $biz['tax_rate']);
$sig_auth = get_signature($job_id, 'authorization');

$est_editable  = $status === 'estimate' && !$snap_est;
$auth_addable  = $status === 'authorized' && !$snap_auth;
$auth_signable = $status === 'authorized' && !$snap_auth && count($lines) > 0;
$inv_editable  = $status === 'invoiced' && !$snap_inv;
$inv_finalizable = $status === 'invoiced' && !$snap_inv && count($lines) > 0;
$pay = job_payment($job_id);
$diagnosis = job_diagnosis($job_id);

page_header('Job #' . $job_id, 'dashboard');

function tab_link(int $job_id, string $key, string $step, string $label, bool $allowed, bool $on): string
{
    $cls = ($on ? 'on ' : '') . ($allowed ? '' : 'disabled');
    return '<a class="' . trim($cls) . '" href="job.php?id=' . $job_id . '&tab=' . $key . '">'
        . '<span class="step">' . e($step) . '</span>' . e($label) . '</a>';
}

/** "Send to client" box: creates/shows the private token link for a stage. */
function share_link_box(int $job_id, string $stage, string $label, string $purpose): string
{
    $link = get_share_link($job_id, $stage);
    $h = '<div class="sharebox"><h3>Client link — ' . e($label) . '</h3>';
    if ($link) {
        $url = share_link_url($link['token']);
        $h .= '<p class="hint">Send this private link to your client ' . e($purpose) . ':</p>'
            . '<div class="sharelink-row">'
            . '<input type="text" readonly id="sharelink-' . $stage . '" value="' . e($url) . '" onclick="this.select()">'
            . '<button class="btn small" type="button" data-copy="sharelink-' . $stage . '">Copy</button>'
            . '</div>';
    } else {
        $h .= '<p class="hint">Create a private link so your client can open this ' . e($label) . ' ' . e($purpose) . ' — no login needed.</p>'
            . '<form method="post" action="job.php?id=' . $job_id . '&tab=' . $stage . '">'
            . csrf_field()
            . '<input type="hidden" name="action" value="create_share_link">'
            . '<input type="hidden" name="link_stage" value="' . e($stage) . '">'
            . '<button class="btn" type="submit">Create client link</button></form>';
    }
    return $h . '</div>';
}
$photos = get_job_photos($job_id);
?>
<div class="page-head">
  <h1>Job #<?= $job_id ?> <?= status_badge($status) ?></h1>
  <form method="post" action="job.php?id=<?= $job_id ?>" style="display:inline"
        onsubmit="return confirm('<?= $sig_auth ? 'WARNING: this job has a SIGNED authorization. ' : '' ?>Delete job #<?= $job_id ?> and ALL its data (lines, documents, photos, links)? This cannot be undone.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_job">
    <button class="btn danger" type="submit">Delete this job</button>
  </form>
</div>

<div class="card">
  <div class="grid2">
    <div>
      <h2>Customer</h2>
      <dl class="kv">
        <dt>Name</dt><dd><a class="rowlink" href="client.php?id=<?= (int)$job['client_id'] ?>"><?= e($client ? client_name($client) : '—') ?></a></dd>
        <dt>Phone</dt><dd><?= e($client['phone'] ?? '—') ?></dd>
        <dt>Email</dt><dd><?= e($client['email'] ?? '—') ?></dd>
      </dl>
    </div>
    <div>
      <h2>Vehicle</h2>
      <dl class="kv">
        <dt>Vehicle</dt><dd><?= e(vehicle_label($vehicle)) ?></dd>
        <dt>VIN</dt><dd><?= e($vehicle['vin'] ?? '—') ?></dd>
        <dt>Mileage</dt><dd><?= e($vehicle['mileage'] ?? '—') ?></dd>
      </dl>
    </div>
  </div>
  <p style="margin-bottom:0"><strong>Problem / requested work:</strong> <?= e($job['problem_title']) ?></p>
  <p class="hint">Opened <?= e(fmt_date($job['created_at'])) ?> · Updated <?= e(fmt_date($job['updated_at'])) ?></p>
</div>

<div class="card">
  <h2>Work photos <?= $photos ? '<span class="hint">· ' . count($photos) . '</span>' : '' ?></h2>
  <p class="hint">Document the job: damage found, work in progress, finished repairs. Photos never appear on the PDF. Only you can add or delete them.</p>
  <?php if ($photos): ?>
    <?php $photo_groups = group_photos_by_category($photos); ?>
    <?php foreach ($photo_groups as $gkey => $gphotos): ?>
      <div class="phcat-bar"><?= e(photo_category_label($gkey)) ?> <span>· <?= count($gphotos) ?></span></div>
      <?php if (!$gphotos): ?>
        <p class="hint">No photos in this group yet.</p>
      <?php else: ?>
      <div class="photo-grid">
        <?php foreach ($gphotos as $ph):
          // Cache-buster so a rotated photo shows the new pixels immediately.
          $phv = (int)strtotime((string)($ph['updated_at'] ?? $ph['created_at'] ?? ''));
          $phsrc = 'photo.php?id=' . (int)$ph['id'] . ($phv ? '&u=' . $phv : '');
        ?>
        <div class="photo-item">
          <a href="<?= e($phsrc) ?>" target="_blank"><img src="<?= e($phsrc) ?>" alt="<?= e($ph['caption'] ?: 'Work photo') ?>" loading="lazy"></a>
          <form method="post" action="job.php?id=<?= $job_id ?>" class="photo-caption">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_photo_caption">
            <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
            <input type="text" name="caption" maxlength="200" placeholder="Add a caption…" value="<?= e($ph['caption']) ?>">
            <button class="btn small" type="submit">Save</button>
          </form>
          <form method="post" action="job.php?id=<?= $job_id ?>" class="photo-move">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_photo_category">
            <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
            <label class="hint">Move to
              <select name="category" onchange="this.form.submit()">
                <?php foreach (PHOTO_CATEGORIES as $ckey => $clabel): ?>
                  <option value="<?= e($ckey) ?>" <?= $ckey === photo_category_key((string)($ph['category'] ?? '')) ? 'selected' : '' ?>><?= e($clabel) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </form>
          <form method="post" action="job.php?id=<?= $job_id ?>" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rotate_photo">
            <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
            <button class="btn small" type="submit" title="Rotate 90° clockwise">⟳ Rotate</button>
          </form>
          <form method="post" action="job.php?id=<?= $job_id ?>" style="display:inline" onsubmit="return confirm('Delete this photo?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_photo">
            <input type="hidden" name="photo_id" value="<?= (int)$ph['id'] ?>">
            <button class="btn small danger" type="submit">Delete</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php else: ?>
  <p class="hint">No photos yet.</p>
  <?php endif; ?>
  <form method="post" action="job.php?id=<?= $job_id ?>" enctype="multipart/form-data" class="photo-upload">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_photos">
    <div class="photo-upload-row">
      <label class="btn">📷 Take photo
        <input type="file" id="wp_cam" name="photos[]" accept="image/*" capture="environment" hidden>
      </label>
      <label class="btn secondary">🖼 Choose existing
        <input type="file" id="wp_lib" name="photos[]" accept="image/*" multiple hidden>
      </label>
      <label class="hint" style="align-self:center">Add to
        <select name="photo_category">
          <?php foreach (PHOTO_CATEGORIES as $ckey => $clabel): ?>
            <option value="<?= e($ckey) ?>"><?= e($clabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div id="wp_thumbs" class="photo-thumbs"></div>
    <p class="hint" id="wp_count"></p>
    <p class="hint">Photos are resized to a max of 1600px. The "Take photo" button opens your camera on a phone. Tap ✕ on a thumbnail to remove it before uploading.</p>
    <button class="btn" type="submit">Upload selected photos</button>
  </form>
  <script>
  // Selected-photo thumbnails with ✕ to drop them before upload
  // (same pattern as the client intake form).
  (function () {
    var cam = document.getElementById('wp_cam');
    var lib = document.getElementById('wp_lib');
    var thumbs = document.getElementById('wp_thumbs');
    var count = document.getElementById('wp_count');
    if (!cam || !lib || !thumbs || !window.DataTransfer) return;
    function entries() {
      var out = [];
      [cam, lib].forEach(function (input) {
        Array.prototype.forEach.call(input.files, function (f) {
          out.push({ input: input, file: f });
        });
      });
      return out;
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
        x.textContent = '×';
        x.setAttribute('aria-label', 'Remove this photo');
        x.addEventListener('click', function () {
          URL.revokeObjectURL(img.src);
          var dt = new DataTransfer();
          Array.prototype.forEach.call(en.input.files, function (f) {
            if (f !== en.file) dt.items.add(f);
          });
          en.input.files = dt.files;
          render();
        });
        wrap.appendChild(img);
        wrap.appendChild(x);
        thumbs.appendChild(wrap);
      });
      var t = entries().length;
      count.textContent = t ? (t + (t === 1 ? ' photo' : ' photos') + ' selected') : '';
    }
    cam.addEventListener('change', render);
    lib.addEventListener('change', render);
  })();
  </script>
</div>

<div class="tabs">
  <?= tab_link($job_id, 'estimate', 'Step 1', 'Estimate', $tabs_allowed['estimate'], $tab === 'estimate') ?>
  <?= tab_link($job_id, 'authorization', 'Step 2', 'Authorization', $tabs_allowed['authorization'], $tab === 'authorization') ?>
  <?= tab_link($job_id, 'invoice', 'Step 3', 'Invoice', $tabs_allowed['invoice'], $tab === 'invoice') ?>
</div>

<?php /* ================= ESTIMATE TAB ================= */ ?>
<?php if ($tab === 'estimate'): ?>
<div class="card">
  <h2>Estimate <?= $snap_est ? '<span class="hint">· finalized ' . e(fmt_date($snap_est['finalized_at'])) . '</span>' : '' ?></h2>
  <?php if ($snap_est): ?>
    <div class="finalized-note">✅ This estimate is finalized and locked.
      <a href="pdf.php?job=<?= $job_id ?>&stage=estimate">Download PDF</a></div>
  <?php endif; ?>

  <?php lines_table($lines, $tab, $est_editable, 'est', true); ?>

  <?php if ($est_editable): ?>
    <?php add_line_form($job_id, $tab); ?>
  <?php elseif ($status === 'estimate'): ?>
    <p class="hint">Estimate is finalized — lines are locked. Convert to Authorization to continue.</p>
  <?php else: ?>
    <p class="hint">Estimate stage is closed — this job is now <?= e($status) ?>.</p>
  <?php endif; ?>

  <div class="actions">
    <?php if ($est_editable && count($lines) > 0): ?>
      <form method="post" action="job.php?id=<?= $job_id ?>&tab=estimate" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="finalize_estimate">
        <button class="btn navy" type="submit" onclick="return confirm('Finalize this estimate? Lines will be locked.')">Finalize Estimate</button>
      </form>
    <?php endif; ?>
    <?php if ($status === 'estimate'): ?>
      <form method="post" action="job.php?id=<?= $job_id ?>&tab=estimate" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="to_authorization">
        <button class="btn green" type="submit">Convert to Authorization →</button>
      </form>
    <?php endif; ?>
    <?php if ($snap_est): ?>
      <a class="btn ghost" href="pdf.php?job=<?= $job_id ?>&stage=estimate">Download Estimate PDF</a>
    <?php endif; ?>
    <?php if ($snap_est && $status === 'estimate'): ?>
      <form method="post" action="job.php?id=<?= $job_id ?>&tab=estimate" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reopen_estimate">
        <button class="btn ghost" type="submit" onclick="return confirm('Reopen this estimate? It will become editable again. The client link will show the new version once you finalize it again.')">Reopen estimate</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($snap_est): ?>
    <?= share_link_box($job_id, 'estimate', 'Estimate', 'to view the estimate') ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ================= AUTHORIZATION TAB ================= */ ?>
<?php if ($tab === 'authorization'): ?>
<div class="card">
  <h2>Repair Authorization <?= $snap_auth ? '<span class="hint">· finalized ' . e(fmt_date($snap_auth['finalized_at'])) . '</span>' : '' ?></h2>
  <p class="hint">All estimate lines carry over automatically. You may add more services below before the customer signs.</p>

  <?php if ($snap_auth && $sig_auth): ?>
    <div class="finalized-note">✅ Signed by <strong><?= e($sig_auth['signer_name']) ?></strong>
      on <?= e(fmt_date($sig_auth['signed_at'])) ?>.
      <a href="pdf.php?job=<?= $job_id ?>&stage=authorization">Download PDF</a></div>
    <p><img class="sig-preview" src="file.php?sig=<?= (int)$sig_auth['id'] ?>" alt="Customer signature"></p>
  <?php endif; ?>

  <?php lines_table($lines, $tab, $auth_addable, 'auth', true); ?>

  <?php if ($auth_addable): ?>
    <?php add_line_form($job_id, $tab); ?>
  <?php endif; ?>

  <?php if ($auth_signable): ?>
    <?php if ($biz['warranty'] !== ''): ?>
      <h3>Warranty &amp; Policies</h3>
      <div class="warranty-box"><?= e($biz['warranty']) ?></div>
    <?php endif; ?>
    <h3>Customer signature</h3>
    <form method="post" action="job.php?id=<?= $job_id ?>&tab=authorization">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sign_authorization">
      <label for="signer_name">Printed name *</label>
      <input type="text" id="signer_name" name="signer_name" required autocomplete="off"
             placeholder="Customer full name">
      <div style="height:10px"></div>
      <div class="sigbox">
        <div class="siglabel">✍️ Sign here (finger or stylus)</div>
        <canvas id="sigpad"></canvas>
        <p class="sig-hint">By writing my name and placing my signature, I accept the terms and conditions presented in this document and the services and prices provided.</p>
        <div class="actions">
          <button class="btn ghost small" id="sigclear">Clear</button>
        </div>
      </div>
      <input type="hidden" name="sigdata" id="sigdata">
      <div class="actions">
        <button class="btn green big" type="submit">Sign &amp; Authorize Service(s)</button>
      </div>
    </form>
  <?php endif; ?>

  <div class="actions">
    <?php if ($status === 'authorized' && $snap_auth): ?>
      <form method="post" action="job.php?id=<?= $job_id ?>&tab=authorization" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="to_invoice">
        <button class="btn green" type="submit">Convert to Invoice →</button>
      </form>
    <?php endif; ?>
    <?php if ($snap_auth): ?>
      <a class="btn ghost" href="pdf.php?job=<?= $job_id ?>&stage=authorization">Download Authorization PDF</a>
    <?php endif; ?>
  </div>

  <?= share_link_box($job_id, 'authorization', 'Authorization', 'to review and sign remotely') ?>
</div>
<?php endif; ?>

<?php /* ================= INVOICE TAB ================= */ ?>
<?php if ($tab === 'invoice'): ?>
<div class="card">
  <h2>Final Invoice <?= $snap_inv ? '<span class="hint">· finalized ' . e(fmt_date($snap_inv['finalized_at'])) . '</span>' : '' ?></h2>

  <?php if ($is_direct_invoice): ?>
    <p class="hint">⚡ Direct invoice — created without estimate or authorization.</p>
  <?php endif; ?>

  <?php if ($snap_inv): ?>
    <div class="finalized-note">✅ Finalized <?= e(fmt_date($snap_inv['finalized_at'])) ?> — this document is locked.
      <a href="pdf.php?job=<?= $job_id ?>&stage=invoice">Download PDF</a></div>
  <?php endif; ?>

  <?php if ($inv_editable): ?>
    <p class="hint">You can add, edit or delete lines and change prices before finalizing the invoice.</p>
  <?php endif; ?>

  <?php lines_table($lines, $tab, $inv_editable, 'inv', true); ?>

  <?php if ($inv_editable): ?>
    <?php add_line_form($job_id, $tab); ?>
  <?php endif; ?>

  <?php if ($biz['warranty'] !== ''): ?>
    <h3>Warranty &amp; Policies</h3>
    <div class="warranty-box"><?= e($biz['warranty']) ?></div>
  <?php endif; ?>

  <h3>Payment</h3>
  <p class="hint">How the client paid (or will pay). The method and notes appear on the invoice your client sees; the receipt photo is private — only you can see it.</p>
  <form method="post" action="job.php?id=<?= $job_id ?>&tab=invoice" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_payment">
    <div class="form-row">
      <div>
        <label>Method</label>
        <select name="payment_method">
          <option value="">— Select —</option>
          <?php foreach (PAYMENT_METHODS as $pkey => $plabel): ?>
            <option value="<?= e($pkey) ?>" <?= $pkey === $pay['method'] ? 'selected' : '' ?>><?= e($plabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Notes</label>
        <input type="text" name="payment_notes" maxlength="255" placeholder="Check #, last 4 digits…" value="<?= e($pay['notes']) ?>">
      </div>
    </div>
    <?php if ($pay['has_photo']): ?>
      <p><a href="photo.php?payphoto=<?= $job_id ?>" target="_blank"><img src="photo.php?payphoto=<?= $job_id ?>" alt="Receipt photo" style="max-width:230px;border-radius:8px;border:1px solid #d5dde5;display:block"></a></p>
    <?php endif; ?>
    <div class="photo-upload-row">
      <label class="btn small">📷 Take photo
        <input type="file" name="payment_photo" accept="image/*" capture="environment" hidden>
      </label>
      <label class="btn small secondary">🖼 Choose existing
        <input type="file" name="payment_photo" accept="image/*" hidden>
      </label>
      <?php if ($pay['has_photo']): ?>
        <button class="btn small danger" type="submit" form="delpayphoto" onclick="return confirm('Remove the receipt photo?');">Remove photo</button>
      <?php endif; ?>
    </div>
    <div class="actions"><button class="btn" type="submit">Save payment info</button></div>
  </form>
  <?php if ($pay['has_photo']): ?>
  <form method="post" action="job.php?id=<?= $job_id ?>&tab=invoice" id="delpayphoto" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_payment_photo">
  </form>
  <?php endif; ?>

  <h3>Specialized Diagnostic Results</h3>
  <p class="hint">What the diagnosis found (optional). It only appears on the invoice your client sees if you write something here. Do not include a title — the page already prints "Specialized Diagnostic Results" as a centered heading.</p>
  <form method="post" action="job.php?id=<?= $job_id ?>&tab=invoice">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_diagnosis">
    <div class="form-row">
      <div style="flex:1">
        <label>Findings</label>
        <textarea name="diagnosis_result" rows="4" maxlength="2000" placeholder="e.g. No communication with the PCM — corroded CAN wiring at the front star connector. Repaired and verified."><?= e($diagnosis) ?></textarea>
      </div>
    </div>
    <div class="actions"><button class="btn" type="submit">Save diagnostic results</button></div>
  </form>

  <?php if ($inv_finalizable): ?>
    <h3>Finalize invoice</h3>
    <p class="hint">No customer signature needed — the invoice is just a copy of the work done.
      Finalizing locks the document so it cannot be changed.</p>
    <form method="post" action="job.php?id=<?= $job_id ?>&tab=invoice">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="finalize_invoice">
      <div class="actions">
        <button class="btn green big" type="submit">Finalize Invoice ✓</button>
      </div>
    </form>
  <?php endif; ?>

  <div class="actions">
    <?php if ($status === 'invoiced' && $snap_inv): ?>
      <form method="post" action="job.php?id=<?= $job_id ?>&tab=invoice" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_paid">
        <button class="btn navy" type="submit">Mark as Paid ✓</button>
      </form>
      <a class="btn ghost" href="pdf.php?job=<?= $job_id ?>&stage=invoice">Download Invoice PDF</a>
    <?php endif; ?>
    <?php if ($status === 'paid'): ?>
      <a class="btn ghost" href="pdf.php?job=<?= $job_id ?>&stage=invoice">Download Invoice PDF</a>
    <?php endif; ?>
  </div>

  <?php if ($tabs_allowed['invoice']): ?>
    <?= share_link_box($job_id, 'invoice', 'Invoice', 'for your client to view and download') ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Copy client-link buttons (event delegation)
document.addEventListener('click', function (ev) {
  var btn = ev.target.closest ? ev.target.closest('[data-copy]') : null;
  if (!btn) return;
  var input = document.getElementById(btn.getAttribute('data-copy'));
  if (!input) return;
  function done() {
    btn.textContent = 'Copied ✓';
    setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
  }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(input.value).then(done, function () {
      input.select();
      try { document.execCommand('copy'); } catch (e) {}
      done();
    });
  } else {
    input.select();
    try { document.execCommand('copy'); } catch (e) {}
    done();
  }
});
</script>

<?php page_footer(); ?>

<?php
/* ---------- Reusable partials (kept in this file) ---------- */

function lines_table(array $lines, string $tab, bool $editable, string $prefix, bool $row_forms = false): void
{
    $job_id = (int)$_GET['id'];
    $biz = business_info();
    $tax_rate = (float)$biz['tax_rate'];
    ?>
    <div class="table-wrap">
    <table class="data wide" data-lines
           data-total-target="#total-<?= $prefix ?>"
           data-subtotal-target="#subtotal-<?= $prefix ?>"
           data-tax-target="#tax-<?= $prefix ?>"
           data-tax-rate="<?= e((string)$tax_rate) ?>">
      <thead>
        <tr>
          <th style="width:34px">#</th><th>Description</th><th>Kind</th>
          <th class="num">Qty</th><th class="num">Unit price</th><th class="num">Total</th>
          <?php if ($editable): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$lines): ?>
        <tr><td colspan="<?= $editable ? 7 : 6 ?>" style="text-align:center;color:var(--muted)">No lines yet.</td></tr>
      <?php endif; ?>
      <?php $n = 0; ?>
      <?php foreach ($lines as $l): $n++; ?>
        <?php if ($row_forms && $editable): ?>
        <tr data-line>
          <td><?= $n ?></td>
          <td>
            <form method="post" action="job.php?id=<?= $job_id ?>&tab=<?= $tab ?>" style="display:flex;gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_line">
              <input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
              <input type="text" name="description" class="line-input" value="<?= e($l['description']) ?>" required style="flex:1">
          </td>
          <td><?= e(ucfirst($l['kind'])) ?></td>
          <td class="num"><input type="number" name="qty" class="line-input" data-qty value="<?= e((string)$l['qty']) ?>" step="0.01" min="0.01" style="width:80px"></td>
          <td class="num"><input type="number" name="unit_price" class="line-input" data-price value="<?= e((string)$l['unit_price']) ?>" step="0.01" min="0" style="width:100px"></td>
          <td class="num" data-row-total><?= money((float)$l['qty'] * (float)$l['unit_price']) ?></td>
          <td style="white-space:nowrap">
              <button class="btn small navy" type="submit">Save</button>
            </form>
            <form method="post" action="job.php?id=<?= $job_id ?>&tab=<?= $tab ?>" style="display:inline"
                  onsubmit="return confirm('Delete this line?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_line">
              <input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
              <button class="btn small danger" type="submit">✕</button>
            </form>
          </td>
        </tr>
        <?php else: ?>
        <tr data-line data-qty-val="<?= e((string)$l['qty']) ?>" data-price-val="<?= e((string)$l['unit_price']) ?>">
          <td><?= $n ?></td>
          <td><?= e($l['description']) ?><br><span class="hint">added in <?= e($l['stage_added']) ?></span></td>
          <td><?= e(ucfirst($l['kind'])) ?></td>
          <td class="num"><?= e((string)$l['qty']) ?></td>
          <td class="num"><?= money($l['unit_price']) ?></td>
          <td class="num" data-row-total><?= money((float)$l['qty'] * (float)$l['unit_price']) ?></td>
          <?php if ($editable): ?>
          <td>
            <form method="post" action="job.php?id=<?= $job_id ?>&tab=<?= $tab ?>" style="display:inline"
                  onsubmit="return confirm('Delete this line?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_line">
              <input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
              <button class="btn small danger" type="submit">✕</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php totals_block($prefix, $tax_rate); ?>
    <?php
}

function totals_block(string $prefix, float $tax_rate): void
{
    // Values are updated live by JS (data-lines table above).
    ?>
    <div style="display:flex;justify-content:flex-end;margin-top:10px">
      <div class="totals">
        <div class="trow"><span>Subtotal</span><span id="subtotal-<?= $prefix ?>">$0.00</span></div>
        <div class="trow"><span>Tax (<?= e(rtrim(rtrim((string)$tax_rate, '0'), '.')) ?>%)</span><span id="tax-<?= $prefix ?>">$0.00</span></div>
        <div class="trow grand"><span>Total</span><span id="total-<?= $prefix ?>">$0.00</span></div>
      </div>
    </div>
    <?php
}

function add_line_form(int $job_id, string $tab): void
{
    ?>
    <h3>Add line</h3>
    <form method="post" action="job.php?id=<?= $job_id ?>&tab=<?= $tab ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_line">
      <label for="desc-<?= $tab ?>">Description *</label>
      <input type="text" id="desc-<?= $tab ?>" name="description" required autocomplete="off"
             placeholder="e.g. ECM programming / module cloning">
      <div class="form-row cols3">
        <div>
          <label for="kind-<?= $tab ?>">Kind</label>
          <select id="kind-<?= $tab ?>" name="kind">
            <option value="service">Service</option>
            <option value="part">Part</option>
          </select>
        </div>
        <div><label for="qty-<?= $tab ?>">Qty</label><input type="number" id="qty-<?= $tab ?>" name="qty" value="1" step="0.01" min="0.01"></div>
        <div><label for="price-<?= $tab ?>">Unit price (USD)</label><input type="number" id="price-<?= $tab ?>" name="unit_price" value="0" step="0.01" min="0"></div>
      </div>
      <div class="actions"><button class="btn" type="submit">Add line</button></div>
    </form>
    <?php
}
