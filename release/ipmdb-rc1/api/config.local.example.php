<?php

declare(strict_types=1);

return [
    'db' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=DATABASE_NAME;charset=utf8mb4',
        'user' => 'DATABASE_USER',
        'pass' => 'DATABASE_PASS',
    ],
    'app' => [
        'asset_prefix' => 'IPM',
        'mail_from' => 'noreply@ajfisherco.com',
        'mail_name' => 'IPMdb',
        'admin_email' => 'ajfisherco@gmail.com',
    ],
];
