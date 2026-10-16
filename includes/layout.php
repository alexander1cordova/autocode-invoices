<?php
// AutoCode Invoices — page layout (header / footer / flash messages).

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

function page_header(string $title, string $active = ''): void
{
    $nav = static function (string $key, string $href, string $label) use ($active): string {
        $cls = $active === $key ? 'on' : '';
        return '<a class="' . $cls . '" href="' . $href . '">' . e($label) . '</a>';
    };
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — AutoCode Invoices</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261007">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php"><span class="brand-mark">AC</span><span class="brand-name">AutoCode</span> <span class="brand-sub">Invoices</span></a>
    <nav class="nav">
      <?= $nav('clients', 'index.php', 'Clients') ?>
      <?= $nav('dashboard', 'jobs.php', 'History') ?>
      <?= $nav('all', 'all.php', 'All') ?>
      <?= $nav('settings', 'settings.php', 'Settings') ?>
    </nav>
  </div>
</header>
<main class="wrap">
    <?php
    flash();
}

function page_footer(): void
{
    ?>
</main>
<footer class="foot">AutoCode Invoices — private business tool</footer>
<script src="assets/js/app.js?v=20261003"></script>
</body>
</html>
    <?php
}

function flash(): void
{
    if (!empty($_SESSION['flash'])) {
        echo '<div class="flash ok">' . e((string)$_SESSION['flash']) . '</div>';
        unset($_SESSION['flash']);
    }
    if (!empty($_SESSION['flash_err'])) {
        echo '<div class="flash err">' . e((string)$_SESSION['flash_err']) . '</div>';
        unset($_SESSION['flash_err']);
    }
}
