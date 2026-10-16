<?php
// ============================================================
// AutoCode Invoices — shared bootstrap:
// session, DB (PDO), auth, CSRF, settings, jobs helpers.
// ============================================================

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

$config_file = __DIR__ . '/../config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    exit('Configuration missing: copy config.sample.php to config.php and fill in your values.');
}
require_once $config_file;

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Los_Angeles');

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => $secure,
        'cookie_samesite' => 'Lax',
    ]);
}

/* ---------------- Database ---------------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // The DB server runs on UTC; run this app on Las Vegas time so every
        // TIMESTAMP read/write matches what date()/strtotime()
        // (America/Los_Angeles) see on the PHP side.
        try {
            $pdo->exec("SET time_zone = 'America/Los_Angeles'");
        } catch (Throwable $t) {
            // Named zones need the server's tz tables; fall back to PHP's numeric offset.
            $pdo->exec(sprintf("SET time_zone = '%s'", date('P')));
        }
        tz_fix_20261007_migrate();
    }
    return $pdo;
}

/* ---------------- Auth ---------------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . urlencode($next));
        exit;
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'       => (int)$user['id'],
        'username' => (string)$user['username'],
    ];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    $have = (string)($_SESSION['csrf'] ?? '');
    if ($have === '' || !hash_equals($have, $sent)) {
        http_response_code(419);
        exit('Security token mismatch. Please go back and try again.');
    }
}

/* ---------------- Generic helpers ---------------- */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($n): string
{
    return '$' . number_format((float)$n, 2);
}

function fmt_date(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('M j, Y g:i A', $t) : '—';
}

function fmt_day(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('M j, Y', $t) : '—';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function post(string $k, string $default = ''): string
{
    return trim((string)($_POST[$k] ?? $default));
}

function get_param(string $k, string $default = ''): string
{
    return trim((string)($_GET[$k] ?? $default));
}

function set_flash(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

function set_flash_err(string $msg): void
{
    $_SESSION['flash_err'] = $msg;
}

/** Only allow relative .php redirects (prevents open-redirect). */
function safe_next(string $next): string
{
    if (preg_match('#^[a-zA-Z0-9_.\-/]+\.php(\?.*)?$#', $next) && strpos($next, '//') === false) {
        return $next;
    }
    return 'index.php';
}

/* ---------------- Settings / business info ---------------- */

function setting_get(string $key, string $default = ''): string
{
    try {
        $st = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $t) {
        return $default;
    }
}

function setting_set(string $key, string $value): void
{
    $st = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $st->execute([$key, $value]);
}

function business_info(): array
{
    return [
        'name'     => setting_get('business_name', 'AutoCode LLC'),
        'address'  => setting_get('business_address', '4240 E Lake Mead Blvd, Suite 110, Las Vegas, NV 89115'),
        'phone'    => setting_get('business_phone', '(702) 608-0202'),
        'warranty' => setting_get('warranty_policies', ''),
        'tax_rate' => (float)setting_get('tax_rate', '0'),
    ];
}

/* ---------------- Clients / vehicles ---------------- */

function get_client(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function client_name(array $c): string
{
    return trim($c['first_name'] . ' ' . $c['last_name']);
}

function get_vehicle(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM vehicles WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function client_vehicles(int $client_id): array
{
    $st = db()->prepare('SELECT * FROM vehicles WHERE client_id = ? ORDER BY id DESC');
    $st->execute([$client_id]);
    return $st->fetchAll();
}

function vehicle_label(?array $v): string
{
    if (!$v) {
        return '—';
    }
    $parts = array_filter([
        $v['year'] ?? '',
        $v['make'] ?? '',
        $v['model'] ?? '',
    ]);
    $label = implode(' ', $parts);
    if (!empty($v['plate'])) {
        $label .= ' (' . $v['plate'] . ')';
    }
    return $label !== '' ? $label : '—';
}

/* ---------------- Jobs ---------------- */

function get_job(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM jobs WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function job_lines(int $job_id): array
{
    $st = db()->prepare('SELECT * FROM job_lines WHERE job_id = ? ORDER BY id ASC');
    $st->execute([$job_id]);
    return $st->fetchAll();
}

function job_totals(array $lines, float $tax_rate): array
{
    $sub = 0.0;
    foreach ($lines as $l) {
        $sub += (float)$l['qty'] * (float)$l['unit_price'];
    }
    $sub = round($sub, 2);
    $tax = round($sub * $tax_rate / 100, 2);
    return ['subtotal' => $sub, 'tax' => $tax, 'total' => round($sub + $tax, 2)];
}

function get_snapshot(int $job_id, string $stage): ?array
{
    $st = db()->prepare('SELECT * FROM job_snapshots WHERE job_id = ? AND stage = ?');
    $st->execute([$job_id, $stage]);
    $row = $st->fetch();
    return $row ?: null;
}

function get_signature(int $job_id, string $stage): ?array
{
    $st = db()->prepare('SELECT * FROM signatures WHERE job_id = ? AND stage = ?');
    $st->execute([$job_id, $stage]);
    $row = $st->fetch();
    return $row ?: null;
}

function status_badge(string $status): string
{
    $map = [
        'estimate'   => ['Estimate', '#40A0F0'],
        'authorized' => ['Authorized', '#E8930C'],
        'invoiced'   => ['Invoiced', '#1F9D55'],
        'paid'       => ['Paid', '#004070'],
    ];
    [$label, $color] = $map[$status] ?? [ucfirst($status), '#666'];
    return '<span class="badge" style="background:' . $color . ';"><span class="bdot"></span>' . e($label) . '</span>';
}

function stage_title(string $stage): string
{
    return [
        'estimate'      => 'Estimate',
        'authorization' => 'Repair Authorization',
        'invoice'       => 'Final Invoice',
    ][$stage] ?? ucfirst($stage);
}

/**
 * Build the immutable data array for a finalized document.
 * Used both when saving a snapshot and when generating a PDF.
 */
function build_snapshot_data(int $job_id, string $stage): array
{
    $job = get_job($job_id);
    if (!$job) {
        throw new RuntimeException('Job not found.');
    }
    $client  = get_client((int)$job['client_id']);
    $vehicle = !empty($job['vehicle_id']) ? get_vehicle((int)$job['vehicle_id']) : null;
    $lines   = job_lines($job_id);
    $biz     = business_info();
    $totals  = job_totals($lines, $biz['tax_rate']);
    $sig     = in_array($stage, ['authorization', 'invoice'], true)
        ? get_signature($job_id, $stage)
        : null;

    return [
        'stage'      => $stage,
        'title'      => stage_title($stage),
        'job'        => [
            'id'            => (int)$job['id'],
            'problem_title' => (string)$job['problem_title'],
            'status'        => (string)$job['status'],
            'created_at'    => (string)$job['created_at'],
        ],
        'client'     => $client ? [
            'name'    => client_name($client),
            'phone'   => (string)$client['phone'],
            'email'   => (string)$client['email'],
            'address' => (string)$client['address'],
        ] : null,
        'vehicle'    => $vehicle ? [
            'label'   => vehicle_label($vehicle),
            'year'    => (string)$vehicle['year'],
            'make'    => (string)$vehicle['make'],
            'model'   => (string)$vehicle['model'],
            'vin'     => (string)$vehicle['vin'],
            'plate'   => (string)$vehicle['plate'],
            'mileage' => (string)$vehicle['mileage'],
            'color'   => (string)$vehicle['color'],
        ] : null,
        'lines'      => array_map(static function (array $l): array {
            return [
                'kind'        => (string)$l['kind'],
                'description' => (string)$l['description'],
                'qty'         => (float)$l['qty'],
                'unit_price'  => (float)$l['unit_price'],
                'line_total'  => round((float)$l['qty'] * (float)$l['unit_price'], 2),
                'stage_added' => (string)$l['stage_added'],
            ];
        }, $lines),
        'totals'     => $totals,
        'tax_rate'   => $biz['tax_rate'],
        'business'   => [
            'name'    => $biz['name'],
            'address' => $biz['address'],
            'phone'   => $biz['phone'],
        ],
        'warranty'   => $biz['warranty'],
        'signature'  => $sig ? [
            'signer_name' => (string)$sig['signer_name'],
            'image_path'  => (string)$sig['image_path'],
            'signed_at'   => (string)$sig['signed_at'],
        ] : null,
        'finalized_at' => date('Y-m-d H:i:s'),
    ];
}

function save_snapshot(int $job_id, string $stage): void
{
    $data = build_snapshot_data($job_id, $stage);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode snapshot.');
    }
    $chk = db()->prepare('SELECT id FROM job_snapshots WHERE job_id = ? AND stage = ?');
    $chk->execute([$job_id, $stage]);
    if ($chk->fetch()) {
        throw new RuntimeException('A finalized snapshot already exists for this stage and cannot be overwritten.');
    }
    $st = db()->prepare(
        'INSERT INTO job_snapshots (job_id, stage, snapshot_json) VALUES (?, ?, ?)'
    );
    $st->execute([$job_id, $stage, $json]);
}

/**
 * Validate and store a signature PNG sent as a data URL.
 * Returns the relative path stored in the DB.
 */
/** True when a signature PNG has no visible strokes (blank canvas submit). */
function signature_image_is_blank(string $png_bytes): bool
{
    if (!function_exists('imagecreatefromstring')) {
        return false; // can't verify — let it through like before
    }
    $img = @imagecreatefromstring($png_bytes);
    if (!$img) {
        return true;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $step_x = max(1, (int)($w / 60));
    $step_y = max(1, (int)($h / 30));
    for ($y = 0; $y < $h; $y += $step_y) {
        for ($x = 0; $x < $w; $x += $step_x) {
            $c = imagecolorat($img, $x, $y);
            $alpha = ($c >> 24) & 0x7F; // 0 = opaque, 127 = fully transparent
            if ($alpha > 100) {
                continue;
            }
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            if ($r < 250 || $g < 250 || $b < 250) {
                imagedestroy($img);
                return false; // found a visible stroke
            }
        }
    }
    imagedestroy($img);
    return true;
}

function save_signature_image(int $job_id, string $stage, string $data_url): string
{
    if (!preg_match('#^data:image/png;base64,#', $data_url)) {
        throw new RuntimeException('Invalid signature image.');
    }
    $raw = base64_decode(substr($data_url, 22), true);
    if ($raw === false || strlen($raw) < 100 || strlen($raw) > 2 * 1024 * 1024) {
        throw new RuntimeException('Invalid signature image.');
    }
    if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException('Invalid signature image.');
    }
    if (signature_image_is_blank($raw)) {
        throw new RuntimeException('Please draw your signature before continuing.');
    }
    $dir = __DIR__ . '/../uploads/signatures';
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }
    $name = sprintf('job%d_%s_%s.png', $job_id, $stage, bin2hex(random_bytes(8)));
    if (file_put_contents($dir . '/' . $name, $raw) === false) {
        throw new RuntimeException('Could not save signature image.');
    }
    return 'uploads/signatures/' . $name;
}

function store_signature(int $job_id, string $stage, string $signer_name, string $data_url): void
{
    $path = save_signature_image($job_id, $stage, $data_url);
    $st = db()->prepare(
        'INSERT INTO signatures (job_id, stage, signer_name, image_path)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE signer_name = VALUES(signer_name), image_path = VALUES(image_path), signed_at = CURRENT_TIMESTAMP'
    );
    $st->execute([$job_id, $stage, $signer_name, $path]);
}

/* ---------------- Client share links (public, token-based) ---------------- */

/**
 * Links let a client view/sign one document without logging in.
 * The 48-char token is the only capability; it grants access to
 * exactly one (job_id, stage). Table is created on first use so no
 * manual migration is needed on the server.
 */
function share_links_init(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS share_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            stage VARCHAR(20) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_job_stage (job_id, stage)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function get_share_link(int $job_id, string $stage): ?array
{
    share_links_init();
    $st = db()->prepare('SELECT * FROM share_links WHERE job_id = ? AND stage = ?');
    $st->execute([$job_id, $stage]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Create (or return the existing) share link for a job stage. Idempotent. */
function create_share_link(int $job_id, string $stage): array
{
    if (!in_array($stage, ['estimate', 'authorization', 'invoice'], true)) {
        throw new RuntimeException('Invalid stage.');
    }
    $existing = get_share_link($job_id, $stage);
    if ($existing) {
        return $existing;
    }
    $token = bin2hex(random_bytes(24)); // 48 hex chars, unguessable
    $st = db()->prepare('INSERT INTO share_links (job_id, stage, token) VALUES (?, ?, ?)');
    $st->execute([$job_id, $stage, $token]);
    $row = get_share_link($job_id, $stage);
    if (!$row) {
        throw new RuntimeException('Could not create the client link.');
    }
    return $row;
}

/** Find a share link by its token. Returns null for malformed/unknown tokens. */
function find_share_link(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    share_links_init();
    $st = db()->prepare('SELECT * FROM share_links WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function app_base_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $dir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    if ($dir === '' || $dir === '.') {
        $dir = '';
    }
    return 'https://' . $host . $dir;
}

function share_link_url(string $token): string
{
    return app_base_url() . '/r.php?token=' . $token;
}

/* ---------------- Client intake links (public, token-based, single-use) ---------------- */

/**
 * A client fills in their own info + vehicle + problem through intake.php?token=...
 * Each token works exactly ONCE: after a successful submit it is marked
 * consumed, so refreshing or reopening the link shows the thank-you page
 * instead of the form (no duplicate submissions). Table is created on
 * first use so no manual migration is needed on the server.
 */
function intake_links_init(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS intake_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL DEFAULT "",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            consumed_at TIMESTAMP NULL DEFAULT NULL,
            client_id INT UNSIGNED NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Create a fresh single-use intake link. */
function create_intake_link(string $label = ''): array
{
    intake_links_init();
    $token = bin2hex(random_bytes(24)); // 48 hex chars, unguessable
    $st = db()->prepare('INSERT INTO intake_links (token, label) VALUES (?, ?)');
    $st->execute([$token, substr(trim($label), 0, 120)]);
    $row = find_intake_link($token);
    if (!$row) {
        throw new RuntimeException('Could not create the intake link.');
    }
    return $row;
}

/** Find an intake link by token. Null for malformed/unknown tokens. */
function find_intake_link(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    intake_links_init();
    $st = db()->prepare('SELECT * FROM intake_links WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Mark a link as consumed. The `consumed_at IS NULL` guard makes this
 * atomic: concurrent double-submits only let the first one through.
 * Returns true if this call consumed it, false if it was already used.
 */
function consume_intake_link(string $token, int $client_id): bool
{
    intake_links_init();
    $st = db()->prepare(
        'UPDATE intake_links SET consumed_at = NOW(), client_id = ?
         WHERE token = ? AND consumed_at IS NULL'
    );
    $st->execute([$client_id, $token]);
    return $st->rowCount() > 0;
}

/** Recent intake links, newest first, with client name when consumed. */
function list_intake_links(int $limit = 15): array
{
    intake_links_init();
    $limit = max(1, min(50, $limit));
    $st = db()->query(
        'SELECT il.*, CONCAT(c.first_name, " ", c.last_name) AS client_name
         FROM intake_links il
         LEFT JOIN clients c ON c.id = il.client_id
         ORDER BY il.id DESC LIMIT ' . $limit
    );
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function delete_intake_link(int $id): void
{
    intake_links_init();
    $st = db()->prepare('DELETE FROM intake_links WHERE id = ?');
    $st->execute([$id]);
}

function intake_link_url(string $token): string
{
    return app_base_url() . '/intake.php?token=' . $token;
}

/** Fetch one intake link by id (admin view). */
function get_intake_link(int $id): ?array
{
    intake_links_init();
    $st = db()->prepare('SELECT * FROM intake_links WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* ---------------- Job photos (work documentation, admin-managed) ---------------- */

/**
 * Photos document what was done to the vehicle during a job: damage found,
 * work in progress, finished repairs. They never go into the PDF.
 * Only the admin can add/delete them. Clients see them through a private
 * photo link (p.php) that expires when the invoice is finalized.
 * Tables are created on first use so no manual migration is needed.
 */
function job_photos_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec(
        'CREATE TABLE IF NOT EXISTS job_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime VARCHAR(60) NOT NULL DEFAULT "image/jpeg",
            caption VARCHAR(200) NOT NULL DEFAULT "",
            category VARCHAR(20) NOT NULL DEFAULT "initial",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_job_photos_job (job_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS photo_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL UNIQUE,
            token VARCHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    // Older installs: add the category / updated_at columns if missing.
    $st = db()->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_photos'
           AND COLUMN_NAME IN ('category', 'updated_at')"
    );
    $st->execute();
    $have = array_column($st->fetchAll(), 'COLUMN_NAME');
    if (!in_array('category', $have, true)) {
        db()->exec(
            "ALTER TABLE job_photos ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'initial'"
        );
    }
    if (!in_array('updated_at', $have, true)) {
        // Backfill with created_at so the incremental backup does not
        // re-download every existing photo as "new".
        db()->exec('ALTER TABLE job_photos ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL');
        db()->exec('UPDATE job_photos SET updated_at = created_at WHERE updated_at IS NULL');
        db()->exec(
            'ALTER TABLE job_photos MODIFY COLUMN updated_at TIMESTAMP NOT NULL
             DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
    }
}

/** Max photo size accepted from the browser (12 MB). */
const JOB_PHOTO_MAX_BYTES = 12 * 1024 * 1024;
/** Longest side of the stored image; bigger originals are downscaled. */
const JOB_PHOTO_MAX_DIM = 1600;

const JOB_PHOTO_MIMES = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'image/heic', 'image/heif',
];

/** Work-photo categories (admin assigns; client sees the English labels). */
const PHOTO_CATEGORIES = [
    'initial'   => 'Initial Inspection',
    'progress'  => 'In Progress',
    'completed' => 'Completed',
];

/**
 * Read an uploaded image, physically apply its EXIF orientation so the
 * stored pixels are upright, downscale to JOB_PHOTO_MAX_DIM, and save as
 * JPEG. Phones store photos in sensor orientation plus an EXIF tag saying
 * how to rotate them; re-encoding drops the tag, so the pixels must be
 * rotated now or the photo shows up sideways.
 * Returns true on success, false if GD cannot decode it (caller falls back
 * to storing the original bytes).
 */
function store_upright_jpeg(string $tmp, string $dest): bool
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return false;
    }
    $raw = @file_get_contents($tmp);
    $src = $raw !== false ? @imagecreatefromstring($raw) : false;
    if ($src === false) {
        return false;
    }
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $src = apply_exif_orientation($src, (int)($exif['Orientation'] ?? 1));
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, JOB_PHOTO_MAX_DIM / max($w, $h, 1));
    if ($scale < 1.0) {
        $nw = (int)round($w * $scale);
        $nh = (int)round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }
    $ok = @imagejpeg($src, $dest, 82);
    imagedestroy($src);
    return (bool)$ok;
}

/**
 * Physically rotate/flip a GD image per its EXIF orientation tag.
 * Note: imagerotate() turns counter-clockwise.
 */
function apply_exif_orientation($src, int $orientation)
{
    switch ($orientation) {
        case 2: // mirror horizontal
            imageflip($src, IMG_FLIP_HORIZONTAL);
            break;
        case 3: // rotate 180
            $src = imagerotate($src, 180, 0);
            break;
        case 4: // mirror vertical
            imageflip($src, IMG_FLIP_VERTICAL);
            break;
        case 5: // mirror horizontal, then rotate 270 CW
            imageflip($src, IMG_FLIP_HORIZONTAL);
            $src = imagerotate($src, 270, 0);
            break;
        case 6: // rotate 90 CW
            $src = imagerotate($src, 270, 0);
            break;
        case 7: // mirror horizontal, then rotate 90 CW
            imageflip($src, IMG_FLIP_HORIZONTAL);
            $src = imagerotate($src, 90, 0);
            break;
        case 8: // rotate 270 CW
            $src = imagerotate($src, 90, 0);
            break;
    }
    return $src;
}

/** English label for a photo category key; falls back to Initial Inspection. */
function photo_category_label(string $cat): string
{
    return PHOTO_CATEGORIES[$cat] ?? PHOTO_CATEGORIES['initial'];
}

/** Normalize a submitted category key; unknown values become 'initial'. */
function photo_category_key(string $cat): string
{
    $cat = strtolower(trim($cat));
    return isset(PHOTO_CATEGORIES[$cat]) ? $cat : 'initial';
}

/** Group a photo list by category key, preserving category order. */
function group_photos_by_category(array $photos): array
{
    $groups = [];
    foreach (array_keys(PHOTO_CATEGORIES) as $k) {
        $groups[$k] = [];
    }
    foreach ($photos as $ph) {
        $k = photo_category_key((string)($ph['category'] ?? 'initial'));
        $groups[$k][] = $ph;
    }
    return $groups;
}

/** Add clients.id_image (+ id_image_updated_at) if missing (auto-migration). */
function clients_id_image_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $st = db()->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'
           AND COLUMN_NAME IN ('id_image', 'id_image_updated_at')"
    );
    $st->execute();
    $have = array_column($st->fetchAll(), 'COLUMN_NAME');
    if (!in_array('id_image', $have, true)) {
        db()->exec('ALTER TABLE clients ADD COLUMN id_image VARCHAR(255) NULL DEFAULT NULL');
    }
    if (!in_array('id_image_updated_at', $have, true)) {
        db()->exec('ALTER TABLE clients ADD COLUMN id_image_updated_at TIMESTAMP NULL DEFAULT NULL');
    }
}

/**
 * Validate, optionally downscale, and store one uploaded photo.
 * Returns the new photo row. Throws RuntimeException on any problem.
 */
function save_job_photo(int $job_id, array $file, string $category = 'initial'): array
{
    job_photos_init();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (error ' . (int)($file['error'] ?? -1) . ').');
    }
    if (($file['size'] ?? 0) > JOB_PHOTO_MAX_BYTES) {
        throw new RuntimeException('Photo is too large (max 12 MB).');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }
    $info = @getimagesize($tmp);
    if ($info === false || !in_array($info['mime'] ?? '', JOB_PHOTO_MIMES, true)) {
        throw new RuntimeException('File is not a supported image (JPG, PNG, WEBP, GIF, HEIC).');
    }

    $dir = __DIR__ . '/../uploads/photos';
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('Could not create photo directory.');
    }
    $name = sprintf('job%d_%s.jpg', $job_id, bin2hex(random_bytes(8)));
    $dest = $dir . '/' . $name;

    // Upright (EXIF-aware) + downscale via GD; fall back to original bytes.
    $stored = store_upright_jpeg($tmp, $dest);
    if (!$stored) {
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Could not save the photo.');
        }
    }
    $mime = $stored ? 'image/jpeg' : (string)$info['mime'];
    $path = 'uploads/photos/' . $name;
    $st = db()->prepare(
        'INSERT INTO job_photos (job_id, file_path, mime, category) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$job_id, $path, $mime, photo_category_key($category)]);
    $row = get_job_photo((int)db()->lastInsertId());
    if (!$row) {
        throw new RuntimeException('Could not record the photo.');
    }
    return $row;
}

/** All photos of a job, oldest first. */
function get_job_photos(int $job_id): array
{
    job_photos_init();
    $st = db()->prepare('SELECT * FROM job_photos WHERE job_id = ? ORDER BY id ASC');
    $st->execute([$job_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Fetch one photo by id. */
function get_job_photo(int $id): ?array
{
    job_photos_init();
    $st = db()->prepare('SELECT * FROM job_photos WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Update a photo caption (admin only; enforced by caller). */
function set_job_photo_caption(int $id, int $job_id, string $caption): void
{
    job_photos_init();
    $st = db()->prepare(
        'UPDATE job_photos SET caption = ? WHERE id = ? AND job_id = ?'
    );
    $st->execute([substr(trim($caption), 0, 200), $id, $job_id]);
}

/** Move a photo to another category (admin only; enforced by caller). */
function set_job_photo_category(int $id, int $job_id, string $category): void
{
    job_photos_init();
    $st = db()->prepare(
        'UPDATE job_photos SET category = ? WHERE id = ? AND job_id = ?'
    );
    $st->execute([photo_category_key($category), $id, $job_id]);
}

/**
 * Rotate a stored photo 90° clockwise (admin only; enforced by caller).
 * For photos saved before the EXIF-orientation fix, whose pixels ended up
 * sideways. Each call turns another 90°, so four taps complete the circle.
 * Bumps updated_at so the incremental backup picks up the fixed file.
 */
function rotate_job_photo(int $id, int $job_id): void
{
    $photo = get_job_photo($id);
    if (!$photo || (int)$photo['job_id'] !== $job_id) {
        throw new RuntimeException('Photo not found.');
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        throw new RuntimeException('Image tools are not available on the server.');
    }
    $disk = __DIR__ . '/../' . $photo['file_path'];
    if (!is_file($disk)) {
        throw new RuntimeException('Photo file is missing.');
    }
    $raw = @file_get_contents($disk);
    $src = $raw !== false ? @imagecreatefromstring($raw) : false;
    if ($src === false) {
        throw new RuntimeException('Could not read the photo.');
    }
    $rotated = imagerotate($src, 270, 0); // 270° counter-clockwise = 90° clockwise
    imagedestroy($src);
    if ($rotated === false || !@imagejpeg($rotated, $disk, 82)) {
        if ($rotated !== false) {
            imagedestroy($rotated);
        }
        throw new RuntimeException('Could not save the rotated photo.');
    }
    imagedestroy($rotated);
    db()->prepare('UPDATE job_photos SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$id]);
}

/** Delete a photo: file and row. */
function delete_job_photo(int $id, int $job_id): void
{
    $photo = get_job_photo($id);
    if (!$photo || (int)$photo['job_id'] !== $job_id) {
        throw new RuntimeException('Photo not found.');
    }
    $disk = __DIR__ . '/../' . $photo['file_path'];
    $st = db()->prepare('DELETE FROM job_photos WHERE id = ? AND job_id = ?');
    $st->execute([$id, $job_id]);
    if (is_file($disk)) {
        @unlink($disk);
    }
}

/** Create (or return the existing) photo link for a job. One link per job. */
function create_photo_link(int $job_id): array
{
    job_photos_init();
    $existing = get_photo_link($job_id);
    if ($existing) {
        return $existing;
    }
    $token = bin2hex(random_bytes(24)); // 48 hex chars, unguessable
    $st = db()->prepare('INSERT INTO photo_links (job_id, token) VALUES (?, ?)');
    $st->execute([$job_id, $token]);
    $row = get_photo_link($job_id);
    if (!$row) {
        throw new RuntimeException('Could not create the photo link.');
    }
    return $row;
}

/** The active photo link of a job, if any. */
function get_photo_link(int $job_id): ?array
{
    job_photos_init();
    $st = db()->prepare('SELECT * FROM photo_links WHERE job_id = ?');
    $st->execute([$job_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Find a photo link by token. Null for malformed/unknown tokens. */
function find_photo_link(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    job_photos_init();
    $st = db()->prepare('SELECT * FROM photo_links WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function photo_link_url(string $token): string
{
    return app_base_url() . '/p.php?token=' . $token;
}

/* ---------------- Job payment info (admin-managed) ---------------- */

/**
 * Payment methods the admin can record when a job becomes an invoice.
 * The client sees the chosen method + notes on the invoice; the receipt
 * photo stays private (admin only).
 */
const PAYMENT_METHODS = [
    'cash'        => 'Cash',
    'debit'       => 'Debit',
    'credit'      => 'Credit',
    'check'       => 'Check',
    'zelle'       => 'Zelle',
    'link_to_pay' => 'Link to Pay',
];

/** Normalize a submitted payment method key; unknown/empty becomes ''. */
function payment_method_key(string $m): string
{
    $m = strtolower(trim($m));
    return isset(PAYMENT_METHODS[$m]) ? $m : '';
}

/** English label for a payment method key ('' when none). */
function payment_method_label(string $m): string
{
    $k = payment_method_key($m);
    return $k !== '' ? PAYMENT_METHODS[$k] : '';
}

/** Add the payment columns to jobs if missing (auto-migration). */
function jobs_payment_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $st = db()->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs'
           AND COLUMN_NAME IN ('payment_method', 'payment_notes',
                               'payment_photo', 'payment_photo_updated_at')"
    );
    $st->execute();
    $have = array_column($st->fetchAll(), 'COLUMN_NAME');
    $add = [
        'payment_method'          => 'VARCHAR(20) NULL DEFAULT NULL',
        'payment_notes'           => 'VARCHAR(255) NULL DEFAULT NULL',
        'payment_photo'           => 'VARCHAR(255) NULL DEFAULT NULL',
        'payment_photo_updated_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $have, true)) {
            db()->exec("ALTER TABLE jobs ADD COLUMN $col $def");
        }
    }
}

/** The job's payment info (method key, notes, whether it has a photo). */
function job_payment(int $job_id): array
{
    jobs_payment_migrate();
    $st = db()->prepare(
        'SELECT payment_method, payment_notes, payment_photo FROM jobs WHERE id = ?'
    );
    $st->execute([$job_id]);
    $row = $st->fetch() ?: [];
    return [
        'method'    => (string)($row['payment_method'] ?? ''),
        'notes'     => (string)($row['payment_notes'] ?? ''),
        'has_photo' => !empty($row['payment_photo']),
    ];
}

/**
 * Save the job's payment method + notes, optionally with a receipt photo.
 * The photo is private (admin only). Throws RuntimeException on bad upload.
 */
function save_job_payment(int $job_id, string $method, string $notes, array $file = []): void
{
    jobs_payment_migrate();
    $method = payment_method_key($method);
    $notes = substr(trim($notes), 0, 255);

    $photo_path = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        if (($file['size'] ?? 0) > JOB_PHOTO_MAX_BYTES) {
            throw new RuntimeException('Photo is too large (max 12 MB).');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }
        $info = @getimagesize($tmp);
        if ($info === false || !in_array($info['mime'] ?? '', JOB_PHOTO_MIMES, true)) {
            throw new RuntimeException('File is not a supported image (JPG, PNG, WEBP, GIF, HEIC).');
        }
        $dir = __DIR__ . '/../uploads/payments';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new RuntimeException('Could not create payment photo directory.');
        }
        $name = sprintf('job%d_pay_%s.jpg', $job_id, bin2hex(random_bytes(8)));
        $dest = $dir . '/' . $name;
        $stored = store_upright_jpeg($tmp, $dest);
        if (!$stored && !move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Could not save the photo.');
        }
        // Remove the previous receipt photo, if any.
        $st = db()->prepare('SELECT payment_photo FROM jobs WHERE id = ?');
        $st->execute([$job_id]);
        $old_rel = (string)($st->fetchColumn() ?? '');
        if ($old_rel !== '') {
            $disk = __DIR__ . '/../' . $old_rel;
            if (is_file($disk)) {
                @unlink($disk);
            }
        }
        $photo_path = 'uploads/payments/' . $name;
    }

    if ($photo_path !== null) {
        db()->prepare(
            'UPDATE jobs SET payment_method = ?, payment_notes = ?,
             payment_photo = ?, payment_photo_updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([$method !== '' ? $method : null, $notes !== '' ? $notes : null, $photo_path, $job_id]);
    } else {
        db()->prepare(
            'UPDATE jobs SET payment_method = ?, payment_notes = ? WHERE id = ?'
        )->execute([$method !== '' ? $method : null, $notes !== '' ? $notes : null, $job_id]);
    }
    touch_job($job_id);
}

/** Remove the job's receipt photo (file + DB reference). */
function delete_job_payment_photo(int $job_id): void
{
    jobs_payment_migrate();
    $st = db()->prepare('SELECT payment_photo FROM jobs WHERE id = ?');
    $st->execute([$job_id]);
    $rel = (string)($st->fetchColumn() ?? '');
    if ($rel !== '') {
        $disk = __DIR__ . '/../' . $rel;
        if (is_file($disk)) {
            @unlink($disk);
        }
    }
    db()->prepare(
        'UPDATE jobs SET payment_photo = NULL, payment_photo_updated_at = NULL WHERE id = ?'
    )->execute([$job_id]);
    touch_job($job_id);
}

/* ---------------- Diagnosis result (admin-written, client-visible) ---------------- */

/** Add the diagnosis_result column to jobs if missing (auto-migration). */
function jobs_diagnosis_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $st = db()->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs'
           AND COLUMN_NAME = 'diagnosis_result'"
    );
    $st->execute();
    if ($st->fetchColumn() === false) {
        db()->exec('ALTER TABLE jobs ADD COLUMN diagnosis_result TEXT NULL DEFAULT NULL');
    }
}

/** The job's diagnosis result text ('' when nothing was written). */
function job_diagnosis(int $job_id): string
{
    jobs_diagnosis_migrate();
    $st = db()->prepare('SELECT diagnosis_result FROM jobs WHERE id = ?');
    $st->execute([$job_id]);
    return (string)($st->fetchColumn() ?? '');
}

/** Save the job's diagnosis result (empty string clears it). */
function save_job_diagnosis(int $job_id, string $text): void
{
    jobs_diagnosis_migrate();
    $text = trim($text);
    db()->prepare('UPDATE jobs SET diagnosis_result = ? WHERE id = ?')
        ->execute([$text !== '' ? $text : null, $job_id]);
    touch_job($job_id);
}

if (!function_exists('clean_doc_text')) {
/**
 * Prepare a long-form text block (diagnosis result, warranty) for display.
 * - Normalizes \r\n line endings.
 * - Drops a duplicated heading first line (headings render automatically).
 * - Unwraps hard single line breaks (e.g. from pasted text) so paragraphs
 *   flow and justify as one block. Blank lines (paragraphs) and list
 *   items (1. / - / bullets) are preserved.
 */
function clean_doc_text(string $text, string $title): string
{
    $t = str_replace("\r\n", "\n", $text);
    $t = str_replace("\r", "\n", $t);
    $t = ltrim($t);
    $nl = strpos($t, "\n");
    $first = $nl === false ? $t : substr($t, 0, $nl);
    $norm_first = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $first));
    $norm_title = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $title));
    if ($first !== '' && $norm_first === $norm_title) {
        $t = $nl === false ? '' : substr($t, $nl + 1);
        $t = ltrim($t);
    }
    $t = (string)preg_replace('/(?<!\n)\n(?!\n)(?!\s*(?:\d+[.)]\s|[-•*]\s))/u', ' ', $t);
    return trim($t);
}
}

/* ---------------- Client ID image (admin-managed) ---------------- */

/**
 * Store (or replace) the client's ID photo. Validates and downscales like
 * job photos. Updates clients.id_image + id_image_updated_at (the latter
 * drives the incremental Drive backup). Returns the stored path.
 * Throws RuntimeException on any problem.
 */
function save_client_id_image(int $client_id, array $file): string
{
    clients_id_image_migrate();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (error ' . (int)($file['error'] ?? -1) . ').');
    }
    if (($file['size'] ?? 0) > JOB_PHOTO_MAX_BYTES) {
        throw new RuntimeException('Photo is too large (max 12 MB).');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }
    $info = @getimagesize($tmp);
    if ($info === false || !in_array($info['mime'] ?? '', JOB_PHOTO_MIMES, true)) {
        throw new RuntimeException('File is not a supported image (JPG, PNG, WEBP, GIF, HEIC).');
    }

    $dir = __DIR__ . '/../uploads/id';
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('Could not create ID photo directory.');
    }
    $name = sprintf('client%d_%s.jpg', $client_id, bin2hex(random_bytes(8)));
    $dest = $dir . '/' . $name;

    // Upright (EXIF-aware) + downscale via GD; fall back to original bytes.
    $stored = store_upright_jpeg($tmp, $dest);
    if (!$stored) {
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Could not save the photo.');
        }
    }

    // Remove the previous ID image file, if any.
    $old = get_client($client_id);
    if ($old && !empty($old['id_image'])) {
        $disk = __DIR__ . '/../' . $old['id_image'];
        if (is_file($disk)) {
            @unlink($disk);
        }
    }

    $path = 'uploads/id/' . $name;
    db()->prepare(
        'UPDATE clients SET id_image = ?, id_image_updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    )->execute([$path, $client_id]);
    touch_client($client_id);
    return $path;
}

/** Remove the client's ID image (file + DB reference). */
function delete_client_id_image(int $client_id): void
{
    clients_id_image_migrate();
    $client = get_client($client_id);
    if ($client && !empty($client['id_image'])) {
        $disk = __DIR__ . '/../' . $client['id_image'];
        if (is_file($disk)) {
            @unlink($disk);
        }
    }
    db()->prepare(
        'UPDATE clients SET id_image = NULL, id_image_updated_at = NULL WHERE id = ?'
    )->execute([$client_id]);
    touch_client($client_id);
}

/* ---------------- Reopen finalized estimates ---------------- */

/**
 * Reopen a finalized estimate so it can be edited and finalized again.
 * Only allowed while the job is still in 'estimate' status (it has not
 * moved on to authorization/invoice). Client/vehicle info is read live
 * when finalizing, so edits to the client or vehicle are picked up.
 * The existing client share link keeps working: it shows "not ready
 * yet" until the estimate is finalized again, then serves the new
 * version — no need to send a new link.
 */
function reopen_estimate(int $job_id): void
{
    $job = get_job($job_id);
    if (!$job) {
        throw new RuntimeException('Job not found.');
    }
    if ((string)$job['status'] !== 'estimate') {
        throw new RuntimeException('Only estimates still in estimate stage can be reopened.');
    }
    if (!get_snapshot($job_id, 'estimate')) {
        throw new RuntimeException('This estimate is not finalized.');
    }
    $st = db()->prepare("DELETE FROM job_snapshots WHERE job_id = ? AND stage = 'estimate'");
    $st->execute([$job_id]);
    if ($st->rowCount() === 0) {
        throw new RuntimeException('Could not reopen the estimate.');
    }
}

/* ---------------- Delete jobs ---------------- */

/**
 * Delete a job and everything under it: lines, snapshots, signatures,
 * share/photo links, and photo/signature files. The client and vehicle
 * are kept. This cannot be undone.
 */
function delete_job(int $job_id): void
{
    $job = get_job($job_id);
    if (!$job) {
        throw new RuntimeException('Job not found.');
    }
    share_links_init();
    job_photos_init();
    jobs_payment_migrate();
    // Collect files before the rows disappear.
    $paths = [];
    foreach (get_job_photos($job_id) as $ph) {
        $paths[] = __DIR__ . '/../' . $ph['file_path'];
    }
    if (!empty($job['payment_photo'])) {
        $paths[] = __DIR__ . '/../' . $job['payment_photo'];
    }
    foreach (['estimate', 'authorization', 'invoice'] as $stg) {
        $sig = get_signature($job_id, $stg);
        if ($sig && !empty($sig['image_path'])) {
            $paths[] = __DIR__ . '/../' . $sig['image_path'];
        }
    }
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM job_photos WHERE job_id = ?')->execute([$job_id]);
        db()->prepare('DELETE FROM photo_links WHERE job_id = ?')->execute([$job_id]);
        db()->prepare('DELETE FROM share_links WHERE job_id = ?')->execute([$job_id]);
        // job_lines, job_snapshots and signatures cascade from jobs.
        db()->prepare('DELETE FROM jobs WHERE id = ?')->execute([$job_id]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    foreach ($paths as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/* ---------------- Live profile overlay for client-facing documents ---------------- */

/**
 * Client, vehicle and business info are NEVER locked: they are read live
 * from the database every time a document is shown or printed, so edits
 * made in the admin are immediately visible in the client link and PDF.
 * Lines, totals, tax and signatures stay frozen as finalized — that is what
 * was agreed/signed. Warranty & Policies stay frozen on the signed
 * authorization, but are live on the invoice (pdf.php / r.php overlay the
 * current Settings text), so the business's current policies always print.
 *
 * The signed authorization is the exception: once signed it renders
 * exactly as signed (frozen), which is what protects both sides.
 */
function live_doc_profile(int $job_id): array
{
    $job     = get_job($job_id);
    $client  = $job ? get_client((int)$job['client_id']) : null;
    $vehicle = ($job && !empty($job['vehicle_id'])) ? get_vehicle((int)$job['vehicle_id']) : null;
    $biz     = business_info();
    return [
        'client'   => $client ? [
            'name'    => client_name($client),
            'phone'   => (string)$client['phone'],
            'email'   => (string)$client['email'],
            'address' => (string)$client['address'],
        ] : null,
        'vehicle'  => $vehicle ? [
            'label'   => vehicle_label($vehicle),
            'year'    => (string)$vehicle['year'],
            'make'    => (string)$vehicle['make'],
            'model'   => (string)$vehicle['model'],
            'vin'     => (string)$vehicle['vin'],
            'plate'   => (string)$vehicle['plate'],
            'mileage' => (string)$vehicle['mileage'],
            'color'   => (string)$vehicle['color'],
        ] : null,
        'business' => [
            'name'    => (string)$biz['name'],
            'address' => (string)$biz['address'],
            'phone'   => (string)$biz['phone'],
        ],
    ];
}

/** Overlay live client/vehicle/business info onto a snapshot-shaped doc. */
function apply_live_profile(array $doc, int $job_id): array
{
    $live = live_doc_profile($job_id);
    $doc['client']   = $live['client'];
    $doc['vehicle']  = $live['vehicle'];
    $doc['business'] = $live['business'];
    return $doc;
}

/* ---------------- Owner notifications ---------------- */

/** Email address that receives automatic owner notifications. */
function notify_email(): string
{
    $email = trim(setting_get('notify_email', 'you@example.com'));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'you@example.com';
}

/**
 * Send an email notification to the owner (Migue).
 * Never throws: a mail failure must never break the client-facing flow,
 * so any error is only logged.
 */
function notify_owner(string $subject, string $body): void
{
    try {
        $headers = implode("\r\n", [
            'From: AutoCode Invoices <info@autocodelv.com>',
            'Reply-To: info@autocodelv.com',
            'X-Mailer: AutoCode-Invoices',
            'Content-Type: text/plain; charset=UTF-8',
        ]);
        @mail(notify_email(), '[AutoCode] ' . $subject, $body, $headers);
    } catch (Throwable $t) {
        error_log('notify_owner failed: ' . $t->getMessage());
    }
}

/* ---------------- Timezone fix (7 oct 2026) ---------------- */

/**
 * One-time fix: before the DB session ran on Las Vegas time,
 * job_snapshots.finalized_at was written by PHP as Vegas wall-clock under a
 * UTC session, so every stored instant sits 7 hours early. Shift them forward
 * once. Guarded by a settings flag; safe to call on every request.
 */
function tz_fix_20261007_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (setting_get('tz_fix_20261007') === 'done') {
            return;
        }
        db()->exec('UPDATE job_snapshots SET finalized_at = DATE_ADD(finalized_at, INTERVAL 7 HOUR)');
        setting_set('tz_fix_20261007', 'done');
    } catch (Throwable $t) {
        error_log('tz_fix_20261007 failed: ' . $t->getMessage());
    }
}

/* ---------------- Client activity history ---------------- */

/** Add clients.updated_at if missing (auto-migration, runs once per request). */
function clients_updated_at_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $st = db()->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'updated_at'"
    );
    $st->execute();
    if (!(int)$st->fetchColumn()) {
        db()->exec(
            'ALTER TABLE clients ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
    }
}

/** Mark a client profile as modified (bumps it to the top of History). Never throws. */
function touch_client(int $client_id): void
{
    if ($client_id <= 0) {
        return;
    }
    try {
        clients_updated_at_migrate();
        db()->prepare('UPDATE clients SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$client_id]);
    } catch (Throwable $t) {
        error_log('touch_client failed: ' . $t->getMessage());
    }
}

/**
 * The N most recently active client profiles, by the latest document date
 * across their jobs: invoice finalized > authorization signed > estimate
 * finalized > job created. Jobs with no documents fall back to created_at.
 */
function client_history(int $limit = 10): array
{
    clients_updated_at_migrate();
    $limit = max(1, min(50, $limit));
    $st = db()->prepare(
        'SELECT c.id, c.first_name, c.last_name,
                MAX(COALESCE(s.latest_stage_at, j.created_at)) AS last_date
         FROM clients c
         LEFT JOIN jobs j ON j.client_id = c.id
         LEFT JOIN (SELECT job_id, MAX(finalized_at) AS latest_stage_at
                    FROM job_snapshots GROUP BY job_id) s ON s.job_id = j.id
         GROUP BY c.id ORDER BY last_date DESC, c.id DESC LIMIT ' . $limit
    );
    $st->execute();
    return $st->fetchAll();
}

/* ---------------- Delete client / vehicle ---------------- */

/**
 * Delete a client profile and EVERYTHING under it: vehicles, jobs, lines,
 * snapshots, signatures, share/photo links, intake links, and photo files.
 * This cannot be undone.
 */
function delete_client(int $client_id): void
{
    $client = get_client($client_id);
    if (!$client) {
        throw new RuntimeException('Client not found.');
    }
    share_links_init();
    job_photos_init();
    intake_links_init();
    clients_id_image_migrate();
    $st = db()->prepare('SELECT id FROM jobs WHERE client_id = ?');
    $st->execute([$client_id]);
    $job_ids = array_column($st->fetchAll(), 'id');

    // Collect files before the rows disappear.
    $paths = [];
    if (!empty($client['id_image'])) {
        $paths[] = __DIR__ . '/../' . $client['id_image'];
    }
    if ($job_ids) {
        $in = implode(',', array_fill(0, count($job_ids), '?'));
        $ph = db()->prepare("SELECT file_path FROM job_photos WHERE job_id IN ($in)");
        $ph->execute($job_ids);
        foreach ($ph->fetchAll() as $row) {
            $paths[] = __DIR__ . '/../' . $row['file_path'];
        }
        $sg = db()->prepare("SELECT image_path FROM signatures WHERE job_id IN ($in)");
        $sg->execute($job_ids);
        foreach ($sg->fetchAll() as $row) {
            if (!empty($row['image_path'])) {
                $paths[] = __DIR__ . '/../' . $row['image_path'];
            }
        }
    }

    db()->beginTransaction();
    try {
        if ($job_ids) {
            $in = implode(',', array_fill(0, count($job_ids), '?'));
            db()->prepare("DELETE FROM job_photos WHERE job_id IN ($in)")->execute($job_ids);
            db()->prepare("DELETE FROM photo_links WHERE job_id IN ($in)")->execute($job_ids);
            db()->prepare("DELETE FROM share_links WHERE job_id IN ($in)")->execute($job_ids);
            // job_lines, job_snapshots and signatures cascade from jobs.
            db()->prepare('DELETE FROM jobs WHERE client_id = ?')->execute([$client_id]);
        }
        db()->prepare('DELETE FROM intake_links WHERE client_id = ?')->execute([$client_id]);
        db()->prepare('DELETE FROM vehicles WHERE client_id = ?')->execute([$client_id]);
        db()->prepare('DELETE FROM clients WHERE id = ?')->execute([$client_id]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    foreach ($paths as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/**
 * Delete a vehicle from a client profile. Jobs that used it keep their
 * history (their vehicle_id is set to NULL by the foreign key).
 * Returns the owner client id.
 */
function delete_vehicle(int $vehicle_id): int
{
    $st = db()->prepare('SELECT client_id FROM vehicles WHERE id = ?');
    $st->execute([$vehicle_id]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Vehicle not found.');
    }
    db()->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$vehicle_id]);
    return (int)$row['client_id'];
}

/** Mark a job as modified (bumps its updated_at). Never throws. */
function touch_job(int $job_id): void
{
    if ($job_id <= 0) {
        return;
    }
    try {
        db()->prepare('UPDATE jobs SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$job_id]);
    } catch (Throwable $t) {
        error_log('touch_job failed: ' . $t->getMessage());
    }
}
