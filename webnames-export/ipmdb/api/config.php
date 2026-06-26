<?php
// IPMdb configuration template.
// Copy this file to config.local.php on Webnames and fill real values there.
// Do not commit config.local.php.

return [
    'db' => [
        'dsn' => getenv('IPMDB_DB_DSN') ?: 'mysql:host=localhost;dbname=ipmdb;charset=utf8mb4',
        'user' => getenv('IPMDB_DB_USER') ?: 'CHANGE_ME',
        'pass' => getenv('IPMDB_DB_PASS') ?: 'CHANGE_ME',
    ],
    'mail' => [
        'from' => getenv('IPMDB_MAIL_FROM') ?: 'no-reply@ajfisherco.com',
        'to' => getenv('IPMDB_MAIL_TO') ?: 'ajfisherco@gmail.com',
    ],
    'app' => [
        'asset_prefix' => 'IPM',
    ],
];
