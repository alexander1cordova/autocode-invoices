<?php
// AutoCode Invoices — New Job wizard:
// 1) pick or create client → 2) pick or add vehicle → 3) describe the problem.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_login();

// Quick-invoice mode: same wizard, but the job is created straight in
// "invoiced" status, skipping estimate and authorization entirely.
$quick = get_param('quick') === '1';
$qs  = $quick ? '&quick=1' : ''; // append to query strings
$qsa = $quick ? '?quick=1' : ''; // append to form actions

/* ---------- POST handlers ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'create_client') {
    csrf_check();
    $first = post('first_name');
    $last  = post('last_name');
    if ($first === '' || $last === '') {
        set_flash_err('First and last name are required.');
        redirect('new_job.php' . $qsa);
    }
    $st = db()->prepare(
        'INSERT INTO clients (first_name, last_name, phone, email, address, notes) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$first, $last, post('phone'), post('email'), post('address'), post('notes')]);
    redirect('new_job.php?client_id=' . (int)db()->lastInsertId() . $qs);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'add_vehicle') {
    csrf_check();
    $client_id = (int)post('client_id');
    if (!$client_id || !get_client($client_id)) {
        redirect('new_job.php' . $qsa);
    }
    $st = db()->prepare(
        'INSERT INTO vehicles (client_id, year, make, model, vin, plate, mileage, color)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $client_id, post('year'), post('make'), post('model'),
        post('vin'), post('plate'), post('mileage'), post('color'),
    ]);
    redirect('new_job.php?client_id=' . $client_id . '&vehicle_id=' . (int)db()->lastInsertId() . $qs);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'create_job') {
    csrf_check();
    $client_id  = (int)post('client_id');
    $vehicle_id = (int)post('vehicle_id');
    $problem    = post('problem_title');
    if (!$client_id || !get_client($client_id)) {
        set_flash_err('Please pick a client first.');
        redirect('new_job.php' . $qsa);
    }
    if ($vehicle_id && !get_vehicle($vehicle_id)) {
        $vehicle_id = 0;
    }
    if ($problem === '') {
        set_flash_err('Please describe the problem or requested work.');
        redirect('new_job.php?client_id=' . $client_id . ($vehicle_id ? '&vehicle_id=' . $vehicle_id : '') . $qs);
    }
    $new_status = $quick ? 'invoiced' : 'estimate';
    $st = db()->prepare(
        'INSERT INTO jobs (client_id, vehicle_id, problem_title, status) VALUES (?, ?, ?, ?)'
    );
    $st->execute([$client_id, $vehicle_id ?: null, $problem, $new_status]);
    $job_id = (int)db()->lastInsertId();
    touch_client($client_id);
    touch_job($job_id);
    if ($quick) {
        set_flash('Job #' . $job_id . ' created. Add the invoice lines below.');
        redirect('job.php?id=' . $job_id . '&tab=invoice');
    }
    set_flash('Job #' . $job_id . ' created. Add the estimate lines below.');
    redirect('job.php?id=' . $job_id . '&tab=estimate');
}

/* ---------- Step detection ---------- */

$client_id  = (int)get_param('client_id');
$vehicle_id = (int)get_param('vehicle_id');
$client  = $client_id ? get_client($client_id) : null;
$vehicle = $vehicle_id ? get_vehicle($vehicle_id) : null;

$step = 1;
if ($client) {
    $step = 2;
}
if ($client && ($vehicle || get_param('vehicle_id') === '0')) {
    $step = 3;
}
// explicit "no vehicle" choice
if ($client && get_param('novehicle') === '1') {
    $step = 3;
    $vehicle = null;
    $vehicle_id = 0;
}

page_header($quick ? 'New Quick Invoice' : 'New Job', 'newjob');
?>
<h1><?= $quick ? 'New Quick Invoice' : 'New Job' ?></h1>
<?php if ($quick): ?>
<p class="hint">Quick invoice: goes straight to the invoice — no estimate, no authorization.</p>
<?php endif; ?>
<p class="hint">Step <?= $step ?> of 3: <?= ['1' => 'Client', '2' => 'Vehicle', '3' => $quick ? 'Work done' : 'Problem'][$step] ?></p>

<?php if ($step === 1): ?>
  <?php
  $q = get_param('q');
  $found = [];
  if ($q !== '') {
      $st = db()->prepare(
          'SELECT * FROM clients
           WHERE first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name," ",last_name) LIKE ? OR phone LIKE ?
           ORDER BY last_name, first_name LIMIT 20'
      );
      $like = '%' . $q . '%';
      $st->execute([$like, $like, $like, $like]);
      $found = $st->fetchAll();
  }
  ?>
  <div class="grid2">
    <div class="card">
      <h2>1 · Find existing client</h2>
      <form method="get" action="new_job.php<?= $qsa ?>">
        <div class="searchbar">
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name or phone…" autocomplete="off">
          <button class="btn" type="submit">Search</button>
        </div>
      </form>
      <?php if ($q !== ''): ?>
        <?php if (!$found): ?>
          <p class="hint">No matches — create the client on the right.</p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:10px">
          <table class="data">
            <tbody>
            <?php foreach ($found as $c): ?>
              <tr>
                <td><strong><?= e(client_name($c)) ?></strong><br><span class="hint"><?= e($c['phone']) ?></span></td>
                <td style="text-align:right"><a class="btn small navy" href="new_job.php?client_id=<?= (int)$c['id'] ?><?= $qs ?>">Select</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>…or add a new client</h2>
      <form method="post" action="new_job.php<?= $qsa ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="create_client">
        <div class="form-row">
          <div><label>First name *</label><input type="text" name="first_name" required></div>
          <div><label>Last name *</label><input type="text" name="last_name" required></div>
        </div>
        <div class="form-row">
          <div><label>Phone</label><input type="tel" name="phone"></div>
          <div><label>Email</label><input type="email" name="email"></div>
        </div>
        <label>Address</label>
        <input type="text" name="address">
        <div class="actions"><button class="btn" type="submit">Continue →</button></div>
      </form>
    </div>
  </div>

<?php elseif ($step === 2): ?>
  <div class="card">
    <h2>Client: <?= e(client_name($client)) ?></h2>
    <p class="hint"><?= e($client['phone']) ?></p>
  </div>
  <div class="grid2">
    <div class="card">
      <h2>2 · Pick a vehicle</h2>
      <?php $vehicles = client_vehicles($client_id); ?>
      <?php if (!$vehicles): ?>
        <p class="hint">No vehicles on file — add one on the right.</p>
      <?php else: ?>
        <?php foreach ($vehicles as $v): ?>
          <p><strong><?= e(vehicle_label($v)) ?></strong><br>
          <span class="hint">VIN <?= e($v['vin'] ?: '—') ?> · <?= e($v['mileage'] ?: '') ?></span><br>
          <a class="btn small navy" href="new_job.php?client_id=<?= $client_id ?>&vehicle_id=<?= (int)$v['id'] ?><?= $qs ?>">Select</a></p>
        <?php endforeach; ?>
      <?php endif; ?>
      <p><a href="new_job.php?client_id=<?= $client_id ?>&novehicle=1<?= $qs ?>">Continue without a vehicle →</a></p>
    </div>
    <div class="card">
      <h2>…or add a vehicle</h2>
      <form method="post" action="new_job.php<?= $qsa ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="add_vehicle">
        <input type="hidden" name="client_id" value="<?= $client_id ?>">
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
        <div class="actions"><button class="btn" type="submit">Continue →</button></div>
      </form>
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <h2>3 · <?= $quick ? 'Describe the work done' : 'Describe the problem' ?></h2>
    <p><strong>Client:</strong> <?= e(client_name($client)) ?><br>
    <strong>Vehicle:</strong> <?= e(vehicle_label($vehicle)) ?></p>
    <form method="post" action="new_job.php<?= $qsa ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="create_job">
      <input type="hidden" name="client_id" value="<?= $client_id ?>">
      <input type="hidden" name="vehicle_id" value="<?= $vehicle_id ?>">
      <label for="problem_title"><?= $quick ? 'Work performed *' : 'Problem / requested work *' ?></label>
      <textarea id="problem_title" name="problem_title" required placeholder="<?= $quick ? 'e.g. Programmed replacement PCM, performed key learning, cleared codes…' : 'e.g. No-start after collision — diagnose CAN communication, program replacement PCM…' ?>"></textarea>
      <div class="actions">
        <a class="btn ghost" href="new_job.php?client_id=<?= $client_id ?><?= $qs ?>">← Back</a>
        <button class="btn green" type="submit"><?= $quick ? 'Create invoice directly' : 'Create job (Estimate)' ?></button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php page_footer(); ?>
