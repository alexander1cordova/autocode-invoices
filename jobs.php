<?php
// AutoCode Invoices — History: the 10 client profiles modified most recently.
// Click a name for quick access to that profile.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$clients = client_history(10);

page_header('History', 'dashboard');
?>
<h1>History</h1>
<p class="hint">The 10 client profiles you modified most recently. Click a name to open it.</p>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Client</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$clients): ?>
          <tr><td colspan="2" style="text-align:center;color:var(--muted)">No clients yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td><strong><?= e(client_name($c)) ?></strong></td>
            <td><a class="rowlink" href="client.php?id=<?= (int)$c['id'] ?>">Open →</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php page_footer(); ?>
