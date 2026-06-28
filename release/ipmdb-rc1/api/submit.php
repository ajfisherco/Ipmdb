<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/assetid.php';
require __DIR__ . '/mail.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        ipmdb_json(['ok' => false, 'error' => 'POST required.'], 405);
    }

    $config = ipmdb_config();
    $data = ipmdb_request_json();
    $title = ipmdb_clean((string)($data['title'] ?? ''), 120);
    $email = ipmdb_clean((string)($data['email'] ?? ''), 255);
    $source = ipmdb_clean((string)($data['source'] ?? ''), 120);
    $description = ipmdb_clean((string)($data['description'] ?? ''), 3000);

    if ($title === '' || $email === '' || $source === '') {
        ipmdb_json(['ok' => false, 'error' => 'Idea Title, Email, and Source are required.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ipmdb_json(['ok' => false, 'error' => 'A valid email is required.'], 400);
    }

    $pdo = ipmdb_pdo();
    $prefix = $config['app']['asset_prefix'] ?? 'IPM';
    $assetId = ipmdb_next_asset_id($pdo, $prefix);

    $pdo->beginTransaction();
    $idea = $pdo->prepare('INSERT INTO ideas (asset_id, title, originator_email, source, description, status) VALUES (?, ?, ?, ?, ?, ?)');
    $idea->execute([$assetId, $title, $email, $source, $description, 'draft']);
    $ideaId = (int)$pdo->lastInsertId();

    $asset = $pdo->prepare('INSERT INTO assets (asset_id, idea_id, version, status) VALUES (?, ?, ?, ?)');
    $asset->execute([$assetId, $ideaId, '0.1', 'draft']);

    $ledger = $pdo->prepare('INSERT INTO ledger (asset_id, event_type, event_payload) VALUES (?, ?, ?)');
    $ledger->execute([$assetId, 'idea.locked', json_encode(['title' => $title, 'source' => $source], JSON_UNESCAPED_SLASHES)]);

    $audit = $pdo->prepare('INSERT INTO audit_log (actor_email, action, payload) VALUES (?, ?, ?)');
    $audit->execute([$email, 'idea.submit', json_encode(['asset_id' => $assetId], JSON_UNESCAPED_SLASHES)]);

    $pdo->commit();

    $mailSent = ipmdb_send_ack($config, $email, $assetId, $title);

    ipmdb_json(['ok' => true, 'asset_id' => $assetId, 'mail_sent' => $mailSent]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ipmdb_json(['ok' => false, 'error' => 'Submission could not be completed.'], 500);
}
