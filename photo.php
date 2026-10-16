<?php
// AutoCode Invoices — serve one job photo, or a client's ID image.
// Job photos: logged-in admin, a valid photo-link token for that job,
// a valid document share-link token for that job (r.php pages show photos
// inline), or the master EXPORT_TOKEN used by the Drive backup script.
// ID images (?idimage=<client_id>): admin or EXPORT_TOKEN only —
// never through a client-facing link.
require_once __DIR__ . '/includes/functions.php';

$token = get_param('token');
$is_export_token = $token !== '' && defined('EXPORT_TOKEN') && hash_equals((string)EXPORT_TOKEN, $token);

/* ---------- Client ID image ---------- */
$idimage_cid = (int)get_param('idimage');
if ($idimage_cid > 0) {
    if (!current_user() && !$is_export_token) {
        http_response_code(404);
        exit('Photo not found.');
    }
    clients_id_image_migrate();
    $client = get_client($idimage_cid);
    $rel = $client ? (string)($client['id_image'] ?? '') : '';
    $disk = ($rel !== '') ? __DIR__ . '/' . $rel : '';
    if ($disk === '' || !is_file($disk)) {
        http_response_code(404);
        exit('Photo not found.');
    }
    $fname = 'autocode-id-' . $idimage_cid . '.jpg';
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($disk));
    header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: ' . (get_param('download') === '1' ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
    readfile($disk);
    exit;
}

/* ---------- Job payment receipt photo (private) ---------- */
$payphoto_jid = (int)get_param('payphoto');
if ($payphoto_jid > 0) {
    if (!current_user() && !$is_export_token) {
        http_response_code(404);
        exit('Photo not found.');
    }
    jobs_payment_migrate();
    $st = db()->prepare('SELECT payment_photo FROM jobs WHERE id = ?');
    $st->execute([$payphoto_jid]);
    $rel = (string)($st->fetchColumn() ?? '');
    $disk = ($rel !== '') ? __DIR__ . '/' . $rel : '';
    if ($disk === '' || !is_file($disk)) {
        http_response_code(404);
        exit('Photo not found.');
    }
    $fname = 'autocode-payment-' . $payphoto_jid . '.jpg';
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($disk));
    header('Cache-Control: private, max-age=3600');
    header('Content-Disposition: ' . (get_param('download') === '1' ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
    readfile($disk);
    exit;
}

/* ---------- Job photo ---------- */
$photo_id = (int)get_param('id');

$photo = $photo_id ? get_job_photo($photo_id) : null;
if (!$photo) {
    http_response_code(404);
    exit('Photo not found.');
}
$job_id = (int)$photo['job_id'];

$allowed = false;
if (current_user()) {
    $allowed = true; // admin
} elseif ($is_export_token) {
    $allowed = true; // backup script
} elseif ($token !== '') {
    $link = find_photo_link($token);
    if ($link && (int)$link['job_id'] === $job_id) {
        $allowed = true; // client with a photo link
    } else {
        $share = find_share_link($token);
        if ($share && (int)$share['job_id'] === $job_id) {
            $allowed = true; // client with a document link (estimate/authorization/invoice)
        }
    }
}
if (!$allowed) {
    http_response_code(404);
    exit('Photo not found.');
}

$disk = __DIR__ . '/' . $photo['file_path'];
if (!is_file($disk)) {
    http_response_code(404);
    exit('Photo not found.');
}

$mime = (string)($photo['mime'] ?: 'image/jpeg');
if (!preg_match('#^image/[a-z0-9.+-]+$#i', $mime)) {
    $mime = 'image/jpeg';
}
$download = get_param('download') === '1';
$fname = 'autocode-photo-' . $photo_id . '.jpg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($disk));
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
readfile($disk);
