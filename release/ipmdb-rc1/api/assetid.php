<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function ipmdb_next_asset_id(PDO $pdo, string $prefix = 'IPM'): string
{
    $date = gmdate('Ymd');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT last_number FROM asset_sequences WHERE sequence_date = ? FOR UPDATE');
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        $next = $row ? ((int)$row['last_number'] + 1) : 1;

        if ($row) {
            $update = $pdo->prepare('UPDATE asset_sequences SET last_number = ? WHERE sequence_date = ?');
            $update->execute([$next, $date]);
        } else {
            $insert = $pdo->prepare('INSERT INTO asset_sequences (sequence_date, last_number) VALUES (?, ?)');
            $insert->execute([$date, $next]);
        }

        $pdo->commit();
        return sprintf('%s-%s-%06d', $prefix, $date, $next);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'assetid.php') {
    try {
        $config = ipmdb_config();
        $pdo = ipmdb_pdo();
        $prefix = $config['app']['asset_prefix'] ?? 'IPM';
        ipmdb_json(['ok' => true, 'asset_id' => ipmdb_next_asset_id($pdo, $prefix)]);
    } catch (Throwable $e) {
        ipmdb_json(['ok' => false, 'error' => 'Asset ID could not be generated.'], 500);
    }
}
