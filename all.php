<?php
// AutoCode Invoices — All clients: full list with phone + date of the latest
// document across each profile's jobs (invoice > signed authorization >
// finalized estimate), sortable alphabetically by name or by latest date.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

clients_updated_at_migrate();

$sort = get_param('sort') === 'name' ? 'name' : 'activity';
$order = $sort === 'name'
    ? 'ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC'
    : 'ORDER BY last_date DESC, c.id DESC';

$st = db()->prepare(
    "SELECT c.id, c.first_name, c.last_name, c.phone,
            MAX(COALESCE(s.latest_stage_at, j.created_at)) AS last_date
     FROM clients c
     LEFT JOIN jobs j ON j.client_id = c.id
     LEFT JOIN (SELECT job_id, MAX(finalized_at) AS latest_stage_at
                FROM job_snapshots GROUP BY job_id) s ON s.job_id = j.id
     GROUP BY c.id $order"
);
$st->execute();
$clients = $st->fetchAll();

page_header('All Clients', 'all');
?>
<div class="page-head">
  <h1>All Clients (<?= count($clients) ?>)</h1>
  <div>
    <span class="hint">Sort by:</span>
    <a class="btn <?= $sort === 'activity' ? 'navy' : 'ghost' ?>" href="all.php?sort=activity">Last service</a>
    <a class="btn <?= $sort === 'name' ? 'navy' : 'ghost' ?>" href="all.php?sort=name">Name A–Z</a>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data wide">
      <thead>
        <tr><th>Name</th><th>Phone</th><th>Last service</th><th></th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$clients): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--muted)">No clients yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td><strong><?= e(client_name($c)) ?></strong></td>
            <td><?= e($c['phone'] !== '' ? $c['phone'] : '—') ?></td>
            <td><?= $c['last_date'] ? e(fmt_day($c['last_date'])) : '—' ?></td>
            <td><a class="rowlink" href="client.php?id=<?= (int)$c['id'] ?>">Open →</a></td>
            <td><a class="btn small" href="new_job.php?client_id=<?= (int)$c['id'] ?>">+ New job</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php page_footer(); ?>
