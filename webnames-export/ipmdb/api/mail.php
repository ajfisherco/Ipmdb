<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function ipmdb_send_acknowledgement(array $record): bool
{
    $config = ipmdb_config()['mail'];
    $to = $record['email'] ?? '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'IPMdb Asset Locked: ' . ($record['asset_id'] ?? 'pending');
    $body = implode("\n", [
        'Hello,',
        '',
        'Your idea has been received by IPMdb.',
        'Asset ID: ' . ($record['asset_id'] ?? ''),
        'Title: ' . ($record['title'] ?? ''),
        '',
        'AJF&Co. / IPMdb'
    ]);

    $headers = 'From: ' . $config['from'];
    return mail($to, $subject, $body, $headers);
}
