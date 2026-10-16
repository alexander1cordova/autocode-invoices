<?php
// ============================================================
// AutoCode Invoices — configuration template
// Copy this file to config.php and fill in your real values.
// NEVER commit config.php to version control.
// ============================================================

// Direct access guard
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

// --- Database (MySQL) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

// --- Export API token (64 hex chars) ---
// Generate one with:  openssl rand -hex 32
// The backup job calls: export.php?token=YOUR_TOKEN
define('EXPORT_TOKEN', 'REPLACE_WITH_64_HEX_CHARS');

// --- App ---
define('APP_TIMEZONE', 'America/Los_Angeles');
// Public base URL of the app, no trailing slash. Used for absolute links in PDFs.
define('APP_URL', 'https://invoices.autocodelv.com');
