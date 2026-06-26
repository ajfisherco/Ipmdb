<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/assetid.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/record.php';

$data = ipmdb_input();
$title = ipmdb_clean((string)($data['title'] ?? ''), 120);
$email = ipmdb_clean((string)($data['email'] ?? ''), 255);
$source = ipmdb_clean((string)($data['source'] ?? ''), 120);
$description = ipmdb_clean((string)($data['description'] ?? ''), 5000);

if ($title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ipmdb_json(['ok' => false, 'error' => 'Title and valid email are required.'], 422);
}

try {
    $pdo = ipmdb_pdo();
    $assetId = ipmdb_next_asset_id($pdo);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO ideas (asset_id, title, originator_email, source, description) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$assetId, $title, $email, $source, $description]);
    $ideaId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO assets (asset_id, idea_id, version, status) VALUES (?, ?, ?, ?)');
    $stmt->execute([$assetId, $ideaId, '0.1', 'draft']);

    ipmdb_record_event($pdo, $assetId, 'idea_locked', [
        'title' => $title,
        'email' => $email,
        'source' => $source
    ]);

    $pdo->commit();

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
        'message' => 'Idea persisted.'
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ipmdb_json(['ok' => false, 'error' => 'Submission failed.'], 500);
}
