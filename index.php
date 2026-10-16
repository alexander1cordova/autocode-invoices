<?php
// AutoCode Invoices — clients: search + list + add.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'add_client') {
    csrf_check();
    $first = post('first_name');
    $last  = post('last_name');
    if ($first === '' || $last === '') {
        set_flash_err('First and last name are required.');
    } else {
        $st = db()->prepare(
            'INSERT INTO clients (first_name, last_name, phone, email, address, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$first, $last, post('phone'), post('email'), post('address'), post('notes')]);
        $id = (int)db()->lastInsertId();
        set_flash('Client added.');
        redirect('client.php?id=' . $id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'create_intake_link') {
    csrf_check();
    $link = create_intake_link(post('label'));
    redirect('index.php?show_intake=' . (int)$link['id'] . '#intake');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'delete_intake_link') {
    csrf_check();
    delete_intake_link((int)post('link_id'));
    set_flash('Intake link deleted.');
    redirect('index.php#intake');
}

$show_intake = get_intake_link((int)get_param('show_intake')) ?: null;

$q = get_param('q');
$clients = [];
if ($q !== '') {
    $st = db()->prepare(
        'SELECT * FROM clients
         WHERE first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ? OR phone LIKE ?
         ORDER BY last_name, first_name LIMIT 100'
    );
    $like = '%' . $q . '%';
    $st->execute([$like, $like, $like, $like]);
    $clients = $st->fetchAll();
}

page_header('Clients', 'clients');
?>
<h1>Clients</h1>

<div class="grid2">
  <div class="card">
    <h2>Find client</h2>
    <form method="get" action="index.php">
      <div class="searchbar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name or phone…" autocomplete="off">
        <button class="btn" type="submit">Search</button>
      </div>
    </form>
    <div class="table-wrap" style="margin-top:12px">
    <table class="data">
      <thead><tr><th>Name</th><th>Phone</th><th></th></tr></thead>
      <tbody>
      <?php if (!$clients): ?>
        <tr><td colspan="3" style="text-align:center;color:var(--muted)"><?= $q === '' ? 'Search for a client to see results.' : 'No clients found.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><strong><?= e(client_name($c)) ?></strong></td>
          <td><?= e($c['phone']) ?></td>
          <td><a class="rowlink" href="client.php?id=<?= (int)$c['id'] ?>">Open →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="card">
    <h2>New client</h2>
    <form method="post" action="index.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="add_client">
      <div class="form-row">
        <div><label for="first_name">First name *</label><input type="text" id="first_name" name="first_name" required></div>
        <div><label for="last_name">Last name *</label><input type="text" id="last_name" name="last_name" required></div>
      </div>
      <div class="form-row">
        <div><label for="phone">Phone</label><input type="tel" id="phone" name="phone"></div>
        <div><label for="email">Email</label><input type="email" id="email" name="email"></div>
      </div>
      <label for="address">Address</label>
      <input type="text" id="address" name="address">
      <label for="notes">Notes</label>
      <textarea id="notes" name="notes" style="min-height:70px"></textarea>
      <div class="actions"><button class="btn" type="submit">Add client</button></div>
    </form>
  </div>
</div>

<div class="card" id="intake">
  <h2>📲 New Customer Info link</h2>
  <p class="hint">Create a single-use link and text it to your client. They fill in their info, vehicle (VIN required) and the problem — it lands in your system as a new client + job. Each link works <strong>once</strong>: after they submit, reopening it only shows the thank-you page.</p>
  <?php if ($show_intake): ?>
    <div class="sharebox">
      <h3>Send this link to your client<?= $show_intake['label'] !== '' ? ' — ' . e($show_intake['label']) : '' ?></h3>
      <div class="sharelink-row">
        <input type="text" readonly id="intake-link-url" value="<?= e(intake_link_url($show_intake['token'])) ?>" onclick="this.select()">
        <button class="btn small" type="button" data-copy="intake-link-url">Copy</button>
      </div>
      <p class="hint">Single-use. Expires once the client submits.</p>
    </div>
  <?php endif; ?>
  <form method="post" action="index.php#intake" style="margin-bottom:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="create_intake_link">
    <div class="searchbar">
      <input type="text" name="label" placeholder="Note for you (optional): who is this for?…" autocomplete="off">
      <button class="btn navy" type="submit">Create link</button>
    </div>
  </form>
  <?php $intake_links = list_intake_links(10); ?>
  <?php if ($intake_links): ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr><th>Created</th><th>Note</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($intake_links as $il): ?>
      <tr>
        <td><?= e(fmt_day($il['created_at'])) ?></td>
        <td><?= e($il['label'] !== '' ? $il['label'] : '—') ?></td>
        <td><?= $il['consumed_at']
              ? '<span class="badge" style="background:var(--ok)">Used</span> ' . e($il['client_name'] ?? '')
              : '<span class="badge" style="background:var(--warn)">Pending</span>' ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if (!$il['consumed_at']): ?>
            <button class="btn small ghost" type="button" data-copy="intake-url-<?= (int)$il['id'] ?>">Copy link</button>
            <input type="hidden" id="intake-url-<?= (int)$il['id'] ?>" value="<?= e(intake_link_url($il['token'])) ?>">
          <?php endif; ?>
          <form method="post" action="index.php#intake" style="display:inline"
                onsubmit="return confirm('Delete this intake link?')">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="delete_intake_link">
            <input type="hidden" name="link_id" value="<?= (int)$il['id'] ?>">
            <button class="btn small danger" type="submit">✕</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php page_footer(); ?>
