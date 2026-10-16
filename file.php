<?php
// AutoCode Invoices — serve a signature image (login required).
// Signatures live under uploads/signatures/, blocked from direct HTTP.
require_once __DIR__ . '/includes/functions.php';
require_login();

$id = (int)get_param('sig');
$st = db()->prepare('SELECT image_path FROM signatures WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    exit('Not found.');
}

// Contain the path inside uploads/signatures (no traversal).
$base = realpath(__DIR__ . '/uploads/signatures');
$path = realpath(__DIR__ . '/' . (string)$row['image_path']);
if ($path === false || $base === false || strpos($path, $base) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
