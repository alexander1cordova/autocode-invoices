<?php
// ============================================================
// AutoCode Invoices — QA local de PDFs (verificación #1).
//
// Úsalo ANTES de subir cualquier cambio a lib/pdfgen.php:
//   php ~/workspace/tools/pdf-qa/qa_pdfs.php
//
// Genera PDFs sintéticos de los 3 tipos de documento (con firma
// RGBA como la del iPhone, warranty, casos borde) y falla si
// alguno no se genera. Si esto está en verde, se puede subir.
// La verificación #2 es healthcheck.php en el servidor.
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', '1');

$APP = '/home/hatch/workspace/invoices-app';
require $APP . '/lib/pdfgen.php';

$failures = 0;
function qa(string $label, callable $fn): void
{
    global $failures;
    try {
        $pdf = $fn();
        if (strlen($pdf) < 500 || substr($pdf, 0, 5) !== '%PDF-') {
            throw new RuntimeException('PDF inválido (vacío o corrupto).');
        }
        $pages = preg_match_all('#/Type\s*/Page[^s]#', $pdf);
        echo "  ✓ $label (" . strlen($pdf) . " bytes, $pages pág.)\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  ✕ $label — FALLO: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function make_sig_png(string $path): void
{
    $im = imagecreatetruecolor(600, 200);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
    $ink = imagecolorallocatealpha($im, 20, 20, 120, 0);
    imagesetthickness($im, 4);
    for ($x = 30; $x < 570; $x += 8) {
        imagefilledellipse($im, $x, 100 + (int)(60 * sin($x / 40)), 6, 6, $ink);
    }
    imagepng($im, $path);
    imagedestroy($im);
}

function snap(string $stage, array $over = []): array
{
    return array_merge([
        'stage' => $stage,
        'title' => ['estimate' => 'Estimate', 'authorization' => 'Repair Authorization', 'invoice' => 'Final Invoice'][$stage],
        'job' => ['id' => 1, 'problem_title' => 'No arranca', 'status' => 'x', 'created_at' => '2026-09-26 10:00:00'],
        'client' => ['name' => 'Miguel Cordova', 'phone' => '(702) 608-0202', 'email' => 'a@b.com', 'address' => 'Las Vegas, NV'],
        'vehicle' => ['label' => 't', 'year' => '2020', 'make' => 'Ford', 'model' => 'F-150', 'vin' => '1FTX', 'plate' => 'ABC1', 'mileage' => '80000', 'color' => 'Azul'],
        'lines' => [
            ['kind' => 'service', 'description' => 'Diagnóstico electrónico completo', 'qty' => 1, 'unit_price' => 250, 'line_total' => 250, 'stage_added' => 'estimate'],
        ],
        'totals' => ['subtotal' => 250, 'tax' => 0, 'total' => 250],
        'tax_rate' => 0,
        'business' => ['name' => 'AutoCode', 'address' => 'Las Vegas, NV', 'phone' => '(702) 608-0202'],
        'warranty' => "WARRANTY & POLICIES\nTodo garantizado 90 días.",
        'signature' => in_array($stage, ['authorization', 'invoice'], true)
            ? ['signer_name' => 'Miguel Cordova', 'image_path' => 'uploads/signatures/qa_sig.png', 'signed_at' => '2026-09-26 11:00:00']
            : null,
        'finalized_at' => '2026-09-26 11:05:00',
    ], $over);
}

@mkdir($APP . '/uploads/signatures', 0750, true);
make_sig_png($APP . '/uploads/signatures/qa_sig.png');

echo "QA de PDFs — AutoCode Invoices\n";

echo "Documentos básicos:\n";
qa('estimate sin firma', fn() => job_pdf(snap('estimate')));
qa('authorization con firma', fn() => job_pdf(snap('authorization')));
qa('invoice con firma', fn() => job_pdf(snap('invoice')));

echo "Casos borde:\n";
$many = snap('invoice');
for ($i = 1; $i <= 25; $i++) {
    $many['lines'][] = ['kind' => 'service', 'description' => "Servicio $i con descripción larga para ocupar espacio en la tabla", 'qty' => 1, 'unit_price' => 50 + $i, 'line_total' => 50 + $i, 'stage_added' => 'estimate'];
}
$many['warranty'] = str_repeat("Línea de póliza. ", 40);
qa('25 líneas + warranty larga + firma', fn() => job_pdf($many));

$nosig = snap('invoice');
$nosig['signature']['image_path'] = 'uploads/signatures/no_existe.png';
qa('firma con archivo faltante', fn() => job_pdf($nosig));

$min = snap('invoice', ['vehicle' => null, 'lines' => [], 'warranty' => '', 'totals' => ['subtotal' => 0, 'tax' => 0, 'total' => 0]]);
qa('mínimo: sin vehículo, sin líneas, sin warranty', fn() => job_pdf($min));

@unlink($APP . '/uploads/signatures/qa_sig.png');

echo $failures === 0 ? "\nTODO EN VERDE — se puede subir.\n" : "\n$failures FALLO(S) — NO subir.\n";
exit($failures === 0 ? 0 : 1);
