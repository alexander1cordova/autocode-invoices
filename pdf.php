<?php
// AutoCode Invoices — download the PDF of a finalized document.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/lib/pdfgen.php';
require_login();

$job_id = (int)get_param('job');
$stage  = get_param('stage');
if (!in_array($stage, ['estimate', 'authorization', 'invoice'], true)) {
    http_response_code(400);
    exit('Invalid stage.');
}

$row = get_snapshot($job_id, $stage);
if (!$row) {
    http_response_code(404);
    exit('This document has not been finalized yet — no PDF is available.');
}
$data = json_decode((string)$row['snapshot_json'], true);
if (!is_array($data)) {
    http_response_code(500);
    exit('Could not read the saved document.');
}
// Client/vehicle/business info is always live; lines stay as finalized.
// (A signed authorization renders exactly as signed.)
if (in_array($stage, ['estimate', 'invoice'], true)) {
    $data = apply_live_profile($data, $job_id);
}
// Payment info is live too (method/notes set by the admin, even after finalizing).
if ($stage === 'invoice') {
    $pay = job_payment($job_id);
    $data['payment'] = [
        'method' => payment_method_label($pay['method']),
        'notes'  => $pay['notes'],
    ];
    // Diagnosis result is live too; it only renders if the admin wrote something.
    $data['diagnosis'] = job_diagnosis($job_id);
    // Warranty & Policies are live on the invoice as well: the current text
    // from Settings always prints, even on invoices finalized before it
    // was written. (A signed authorization keeps rendering exactly as signed.)
    $data['warranty'] = business_info()['warranty'];
}

$pdf = null;
try {
    $pdf = job_pdf($data);
} catch (Throwable $e) {
    error_log('PDF generation failed for job ' . $job_id . ' stage ' . $stage . ': ' . $e->getMessage());
    http_response_code(500);
    exit('Could not generate the PDF for this document. The error has been logged.');
}
$filename = sprintf('autocode-%s-job%d.pdf', $stage, $job_id);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
