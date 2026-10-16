<?php
// ============================================================
// AutoCode Invoices — Árbitro de PDFs (verificación doble).
//
// Esta página regenera EN MEMORIA (sin descargar nada) el PDF de
// cada documento finalizado guardado y reporta cualquier fallo.
// Úsala después de cada cambio en el sistema: si algo se rompe,
// aquí aparece en rojo ANTES de que afecte a tus clientes.
//
// Acceso: solo con sesión iniciada (es tu herramienta privada).
// ============================================================
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/pdfgen.php';
require_login();

@set_time_limit(120);

$results = ['ok' => [], 'fail' => []];

function arbiter_check(string $label, array $snap): void
{
    global $results;
    try {
        $pdf = job_pdf($snap);
        if (strlen($pdf) < 500 || substr($pdf, 0, 5) !== '%PDF-') {
            throw new RuntimeException('PDF generado inválido (vacío o corrupto).');
        }
        $results['ok'][] = $label;
    } catch (Throwable $e) {
        $results['fail'][] = ['label' => $label, 'error' => $e->getMessage()];
    }
}

/** Snapshot sintético mínimo para pruebas que no dependen de datos reales. */
function arbiter_synthetic(string $stage, array $over = []): array
{
    $snap = [
        'stage' => $stage,
        'title' => stage_title($stage),
        'job' => ['id' => 0, 'problem_title' => 'Prueba del árbitro', 'status' => 'test', 'created_at' => date('Y-m-d H:i:s')],
        'client' => ['name' => 'Cliente Prueba', 'phone' => '(702) 555-0100', 'email' => '', 'address' => ''],
        'vehicle' => null,
        'lines' => [
            ['kind' => 'service', 'description' => 'Servicio de prueba', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100, 'stage_added' => 'estimate'],
        ],
        'totals' => ['subtotal' => 100, 'tax' => 0, 'total' => 100],
        'tax_rate' => 0,
        'business' => ['name' => 'AutoCode', 'address' => 'Las Vegas, NV', 'phone' => '(702) 608-0202'],
        'warranty' => "Póliza de prueba del árbitro.\nSegunda línea.",
        'signature' => in_array($stage, ['authorization', 'invoice'], true)
            ? ['signer_name' => 'Cliente Prueba', 'image_path' => 'uploads/signatures/no_existe.png', 'signed_at' => date('Y-m-d H:i:s')]
            : null,
        'finalized_at' => date('Y-m-d H:i:s'),
    ];
    return array_merge($snap, $over);
}

// ---- 1) Pruebas sintéticas (no tocan tus datos) ----
arbiter_check('Sintética: estimate sin firma', arbiter_synthetic('estimate'));
arbiter_check('Sintética: authorization con firma', arbiter_synthetic('authorization'));
arbiter_check('Sintética: invoice con firma', arbiter_synthetic('invoice'));

// ---- 2) Todos los documentos finalizados reales ----
$checked = 0;
try {
    $rows = db()->query('SELECT job_id, stage FROM job_snapshots ORDER BY job_id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $job_id = (int)$r['job_id'];
        $stage = (string)$r['stage'];
        $row = get_snapshot($job_id, $stage);
        if (!$row) {
            $results['fail'][] = ['label' => "Job #$job_id ($stage)", 'error' => 'Snapshot no encontrado en la base de datos.'];
            continue;
        }
        $data = json_decode((string)$row['snapshot_json'], true);
        if (!is_array($data)) {
            $results['fail'][] = ['label' => "Job #$job_id ($stage)", 'error' => 'Snapshot corrupto (JSON inválido).'];
            continue;
        }
        arbiter_check("Job #$job_id ($stage)", $data);
        $checked++;
    }
} catch (Throwable $e) {
    $results['fail'][] = ['label' => 'Lectura de snapshots', 'error' => $e->getMessage()];
}

$nOk = count($results['ok']);
$nFail = count($results['fail']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Árbitro de PDFs — AutoCode Invoices</title>
<style>
body{font-family:-apple-system,Helvetica,Arial,sans-serif;background:#f2f5f9;margin:0;color:#1c2b3a}
.wrap{max-width:760px;margin:0 auto;padding:20px}
.card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px}
h1{font-size:20px;color:#004070;margin:0 0 4px}
.sub{color:#69737d;font-size:13px;margin:0 0 12px}
.big{font-size:15px;font-weight:700;padding:10px 14px;border-radius:8px;margin-bottom:12px}
.okbox{background:#e6f6ec;color:#1a7a3c}
.failbox{background:#fdecec;color:#b3261e}
ul{margin:8px 0;padding-left:20px;font-size:14px}
li{margin:4px 0}
.err{color:#b3261e;font-size:13px}
.meta{font-size:12px;color:#69737d}
a{color:#40A0F0}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Árbitro de PDFs</h1>
    <p class="sub">Verificación doble después de cada cambio · <?= e(date('M j, Y g:i A')) ?> · PHP <?= e(PHP_VERSION) ?></p>
    <?php if ($nFail === 0): ?>
      <div class="big okbox">✓ Todo bien: <?= $nOk ?> documentos verificados, 0 fallos.</div>
    <?php else: ?>
      <div class="big failbox">✕ Atención: <?= $nFail ?> fallo(s) de <?= $nOk + $nFail ?> verificados.</div>
    <?php endif; ?>
    <p class="meta">Documentos reales revisados: <?= $checked ?> · Pruebas sintéticas: 3</p>
    <p><a href="index.php">← Volver a Jobs</a></p>
  </div>
  <?php if ($nFail > 0): ?>
  <div class="card">
    <h1>Fallos detectados</h1>
    <ul>
      <?php foreach ($results['fail'] as $f): ?>
        <li><strong><?= e($f['label']) ?></strong><br><span class="err"><?= e($f['error']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <div class="card">
    <h1>Documentos verificados</h1>
    <ul>
      <?php foreach ($results['ok'] as $label): ?>
        <li>✓ <?= e($label) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
</body>
</html>
