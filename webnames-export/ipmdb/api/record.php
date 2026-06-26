<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function ipmdb_record_event(PDO $pdo, string $assetId, string $eventType, array $payload = []): void
{
    $stmt = $pdo->prepare('INSERT INTO ledger (asset_id, event_type, event_payload) VALUES (?, ?, ?)');
    $stmt->execute([
        $assetId,
        $eventType,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ]);
}
