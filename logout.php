<?php
// AutoCode Invoices — logout.
require_once __DIR__ . '/includes/functions.php';
logout_user();
redirect('login.php');
