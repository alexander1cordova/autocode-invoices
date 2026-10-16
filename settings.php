<?php
// AutoCode Invoices — settings: business info, warranty & policies, password.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'shop') {
    csrf_check();
    setting_set('business_name', post('business_name'));
    setting_set('business_address', post('business_address'));
    setting_set('business_phone', post('business_phone'));
    $tax = (float)post('tax_rate', '0');
    if ($tax < 0 || $tax > 100) {
        set_flash_err('Tax rate must be between 0 and 100.');
    } else {
        setting_set('tax_rate', (string)$tax);
    }
    setting_set('warranty_policies', trim((string)($_POST['warranty_policies'] ?? '')));
    $notify_email = trim(post('notify_email'));
    if ($notify_email === '' || filter_var($notify_email, FILTER_VALIDATE_EMAIL)) {
        setting_set('notify_email', $notify_email);
    } else {
        set_flash_err('Notification email is not a valid email address.');
    }
    set_flash('Business settings saved.');
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'password') {
    csrf_check();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $row = $st->fetch();

    if (!$row || !password_verify($current, (string)$row['password_hash'])) {
        set_flash_err('Current password is incorrect.');
    } elseif (strlen($new) < 8) {
        set_flash_err('New password must be at least 8 characters.');
    } elseif ($new !== $confirm) {
        set_flash_err('New passwords do not match.');
    } else {
        $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        set_flash('Password changed.');
    }
    redirect('settings.php');
}

$biz = business_info();

page_header('Settings', 'settings');
?>
<h1>Settings</h1>

<div class="grid2">
  <div class="card">
    <h2>Account</h2>
    <p>Signed in as <strong><?= e($user['username'] ?? '') ?></strong>.</p>
    <div class="actions"><a class="btn" href="logout.php">Log out</a></div>
  </div>

  <div class="card">
    <h2>Business info</h2>
    <p class="hint">Shown on every document and PDF.</p>
    <form method="post" action="settings.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="shop">
      <label for="business_name">Business name</label>
      <input type="text" id="business_name" name="business_name" value="<?= e($biz['name']) ?>" required>
      <label for="business_address">Address</label>
      <input type="text" id="business_address" name="business_address" value="<?= e($biz['address']) ?>">
      <label for="business_phone">Phone</label>
      <input type="tel" id="business_phone" name="business_phone" value="<?= e($biz['phone']) ?>">
      <label for="tax_rate">Tax rate (%)</label>
      <input type="number" id="tax_rate" name="tax_rate" step="0.01" min="0" max="100" value="<?= e((string)$biz['tax_rate']) ?>">
      <label for="notify_email">Notification email</label>
      <input type="email" id="notify_email" name="notify_email" value="<?= e(setting_get('notify_email', 'you@example.com')) ?>">
      <p class="hint">Automatic alerts (new client registrations, signed authorizations) are sent here.</p>
      <div class="actions"><button class="btn" type="submit">Save</button></div>
    </form>
  </div>

  <div class="card">
    <h2>Change password</h2>
    <form method="post" action="settings.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="password">
      <label for="current_password">Current password</label>
      <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
      <label for="new_password">New password (min. 8 characters)</label>
      <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
      <label for="confirm_password">Confirm new password</label>
      <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
      <div class="actions"><button class="btn navy" type="submit">Change password</button></div>
    </form>
  </div>
</div>

<div class="card">
  <h2>Warranty &amp; Policies</h2>
  <p class="hint">Printed on every Final Invoice and its PDF. Write it in English.</p>
  <form method="post" action="settings.php">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="shop">
    <input type="hidden" name="business_name" value="<?= e($biz['name']) ?>">
    <input type="hidden" name="business_address" value="<?= e($biz['address']) ?>">
    <input type="hidden" name="business_phone" value="<?= e($biz['phone']) ?>">
    <input type="hidden" name="tax_rate" value="<?= e((string)$biz['tax_rate']) ?>">
    <input type="hidden" name="notify_email" value="<?= e(setting_get('notify_email', 'you@example.com')) ?>">
    <textarea id="warranty_policies" name="warranty_policies" style="min-height:220px"><?= e($biz['warranty']) ?></textarea>
    <div class="actions"><button class="btn" type="submit">Save Warranty &amp; Policies</button></div>
  </form>
</div>

<?php page_footer(); ?>
