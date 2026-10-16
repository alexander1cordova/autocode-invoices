<?php
// ============================================================
// AutoCode Invoices — backup export endpoint.
// Protected by a long random token (EXPORT_TOKEN in config.php).
// Call:  api/export.php?token=YOUR_64_CHAR_TOKEN
// Returns a full JSON dump of the database for automatic
// backups (e.g. a cron job that uploads it to Google Drive).
// ============================================================

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$token = (string)($_GET['token'] ?? '');
$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (stripos($authHeader, 'Bearer ') === 0) {
    $token = substr($authHeader, 7);
}

$expected = defined('EXPORT_TOKEN') ? (string)EXPORT_TOKEN : '';
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$tables = ['users', 'settings', 'clients', 'vehicles', 'jobs', 'job_lines', 'job_snapshots', 'signatures', 'job_photos', 'photo_links'];
$dump = ['ok' => true, 'exported_at' => date('c'), 'tables' => []];

foreach ($tables as $t) {
    // Table names are hardcoded above — safe to interpolate.
    $rows = db()->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $dump['tables'][$t] = $rows;
    $dump['counts'][$t] = count($rows);
}

echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
