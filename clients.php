<?php
// AutoCode Invoices — legacy route: Clients now lives at index.php.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: index.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
