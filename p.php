<?php
// ============================================================
// AutoCode Invoices — public photo gallery (no login).
// Opened via a private photo link: p.php?token=...
// The token grants view+download of ONE job's work photos.
// No delete option here; only the admin can manage photos.
// Photo links never expire.
// Everything on this page is in English (client-facing).
// ============================================================
require_once __DIR__ . '/includes/functions.php';

$token = get_param('token');
$link = find_photo_link($token);
if (!$link) {
    http_response_code(404);
    p_error_page('This link is invalid or has expired.', 'Please contact AutoCode at (702) 608-0202 for a new link.');
}
$job_id = (int)$link['job_id'];

$job = get_job($job_id);
if (!$job) {
    http_response_code(404);
    p_error_page('Photos not found.', 'Please contact AutoCode at (702) 608-0202.');
}

$client = get_client((int)$job['client_id']);
$vehicle = get_vehicle((int)$job['vehicle_id']);
$photos = get_job_photos($job_id);

p_shell('Work photos', function () use ($token, $job, $client, $vehicle, $photos) {
    ?>
    <div class="card">
      <h2 class="doc-title">Work photos</h2>
      <p class="doc-meta">
        <?= e(vehicle_label($vehicle)) ?> · <?= e(fmt_date($job['created_at'])) ?>
      </p>
      <?php if (!$photos): ?>
        <p>No photos have been added yet. Please check back later.</p>
      <?php else: ?>
        <p class="phint">Tap a photo to view it full size. You can download any photo, but photos cannot be deleted from this page.</p>
        <?php foreach (group_photos_by_category($photos) as $gkey => $gphotos): ?>
          <?php if (!$gphotos) continue; ?>
          <div class="phcat-bar"><?= e(photo_category_label($gkey)) ?> <span>· <?= count($gphotos) ?></span></div>
          <div class="photo-grid">
            <?php foreach ($gphotos as $ph): ?>
            <div class="photo-item">
              <a href="photo.php?token=<?= e($token) ?>&id=<?= (int)$ph['id'] ?>" target="_blank"><img src="photo.php?token=<?= e($token) ?>&id=<?= (int)$ph['id'] ?>" alt="<?= e($ph['caption'] ?: 'Work photo') ?>" loading="lazy"></a>
              <?php if ($ph['caption'] !== ''): ?>
                <p class="pcap"><?= e($ph['caption']) ?></p>
              <?php endif; ?>
              <a class="btn small" href="photo.php?token=<?= e($token) ?>&id=<?= (int)$ph['id'] ?>&download=1">Download</a>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
});

/* ================================================================
   Rendering helpers (all client-facing text in English)
   ================================================================ */

function p_error_page(string $title, string $msg): void
{
    p_shell('AutoCode', function () use ($title, $msg) {
        echo '<div class="card center"><h2>' . e($title) . '</h2><p>' . e($msg) . '</p></div>';
    });
    exit;
}

function p_shell(string $title, callable $body): void
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
.rwrap{max-width:720px;margin:0 auto;padding:16px}
.card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.07);margin-bottom:14px}
.center{text-align:center}
.doc-title{color:#004070;margin:0 0 2px;font-size:20px}
.doc-meta{color:#69737d;font-size:13px;margin:0 0 12px}
.phint{font-size:13px;color:#44515f;background:#f2f7fc;border-radius:8px;padding:8px 10px}
.pcap{font-size:13px;color:#33404e;margin:8px 0}
.rfoot{text-align:center;color:#69737d;font-size:12px;padding:18px}
.photo-item .btn.small{display:block;text-align:center;margin-top:8px;text-decoration:none}
</style>
</head>
<body>
<div class="rband"><h1>AutoCode</h1><p>4240 E Lake Mead Blvd, Suite 110, Las Vegas, NV 89115 · (702) 608-0202</p></div>
<div class="rwrap">
<?php $body(); ?>
<div class="rfoot">Private photo link · AutoCode · (702) 608-0202</div>
</div>
</body>
</html>
    <?php
}
