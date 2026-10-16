<?php
// ============================================================
// AutoCode Invoices — public client page (no login).
// Opened via a private share link: r.php?token=...
// The token grants access to exactly ONE document (job + stage):
//   estimate      → view the finalized estimate (+ download PDF)
//   authorization → review + sign remotely (+ download PDF)
//   invoice       → view the finalized invoice (+ download PDF) — no signature needed, it's a copy
// Everything on this page is in English (client-facing).
// ============================================================
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/pdfgen.php';

$token = get_param('token');
$link = find_share_link($token);
if (!$link) {
    http_response_code(404);
    r_error_page('This link is invalid or has expired.', 'Please contact AutoCode at (702) 608-0202 for a new link.');
}
$job_id = (int)$link['job_id'];
$stage = (string)$link['stage'];

$job = get_job($job_id);
if (!$job) {
    http_response_code(404);
    r_error_page('Document not found.', 'Please contact AutoCode at (702) 608-0202.');
}
$status = (string)$job['status'];

$snap_est  = get_snapshot($job_id, 'estimate');
$snap_auth = get_snapshot($job_id, 'authorization');
$snap_inv  = get_snapshot($job_id, 'invoice');

/* ---------- PDF download (token-authorized, finalized docs only) ---------- */
if (get_param('pdf') === '1') {
    $snap_row = ['estimate' => $snap_est, 'authorization' => $snap_auth, 'invoice' => $snap_inv][$stage] ?? null;
    if (!$snap_row) {
        http_response_code(404);
        r_error_page('PDF not available yet.', 'This document has not been finalized — please contact AutoCode at (702) 608-0202.');
    }
    $data = json_decode((string)$snap_row['snapshot_json'], true);
    if (!is_array($data)) {
        http_response_code(500);
        r_error_page('Could not read this document.', 'Please contact AutoCode at (702) 608-0202.');
    }
    if (in_array($stage, ['estimate', 'invoice'], true)) {
        // Client/vehicle/business info is always live; lines stay as finalized.
        $data = apply_live_profile($data, $job_id);
    }
    try {
        $pdf = job_pdf($data);
    } catch (Throwable $e) {
        error_log('Share PDF failed for job ' . $job_id . ' stage ' . $stage . ': ' . $e->getMessage());
        http_response_code(500);
        r_error_page('Could not generate the PDF.', 'Please contact AutoCode at (702) 608-0202.');
    }
    $filename = sprintf('autocode-%s-job%d.pdf', $stage, $job_id);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

/* ---------- Remote signing (authorization only — invoice needs no signature) ---------- */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stage === 'authorization') {
    try {
        if ($status !== 'authorized' || $snap_auth) {
            throw new RuntimeException('This document cannot be signed now.');
        }
        if (!job_lines($job_id)) {
            throw new RuntimeException('There is nothing to sign yet.');
        }
        $name = trim((string)($_POST['signer_name'] ?? ''));
        $sig = (string)($_POST['sigdata'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Please write your full name.');
        }
        if ($sig === '') {
            throw new RuntimeException('Please sign before continuing.');
        }
        store_signature($job_id, $stage, $name, $sig);
        save_snapshot($job_id, $stage);
        // Notify Migue: the client signed the repair authorization.
        $nclient = get_client((int)$job['client_id']);
        $nlines  = job_lines($job_id);
        $nbiz    = business_info();
        $ntotals = job_totals($nlines, (float)$nbiz['tax_rate']);
        notify_owner(
            'Autorizacion firmada: ' . ($nclient ? client_name($nclient) : 'cliente'),
            "El cliente firmo la autorizacion de reparacion.\n\n" .
            'Firmado por: ' . $name . "\n" .
            'Cliente: ' . ($nclient ? client_name($nclient) : '—') . "\n" .
            'Vehiculo: ' . vehicle_label(get_vehicle((int)$job['vehicle_id'])) . "\n" .
            'Total: ' . money($ntotals['total'] ?? 0) . "\n\n" .
            'Ver el trabajo: ' . app_base_url() . '/job.php?id=' . $job_id
        );
        header('Location: r.php?token=' . urlencode($token));
        exit;
    } catch (Throwable $t) {
        $err = $t->getMessage();
    }
    // refresh snapshot after the attempt
    $snap_auth = get_snapshot($job_id, 'authorization');
}

/* ---------- Decide what to show ---------- */
$mode = 'notready';   // notready | sign | thanks | view
$doc = null;          // snapshot-shaped array to render
$notready_msg = '';

if ($stage === 'estimate') {
    if ($snap_est) {
        $doc = json_decode((string)$snap_est['snapshot_json'], true);
        $mode = 'view';
    } else {
        $notready_msg = 'This estimate is not ready yet.';
    }
} elseif ($stage === 'authorization') {
    if ($snap_auth) {
        $doc = json_decode((string)$snap_auth['snapshot_json'], true);
        $mode = 'thanks';
    } elseif ($status === 'authorized' && count(job_lines($job_id)) > 0) {
        $doc = build_snapshot_data($job_id, 'authorization');
        $mode = 'sign';
    } else {
        $notready_msg = 'This authorization is not ready for signature yet.';
    }
} elseif ($stage === 'invoice') {
    if ($snap_inv) {
        $doc = json_decode((string)$snap_inv['snapshot_json'], true);
        $mode = 'thanks';
    } else {
        $notready_msg = 'This invoice is not ready yet. Please contact AutoCode at (702) 608-0202.';
    }
}
if (!is_array($doc)) {
    $doc = null;
    if ($mode === 'view' || $mode === 'thanks') {
        $mode = 'notready';
        $notready_msg = 'Could not read this document.';
    }
}

// Estimate/invoice links are live: profile edits show immediately.
// (A signed authorization always renders exactly as signed.)
if ($doc && in_array($stage, ['estimate', 'invoice'], true)) {
    $doc = apply_live_profile($doc, $job_id);
}
// Payment info is live too: the method/notes the admin records appear on
// the invoice even if set after finalizing. The receipt photo is private.
if ($doc && $stage === 'invoice') {
    $pay = job_payment($job_id);
    $doc['payment'] = [
        'method' => payment_method_label($pay['method']),
        'notes'  => $pay['notes'],
    ];
    // Diagnosis result is live too: it only renders if the admin wrote something.
    $doc['diagnosis'] = job_diagnosis($job_id);
    // Warranty & Policies are live on the invoice as well: the current text
    // from Settings always shows, even on invoices finalized before it was
    // written. (A signed authorization keeps rendering exactly as signed.)
    $doc['warranty'] = business_info()['warranty'];
}

r_page($token, $stage, $mode, $doc, $err, $notready_msg, $job_id);

/* ================================================================
   Rendering helpers (all client-facing text in English)
   ================================================================ */

function r_error_page(string $title, string $msg): void
{
    r_shell('AutoCode', function () use ($title, $msg) {
        echo '<div class="card center"><h2>' . e($title) . '</h2><p>' . e($msg) . '</p></div>';
    });
    exit;
}

function r_shell(string $title, callable $body): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — AutoCode</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261007">
<style>
.rband{background:#004070;color:#fff;padding:18px 16px;text-align:center}
.rband h1{margin:0;font-size:22px;letter-spacing:.5px;color:#fff}
.rband p{margin:4px 0 0;font-size:13px;opacity:.9}
.rwrap{max-width:640px;margin:0 auto;padding:16px}
.card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.07);margin-bottom:14px}
.center{text-align:center}
.doc-title{color:#004070;margin:0 0 2px;font-size:20px}
.doc-meta{color:#69737d;font-size:13px;margin:0 0 12px}
.kv{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
.kv .box{background:#f2f7fc;border-radius:8px;padding:10px 12px;font-size:14px}
.kv .box b{display:block;font-size:12px;color:#004070;text-transform:uppercase;margin-bottom:4px}
table.lines{width:100%;border-collapse:collapse;font-size:14px;margin:6px 0 10px}
table.lines th{background:#004070;color:#fff;text-align:left;padding:8px;font-size:12px}
table.lines td{border-bottom:1px solid #e6ecf2;padding:8px;vertical-align:top}
table.lines td.n,table.lines th.n{text-align:right}
.totals{text-align:right;font-size:15px}
.totals .grand{font-size:19px;font-weight:700;color:#004070}
.warranty-box{background:#fff8e6;border:1px solid #f0d98a;border-radius:8px;padding:10px 12px;font-size:13px;white-space:pre-wrap;margin:10px 0}
.sig-img{max-width:260px;border-bottom:1px solid #999;padding-bottom:4px}
.thanks{font-size:17px}
.bigcheck{font-size:44px}
.btn.big{font-size:17px;padding:14px 22px}
.err{background:#fdecec;color:#b3261e;border-radius:8px;padding:10px 12px;font-size:14px;margin-bottom:12px}
.rfoot{text-align:center;color:#69737d;font-size:12px;padding:18px}
input[type=text]{width:100%;padding:12px;border:1px solid #ccd6e0;border-radius:8px;font-size:16px;box-sizing:border-box}
label.flabel{display:block;font-weight:600;margin:10px 0 4px}
.sig-hint{font-size:13px;color:#44515f;background:#f2f7fc;border-radius:8px;padding:8px 10px}
</style>
</head>
<body>
<div class="rband"><h1>AutoCode</h1><p>4240 E Lake Mead Blvd, Suite 110, Las Vegas, NV 89115 · (702) 608-0202</p></div>
<div class="rwrap">
<?php $body(); ?>
<div class="rfoot">Private document link · AutoCode · (702) 608-0202</div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
    <?php
}

function r_sig_data_url(?array $sig): string
{
    if (!$sig || empty($sig['image_path'])) {
        return '';
    }
    $base = realpath(__DIR__ . '/uploads/signatures');
    $p = realpath(__DIR__ . '/' . ltrim((string)$sig['image_path'], '/'));
    if (!$base || !$p || strpos($p, $base) !== 0) {
        return '';
    }
    $raw = @file_get_contents($p);
    return $raw ? 'data:image/png;base64,' . base64_encode($raw) : '';
}

function r_money($n): string
{
    return '$' . number_format((float)$n, 2, '.', ',');
}

/** Render the document body from a snapshot-shaped array. */
function r_doc_html(array $d): void
{
    $c = $d['client'] ?? [];
    $v = $d['vehicle'] ?? null;
    $tot = $d['totals'] ?? [];
    $is_invoice = ($d['stage'] ?? '') === 'invoice';
    ?>
  <div class="card">
    <h2 class="doc-title"><?= e(strtoupper((string)($d['title'] ?? 'Document'))) ?></h2>
    <?php if (!empty($d['finalized_at'])): ?>
    <p class="doc-meta"><?= e(date('M j, Y g:i A', strtotime((string)$d['finalized_at']))) ?></p>
    <?php endif; ?>
    <div class="kv">
      <div class="box"><b>Customer</b>
        <?= e((string)($c['name'] ?? '')) ?><br>
        <?= e((string)($c['phone'] ?? '')) ?><br>
        <?= e((string)($c['address'] ?? '')) ?>
      </div>
      <div class="box"><b>Vehicle</b>
        <?php if ($v): ?>
          <?= e(trim(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? ''))) ?><br>
          VIN: <?= e((string)($v['vin'] ?? '—')) ?><br>
          Plate: <?= e((string)($v['plate'] ?? '—')) ?>
        <?php else: ?>—<?php endif; ?>
      </div>
    </div>
    <p><b>Problem / requested work:</b><br><?= e((string)($d['job']['problem_title'] ?? '')) ?></p>
    <table class="lines">
      <tr><th>Description</th><th class="n">Qty</th><th class="n">Price</th><th class="n">Total</th></tr>
      <?php foreach (($d['lines'] ?? []) as $l): ?>
        <tr>
          <td><?= e((string)($l['description'] ?? '')) ?></td>
          <td class="n"><?= e(rtrim(rtrim(number_format((float)($l['qty'] ?? 0), 2, '.', ''), '0'), '.')) ?></td>
          <td class="n"><?= r_money($l['unit_price'] ?? 0) ?></td>
          <td class="n"><?= r_money($l['line_total'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <div class="totals">
      Subtotal: <?= r_money($tot['subtotal'] ?? 0) ?><br>
      <?php if (!empty($d['tax_rate'])): ?>Tax (<?= e((string)$d['tax_rate']) ?>%): <?= r_money($tot['tax'] ?? 0) ?><br><?php endif; ?>
      <span class="grand">Total: <?= r_money($tot['total'] ?? 0) ?></span>
    </div>
    <?php $warranty_text = clean_doc_text((string)($d["warranty"] ?? ""), "Warranty & Policies"); ?>
    <?php if (in_array(($d['stage'] ?? ''), ['authorization', 'invoice'], true) && $warranty_text !== ''): ?>
      <h3>Warranty &amp; Policies</h3>
      <div class="warranty-box"><?= e($warranty_text) ?></div>
    <?php endif; ?>
    <?php if (!empty($d['signature'])): ?>
      <h3><?= $is_invoice ? 'Customer acceptance' : 'Customer authorization' ?></h3>
      <p>Signed by <b><?= e((string)$d['signature']['signer_name']) ?></b>
        on <?= e(date('M j, Y g:i A', strtotime((string)$d['signature']['signed_at']))) ?></p>
      <?php $src = r_sig_data_url($d['signature']); ?>
      <?php if ($src !== ''): ?><p><img class="sig-img" src="<?= e($src) ?>" alt="Customer signature"></p><?php endif; ?>
    <?php endif; ?>
    <?php $pay = $d['payment'] ?? null; ?>
    <?php $diagnosis = clean_doc_text((string)($d["diagnosis"] ?? ""), "Specialized Diagnostic Results"); ?>
    <?php if ($is_invoice && $diagnosis !== ''): ?>
      <h3 style="text-align:center">Specialized Diagnostic Results</h3>
      <p style="text-align:justify"><?= nl2br(e($diagnosis)) ?></p>
    <?php endif; ?>
    <?php if ($is_invoice && $pay && (trim((string)($pay['method'] ?? '')) !== '' || trim((string)($pay['notes'] ?? '')) !== '')): ?>
      <h3>Payment</h3>
      <p>Method: <b><?= e((string)$pay['method']) ?></b><?php if (trim((string)($pay['notes'] ?? '')) !== ''): ?><br>Notes: <?= e((string)$pay['notes']) ?><?php endif; ?></p>
    <?php endif; ?>
  </div>
    <?php
}

/**
 * Work photos on the client page, grouped by category.
 * Rendered only when the job actually has photos; reads live from the DB
 * so photos added after the link was sent show up on the same link.
 * (All client-facing text in English.)
 */
function r_photos_html(int $job_id, string $token): void
{
    if ($job_id <= 0) {
        return;
    }
    $photos = get_job_photos($job_id);
    if (!$photos) {
        return;
    }
    $groups = group_photos_by_category($photos);
    ?>
  <div class="card">
    <h2 class="doc-title">Work Photos</h2>
    <p class="doc-meta">Photos of your vehicle during this job. Tap a photo to view it full size.</p>
    <?php foreach ($groups as $gkey => $gphotos): ?>
      <?php if (!$gphotos) continue; ?>
      <div class="phcat-bar"><?= e(photo_category_label($gkey)) ?> <span>· <?= count($gphotos) ?></span></div>
      <div class="photo-grid">
        <?php foreach ($gphotos as $ph):
            $url = 'photo.php?id=' . (int)$ph['id'] . '&token=' . urlencode($token);
        ?>
        <div class="photo-item">
          <a href="<?= e($url) ?>" target="_blank"><img src="<?= e($url) ?>" alt="<?= e($ph['caption'] ?: 'Work photo') ?>" loading="lazy"></a>
          <?php if (trim((string)$ph['caption']) !== ''): ?>
            <p class="pcap"><?= e($ph['caption']) ?></p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
    <?php
}

function r_page(string $token, string $stage, string $mode, ?array $doc, string $err, string $notready_msg, int $job_id = 0): void
{
    $titles = ['estimate' => 'Your Estimate', 'authorization' => 'Repair Authorization', 'invoice' => 'Your Invoice'];
    r_shell($titles[$stage] ?? 'Document', function () use ($token, $stage, $mode, $doc, $err, $notready_msg, $job_id) {
        if ($err !== '') {
            echo '<div class="err">' . e($err) . '</div>';
        }
        if ($mode === 'notready' || $doc === null) {
            echo '<div class="card center"><h2>Not available</h2><p>'
                . e($notready_msg !== '' ? $notready_msg : 'This document is not available yet.')
                . '</p><p>Please contact AutoCode at (702) 608-0202.</p></div>';
            return;
        }

        if ($mode === 'view') {
            // Estimate: view-only + download
            r_doc_html($doc);
            r_photos_html($job_id, $token);
            echo '<div class="card center"><p>Thank you for reviewing your estimate.</p>'
                . '<a class="btn big" href="r.php?token=' . e($token) . '&pdf=1">Download PDF</a></div>';
            return;
        }

        if ($mode === 'thanks') {
            // Already signed: thank-you + download
            r_doc_html($doc);
            r_photos_html($job_id, $token);
            if ($stage === 'authorization') {
                echo '<div class="card center thanks"><div class="bigcheck">✓</div>'
                    . '<h2>Thank you for your authorization!</h2>'
                    . '<p>Would you like to download a copy?</p>'
                    . '<a class="btn green big" href="r.php?token=' . e($token) . '&pdf=1">Download PDF</a></div>';
            } else {
                echo '<div class="card center thanks"><div class="bigcheck">✓</div>'
                    . '<h2>Thank you for doing business with us!</h2>'
                    . '<p>Would you like to download a copy of your invoice?</p>'
                    . '<a class="btn green big" href="r.php?token=' . e($token) . '&pdf=1">Download PDF</a></div>';
            }
            return;
        }

        // $mode === 'sign': only authorization reaches this — invoice needs no signature
        r_doc_html($doc);
        r_photos_html($job_id, $token);
        ?>
      <div class="card">
        <h2 class="doc-title">Authorize &amp; Sign</h2>
        <form method="post" action="r.php?token=<?= e($token) ?>">
          <label class="flabel" for="signer_name">Printed name *</label>
          <input type="text" id="signer_name" name="signer_name" required autocomplete="off" placeholder="Your full name">
          <div style="height:10px"></div>
          <div class="sigbox">
            <div class="siglabel">✍️ Sign here (finger or stylus)</div>
            <canvas id="sigpad"></canvas>
            <p class="sig-hint">By writing my name and placing my signature, I accept the terms and conditions presented in this document and the services and prices provided.</p>
            <div class="actions"><button class="btn ghost small" id="sigclear">Clear</button></div>
          </div>
          <input type="hidden" name="sigdata" id="sigdata">
          <div class="actions" style="margin-top:12px">
            <button class="btn green big" type="submit">Sign &amp; Authorize Service(s)</button>
          </div>
        </form>
      </div>
        <?php
    });
}
