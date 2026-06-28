<?php

declare(strict_types=1);

function ipmdb_send_ack(array $config, string $email, string $assetId, string $title): bool
{
    $fromEmail = $config['app']['mail_from'] ?? 'noreply@ajfisherco.com';
    $fromName = $config['app']['mail_name'] ?? 'IPMdb';
    $subject = 'IPMdb Asset ID: ' . $assetId;
    $body = "Hello,\n\nYour idea has been received by IPMdb.\n\nAsset ID: {$assetId}\nIdea Title: {$title}\n\nThis acknowledgement confirms receipt and timestamping of your submission.\n\n{$fromName}\n";
    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . ($config['app']['admin_email'] ?? $fromEmail);
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    return mail($email, $subject, $body, implode("\r\n", $headers));
}
