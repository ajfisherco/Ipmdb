<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function ipmdb_next_asset_id(PDO $pdo): string
{
    $today = date('Ymd');
    $config = ipmdb_config();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT last_number FROM asset_sequences WHERE sequence_date = ?');
    $stmt->execute([$today]);
    $current = $stmt->fetchColumn();

    if ($current === false) {
        $number = 1;
        $stmt = $pdo->prepare('INSERT INTO asset_sequences (sequence_date, last_number) VALUES (?, ?)');
        $stmt->execute([$today, $number]);
    } else {
        $number = ((int) $current) + 1;
        $stmt = $pdo->prepare('UPDATE asset_sequences SET last_number = ? WHERE sequence_date = ?');
        $stmt->execute([$number, $today]);
    }

    $pdo->commit();

    return sprintf('%s-%s-%06d', $config['app']['asset_prefix'], $today, $number);
}

try {
    $pdo = ipmdb_pdo();
    ipmdb_json(['ok' => true, 'asset_id' => ipmdb_next_asset_id($pdo)]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ipmdb_json(['ok' => false, 'error' => 'Asset ID generation failed.'], 500);
}
