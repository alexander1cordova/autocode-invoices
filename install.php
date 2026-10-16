<?php
// ============================================================
// AutoCode Invoices — one-time installer.
// 1. Copy config.sample.php → config.php and fill it in.
// 2. Open install.php in your browser and create the admin user.
// 3. DELETE install.php when done (it locks itself too).
// ============================================================

$lock = __DIR__ . '/install.lock';
if (file_exists($lock)) {
    http_response_code(403);
    exit('Already installed. Delete install.php from the server.');
}

$config = __DIR__ . '/config.php';
if (!file_exists($config)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.sample.php to config.php and fill in your database values first.');
}
require $config;

$err = '';
$done = false;

session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $err = 'Invalid form token. Reload the page and try again.';
    } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $pw1 = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        $err = 'Username: 3–50 chars, letters/numbers/._- only.';
    } elseif (strlen($pw1) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $err = 'Passwords do not match.';
    } else {
        try {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Run schema.sql (strip -- comments, split on semicolons)
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            if ($sql === false) {
                throw new RuntimeException('schema.sql not found.');
            }
            $sql = preg_replace('/--[^\n]*(\n|$)/', "\n", $sql);
            foreach (explode(';', (string)$sql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }

            // Seed business settings
            $seed = [
                'business_name'    => 'AutoCode LLC',
                'business_address' => '4240 E Lake Mead Blvd, Suite 110, Las Vegas, NV 89115',
                'business_phone'   => '(702) 608-0202',
                'tax_rate'         => '0',
                'warranty_policies' =>
                    "WARRANTY & POLICIES\n\n" .
                    "All repairs performed by AutoCode LLC are warranted against defects in workmanship " .
                    "for 90 days or 3,000 miles, whichever comes first, unless otherwise stated in writing.\n\n" .
                    "This warranty covers the specific repair described on this invoice only. It does not " .
                    "cover unrelated failures, normal wear, abuse, accidents, or alterations made by third parties.\n\n" .
                    "Diagnostic fees are non-refundable once the diagnostic work has been performed.\n\n" .
                    "Programming and module services depend on the condition of the vehicle's wiring and " .
                    "network; pre-existing electrical faults may require additional authorized repairs.\n\n" .
                    "Payment is due upon completion unless other terms were agreed in writing.",
            ];
            $ins = $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($seed as $k => $v) {
                $ins->execute([$k, $v]);
            }

            // Admin user
            $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $chk->execute([$username]);
            if ($chk->fetch()) {
                throw new RuntimeException('That username already exists.');
            }
            $add = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $add->execute([$username, password_hash($pw1, PASSWORD_DEFAULT)]);

            // Ensure upload dir exists
            $sigdir = __DIR__ . '/uploads/signatures';
            if (!is_dir($sigdir)) {
                mkdir($sigdir, 0750, true);
            }

            file_put_contents($lock, 'installed ' . date('c'));
            $done = true;
        } catch (Throwable $t) {
            $err = 'Install failed: ' . $t->getMessage();
        }
    } // end validation else
    } // end CSRF else
} // end POST handler
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Install — AutoCode Invoices</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="wrap">
  <div class="login-wrap">
    <div class="login-card">
      <div class="brand"><span class="brand-mark">AC</span><span class="brand-name">AutoCode</span> <span class="brand-sub">Invoices</span></div>
      <h1>Install</h1>
      <?php if ($done): ?>
        <div class="flash ok">✅ Installed successfully.<br><br>
          <strong>Now DELETE <code>install.php</code> from the server</strong> (it is locked, but remove it anyway).</div>
        <p><a class="btn big" href="login.php">Go to login</a></p>
      <?php else: ?>
        <?php if ($err !== ''): ?><div class="login-err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <p class="hint">This creates the database tables and your admin user. It runs only once.</p>
        <form method="post" action="install.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
          <label for="username">Admin username</label>
          <input type="text" id="username" name="username" value="admin" required>
          <label for="password">Password (min. 8 characters)</label>
          <input type="password" id="password" name="password" required autocomplete="new-password">
          <label for="password2">Confirm password</label>
          <input type="password" id="password2" name="password2" required autocomplete="new-password">
          <div class="actions"><button class="btn big" type="submit">Install</button></div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
