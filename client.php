<?php
// AutoCode Invoices — client profile: data, vehicles, FULL job history.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

$client_id = (int)get_param('id');
$client = $client_id ? get_client($client_id) : null;
if (!$client) {
    http_response_code(404);
    exit('Client not found.');
}
clients_id_image_migrate();
$client = get_client($client_id); // re-fetch with id_image column present

// Update client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'edit_client') {
    csrf_check();
    $st = db()->prepare(
        'UPDATE clients SET first_name=?, last_name=?, phone=?, email=?, address=?, notes=? WHERE id=?'
    );
    $st->execute([post('first_name'), post('last_name'), post('phone'), post('email'), post('address'), post('notes'), $client_id]);
    touch_client($client_id);
    set_flash('Client updated.');
    redirect('client.php?id=' . $client_id);
}

// Add vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'add_vehicle') {
    csrf_check();
    $st = db()->prepare(
        'INSERT INTO vehicles (client_id, year, make, model, vin, plate, mileage, color)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $client_id, post('year'), post('make'), post('model'),
        post('vin'), post('plate'), post('mileage'), post('color'),
    ]);
    touch_client($client_id);
    set_flash('Vehicle added.');
    redirect('client.php?id=' . $client_id);
}

// Delete vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'delete_vehicle') {
    csrf_check();
    $vid = (int)post('vehicle_id');
    $st = db()->prepare('SELECT id FROM vehicles WHERE id = ? AND client_id = ?');
    $st->execute([$vid, $client_id]);
    if (!$st->fetch()) {
        set_flash_err('Vehicle not found.');
    } else {
        delete_vehicle($vid);
        touch_client($client_id);
        set_flash('Vehicle deleted.');
    }
    redirect('client.php?id=' . $client_id);
}

// Delete this client (and everything under it)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'delete_client') {
    csrf_check();
    delete_client($client_id);
    set_flash('Client deleted.');
    redirect('index.php');
}

// Upload/replace the client's ID image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'upload_id_image') {
    csrf_check();
    try {
        save_client_id_image($client_id, $_FILES['id_image'] ?? []);
        set_flash('ID image saved.');
    } catch (RuntimeException $ex) {
        set_flash_err('Could not save the ID image: ' . $ex->getMessage());
    }
    redirect('client.php?id=' . $client_id);
}

// Delete the client's ID image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'delete_id_image') {
    csrf_check();
    delete_client_id_image($client_id);
    set_flash('ID image removed.');
    redirect('client.php?id=' . $client_id);
}

$vehicles = client_vehicles($client_id);

// Full job history for this client (every visit), newest first
$hj = db()->prepare(
    'SELECT j.*, v.year, v.make, v.model, v.plate, v.vin
     FROM jobs j
     LEFT JOIN vehicles v ON v.id = j.vehicle_id
     WHERE j.client_id = ?
     ORDER BY j.created_at DESC'
);
$hj->execute([$client_id]);
$jobs = $hj->fetchAll();

// Per-job totals + which stages are finalized
$job_ids = array_column($jobs, 'id');
$totals = [];
$finalized = []; // [job_id => [stage => finalized_at]]
if ($job_ids) {
    $in = implode(',', array_fill(0, count($job_ids), '?'));
    $lt = db()->prepare("SELECT job_id, SUM(qty * unit_price) AS t FROM job_lines WHERE job_id IN ($in) GROUP BY job_id");
    $lt->execute($job_ids);
    foreach ($lt->fetchAll() as $row) {
        $totals[(int)$row['job_id']] = (float)$row['t'];
    }
    $sn = db()->prepare("SELECT job_id, stage, finalized_at FROM job_snapshots WHERE job_id IN ($in)");
    $sn->execute($job_ids);
    foreach ($sn->fetchAll() as $row) {
        $finalized[(int)$row['job_id']][$row['stage']] = $row['finalized_at'];
    }
}

page_header(client_name($client), 'clients');
?>
<div class="page-head">
  <h1><?= e(client_name($client)) ?></h1>
  <div>
    <a class="btn" href="new_job.php?client_id=<?= $client_id ?>">+ New job for this client</a>
    <a class="btn ghost" href="new_job.php?client_id=<?= $client_id ?>&quick=1">⚡ Quick invoice</a>
    <form method="post" action="client.php?id=<?= $client_id ?>" style="display:inline" id="delclient-form">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="delete_client">
      <button class="btn danger" type="button" id="delclient-btn">Delete this client</button>
      <span id="delclient-confirm" style="display:none">
        <strong>Delete this client and EVERYTHING (vehicles, jobs, documents, photos, links)?</strong>
        <button class="btn danger" type="submit">Yes, delete</button>
        <button class="btn ghost" type="button" id="delclient-cancel">Cancel</button>
      </span>
    </form>
    <script>
    (function () {
      var b = document.getElementById('delclient-btn');
      var c = document.getElementById('delclient-confirm');
      var x = document.getElementById('delclient-cancel');
      if (!b || !c || !x) return;
      b.addEventListener('click', function () { b.style.display = 'none'; c.style.display = 'inline'; });
      x.addEventListener('click', function () { c.style.display = 'none'; b.style.display = 'inline'; });
    })();
    </script>
  </div>
</div>

<div class="card">
  <h2>Job history — every visit (<?= count($jobs) ?>)</h2>
  <?php if (!$jobs): ?>
    <p class="hint">No jobs yet for this client. <a href="new_job.php?client_id=<?= $client_id ?>">Start a new job</a>.</p>
  <?php else: ?>
  <div class="table-wrap">
  <table class="data wide">
    <thead>
      <tr><th>Job</th><th>Date</th><th>Vehicle</th><th>Problem</th><th>Status</th><th class="num">Total</th><th>Documents</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $j):
        $jid = (int)$j['id'];
        $fin = $finalized[$jid] ?? [];
    ?>
      <tr>
        <td><strong>#<?= $jid ?></strong></td>
        <td><?= e(fmt_day($j['created_at'])) ?></td>
        <td><?= e(vehicle_label($j)) ?></td>
        <td><?= e(mb_strimwidth($j['problem_title'], 0, 50, '…')) ?></td>
        <td><?= status_badge($j['status']) ?></td>
        <td class="num"><?= money($totals[$jid] ?? 0) ?></td>
        <td>
          <div class="doc-links">
            <?php foreach (['estimate' => 'Estimate', 'authorization' => 'Authorization', 'invoice' => 'Invoice'] as $stg => $lbl): ?>
              <?php if (isset($fin[$stg])): ?>
                <a href="pdf.php?job=<?= $jid ?>&stage=<?= $stg ?>">📄 <?= $lbl ?></a>
              <?php else: ?>
                <span class="hint"><?= $lbl ?> —</span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </td>
        <td><a class="rowlink" href="job.php?id=<?= $jid ?>">Open →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<div class="grid2">
  <div class="card">
    <h2>Client info</h2>
    <form method="post" action="client.php?id=<?= $client_id ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="edit_client">
      <div class="form-row">
        <div><label>First name</label><input type="text" name="first_name" value="<?= e($client['first_name']) ?>" required></div>
        <div><label>Last name</label><input type="text" name="last_name" value="<?= e($client['last_name']) ?>" required></div>
      </div>
      <div class="form-row">
        <div><label>Phone</label><input type="tel" name="phone" value="<?= e($client['phone']) ?>"></div>
        <div><label>Email</label><input type="email" name="email" value="<?= e($client['email']) ?>"></div>
      </div>
      <label>Address</label>
      <input type="text" name="address" value="<?= e($client['address']) ?>">
      <label>Notes</label>
      <textarea name="notes" style="min-height:70px"><?= e($client['notes']) ?></textarea>
      <div class="actions"><button class="btn" type="submit">Save changes</button></div>
    </form>

    <h3 style="margin-top:20px">ID image</h3>
    <?php if (!empty($client['id_image'])): ?>
      <p><a href="photo.php?idimage=<?= $client_id ?>" target="_blank"><img src="photo.php?idimage=<?= $client_id ?>" alt="Client ID image" style="max-width:230px;border-radius:8px;border:1px solid #d5dde5;display:block"></a></p>
    <?php else: ?>
      <p class="hint">No ID image on file yet.</p>
    <?php endif; ?>
    <div class="photo-upload-row">
      <form method="post" action="client.php?id=<?= $client_id ?>" enctype="multipart/form-data" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="upload_id_image">
        <label class="btn small">📷 Take photo
          <input type="file" name="id_image" accept="image/*" capture="environment" hidden onchange="this.form.submit()">
        </label>
      </form>
      <form method="post" action="client.php?id=<?= $client_id ?>" enctype="multipart/form-data" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="upload_id_image">
        <label class="btn small secondary">🖼 Choose existing
          <input type="file" name="id_image" accept="image/*" hidden onchange="this.form.submit()">
        </label>
      </form>
      <?php if (!empty($client['id_image'])): ?>
      <form method="post" action="client.php?id=<?= $client_id ?>" style="display:inline" onsubmit="return confirm('Remove the ID image?');">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="delete_id_image">
        <button class="btn small danger" type="submit">Remove</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Vehicles</h2>
    <?php if (!$vehicles): ?>
      <p class="hint">No vehicles on file yet.</p>
    <?php endif; ?>
    <?php foreach ($vehicles as $v): ?>
      <p><strong><?= e(vehicle_label($v)) ?></strong>
        <form method="post" action="client.php?id=<?= $client_id ?>" style="display:inline;margin-left:8px"
              onsubmit="return confirm('Delete this vehicle? Jobs that used it will keep their history.');">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="delete_vehicle">
          <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
          <button class="btn small danger" type="submit">Delete</button>
        </form><br>
      <span class="hint">VIN: <?= e($v['vin'] ?: '—') ?> · Mileage: <?= e($v['mileage'] ?: '—') ?> · <?= e($v['color'] ?: '') ?></span></p>
    <?php endforeach; ?>
    <h3>Add vehicle</h3>
    <form method="post" action="client.php?id=<?= $client_id ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="add_vehicle">
      <div class="form-row cols3">
        <div><label>Year</label><input type="text" name="year" placeholder="2019"></div>
        <div><label>Make</label><input type="text" name="make" placeholder="Ford"></div>
        <div><label>Model</label><input type="text" name="model" placeholder="F-150"></div>
      </div>
      <div class="form-row cols3">
        <div><label>Plate</label><input type="text" name="plate"></div>
        <div><label>VIN</label><input type="text" name="vin"></div>
        <div><label>Mileage</label><input type="text" name="mileage"></div>
      </div>
      <label>Color</label>
      <input type="text" name="color">
      <div class="actions"><button class="btn navy" type="submit">Add vehicle</button></div>
    </form>
  </div>
</div>

<?php page_footer(); ?>
