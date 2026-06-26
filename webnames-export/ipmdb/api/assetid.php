<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$today = date('Ymd');
$random = random_int(100000, 999999);
$config = ipmdb_config();
$assetId = $config['app']['asset_prefix'] . '-' . $today . '-' . $random;

ipmdb_json([
    'ok' => true,
    'asset_id' => $assetId,
    'note' => 'Temporary generator. Replace with database sequence before production.'
]);
