<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $pdo = ipmdb_pdo();
    $stmt = $pdo->query('SELECT asset_id, title, source, status, created_at FROM ideas ORDER BY created_at DESC LIMIT 25');
    ipmdb_json(['ok' => true, 'records' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    ipmdb_json(['ok' => false, 'error' => 'Records could not be loaded.'], 500);
}
