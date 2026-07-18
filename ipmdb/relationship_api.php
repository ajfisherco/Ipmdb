<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_api.php
|--------------------------------------------------------------------------
| IPMdb Relationship Explorer JSON API
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/relationship_functions.php';

if (!function_exists('ipmdb_config')) {
    function ipmdb_config(): array
    {
        $local = __DIR__ . '/config.local.php';
        $main = __DIR__ . '/config.php';

        if (is_file($local)) {
            return require $local;
        }

        if (is_file($main)) {
            return require $main;
        }

        throw new RuntimeException('IPMdb config file missing.');
    }
}

try {
    $config = ipmdb_config();

    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $assetId = trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 80);
    $limit = max(1, min(200, $limit));

    $graphData = ipmdb_rel_prepare_graph(
        $pdo,
        $assetId !== '' ? $assetId : null,
        $type !== '' ? $type : null,
        $limit
    );

    $json = json_decode(ipmdb_rel_graph_json($graphData), true);

    if (!is_array($json)) {
        ipmdb_rel_json_response([
            'ok' => false,
            'error' => 'Graph data could not be encoded.',
        ], 500);
    }

    $json['ok'] = true;
    $json['generated_at'] = gmdate('c');
    ipmdb_rel_json_response($json);
} catch (Throwable $error) {
    error_log('IPMdb relationship API failed: ' . $error->getMessage());
    ipmdb_rel_json_response([
        'ok' => false,
        'error' => 'Relationship API error.',
    ], 500);
}
