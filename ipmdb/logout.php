<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Logout requires a POST request.');
}

ipmdb_require_csrf();
ipmdb_logout();

header('Location: /ipmdb/login.php');
exit;
