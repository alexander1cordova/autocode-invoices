<?php
// AutoCode Invoices — login.
require_once __DIR__ . '/includes/functions.php';

if (current_user()) {
    redirect('index.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        $user = $st->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $upd->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            login_user($user);
            redirect(safe_next((string)($_GET['next'] ?? 'index.php')));
        }
    }
    // Generic message: do not reveal whether the username exists.
    $err = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Log in — AutoCode Invoices</title>
<link rel="stylesheet" href="assets/css/style.css?v=20250925b">
</head>
<body>
<div class="wrap">
  <div class="login-wrap">
    <div class="login-card">
      <div class="brand"><span class="brand-mark">AC</span><span class="brand-name">AutoCode</span> <span class="brand-sub">Invoices</span></div>
      <h1>Log in</h1>
      <?php if ($err !== ''): ?>
        <div class="login-err"><?= e($err) ?></div>
      <?php endif; ?>
      <form method="post" action="login.php<?= isset($_GET['next']) ? '?next=' . urlencode((string)$_GET['next']) : '' ?>" autocomplete="off">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username" autofocus>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <div class="actions">
          <button type="submit" class="btn big">Log in</button>
        </div>
      </form>
      <p class="hint" style="text-align:center">Private tool. Authorized access only.</p>
    </div>
  </div>
</div>
</body>
</html>
