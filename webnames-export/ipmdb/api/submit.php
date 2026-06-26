<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/mail.php';

$data = ipmdb_input();
$title = ipmdb_clean((string)($data['title'] ?? ''), 120);
$email = ipmdb_clean((string)($data['email'] ?? ''), 255);
$source = ipmdb_clean((string)($data['source'] ?? ''), 120);
$description = ipmdb_clean((string)($data['description'] ?? ''), 5000);

if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ipmdb_json(['ok' => false, 'error' => 'Title and valid email are required.'], 422);
}

$assetId = 'IPM-' . date('Ymd') . '-' . random_int(100000, 999999);
$record = [
    'asset_id' => $assetId,
    'title' => $title,
    'email' => $email,
    'source' => $source,
    'description' => $description,
    'created_at' => date(DATE_ATOM)
];

$mailSent = ipmdb_send_acknowledgement($record);

ipmdb_json([
    'ok' => true,
    'asset_id' => $assetId,
    'mail_sent' => $mailSent,
    'note' => 'Database persistence pending.'
]);
