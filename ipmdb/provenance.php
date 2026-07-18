<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

function provenance_config(): array
{
    $path = is_file(__DIR__ . '/config.local.php')
        ? __DIR__ . '/config.local.php'
        : __DIR__ . '/config.php';

    return require $path;
}

function provenance_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $error) {
        error_log('IPMdb provenance optional query failed: ' . $error->getMessage());
        return [];
    }
}

function provenance_hash(array $record): string
{
    return hash('sha256', json_encode(
        $record,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
}

function provenance_excerpt(string $value, int $limit = 260): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 1) . '…';
    }

    return strlen($value) <= $limit ? $value : substr($value, 0, $limit - 3) . '...';
}

$assetId = substr(trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? '')), 0, 128);
$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
$manifest = null;
$error = '';

try {
    if ($assetId === '') {
        http_response_code(400);
        throw new InvalidArgumentException('Choose an asset to inspect.');
    }

    $config = provenance_config();
    $db = $config['db'] ?? [];
    $pdo = new PDO(
        (string)($db['dsn'] ?? ''),
        (string)($db['user'] ?? ''),
        (string)($db['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $stmt = $pdo->prepare(
        'SELECT asset_id, title, category, idea, status, version, created_at, updated_at
         FROM ipmdb_assets
         WHERE asset_id = ?
         LIMIT 1'
    );
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();

    if (!$asset) {
        http_response_code(404);
        throw new RuntimeException('Asset not found.');
    }

    $versions = provenance_fetch_all(
        $pdo,
        'SELECT version_number, title, category, idea, saved_at
         FROM ipmdb_asset_versions
         WHERE asset_id = ?
         ORDER BY version_number ASC, id ASC',
        [$assetId]
    );
    $relationships = provenance_fetch_all(
        $pdo,
        'SELECT source_asset_id, target_asset_id, relationship_type, note, created_at
         FROM ipmdb_relationships
         WHERE source_asset_id = ? OR target_asset_id = ?
         ORDER BY created_at ASC, id ASC',
        [$assetId, $assetId]
    );

    $assetRecord = [
        'asset_id' => (string)$asset['asset_id'],
        'title' => (string)$asset['title'],
        'category' => (string)$asset['category'],
        'status' => (string)$asset['status'],
        'version' => (string)$asset['version'],
        'created_at' => (string)$asset['created_at'],
        'updated_at' => (string)($asset['updated_at'] ?? ''),
        'idea' => (string)$asset['idea'],
    ];
    $assetRecord['content_sha256'] = provenance_hash($assetRecord);

    $versionRecords = [];
    foreach ($versions as $version) {
        $record = [
            'version_number' => (int)($version['version_number'] ?? 0),
            'title' => (string)($version['title'] ?? ''),
            'category' => (string)($version['category'] ?? ''),
            'idea' => (string)($version['idea'] ?? ''),
            'saved_at' => (string)($version['saved_at'] ?? ''),
        ];
        $record['content_sha256'] = provenance_hash($record);
        $versionRecords[] = $record;
    }

    $relationshipRecords = array_map(static fn(array $row): array => [
        'source_asset_id' => (string)($row['source_asset_id'] ?? ''),
        'target_asset_id' => (string)($row['target_asset_id'] ?? ''),
        'relationship_type' => (string)($row['relationship_type'] ?? 'related'),
        'note' => (string)($row['note'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
    ], $relationships);

    $hashPayload = [
        'schema' => 'https://ipmdb.ai/schema/provenance/v1',
        'asset' => $assetRecord,
        'versions' => $versionRecords,
        'relationships' => $relationshipRecords,
    ];
    $manifest = ['generated_at' => gmdate(DATE_ATOM)] + $hashPayload;
    $manifest['manifest_sha256'] = provenance_hash($hashPayload);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    $error = ipmdb_public_error($exception, 'The provenance receipt is temporarily unavailable.');
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60');

    if ($manifest !== null) {
        header('ETag: "' . $manifest['manifest_sha256'] . '"');
        echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } else {
        echo json_encode(['error' => $error], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Provenance Receipt | IPMdb</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100svh;background:radial-gradient(circle at top left,rgba(59,130,246,.28),transparent 34%),linear-gradient(135deg,#020617,#0f172a);color:#f8fafc;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}main{width:min(1050px,94vw);margin:auto;padding:28px 0 60px}a{color:#93c5fd;text-decoration:none}.top,.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.top{justify-content:space-between}.brand{font-size:clamp(34px,8vw,64px);font-weight:1000}.card{margin:16px 0;padding:20px;border:1px solid #334155;border-radius:22px;background:rgba(15,23,42,.78)}.receipt{border-color:#86efac}.label{color:#94a3b8;font-size:.75rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase}.hash{color:#86efac;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.box{padding:12px;border:1px solid #334155;border-radius:14px}.actions a{padding:8px 11px;border:1px solid #334155;border-radius:999px}.error{color:#fecaca;border-color:#ef4444}.muted{color:#94a3b8}.relationship{padding:10px 0;border-bottom:1px solid #334155}.relationship:last-child{border-bottom:0}h1,h2{margin:6px 0 12px}
</style>
</head>
<body>
<main>
  <header class="top">
    <a class="brand" href="/ipmdb/">IPMdb</a>
    <nav class="actions" aria-label="Primary">
      <a href="/ipmdb/ledger.php">Ledger</a>
      <a href="/ipmdb/relationship_explorer.php">Graph</a>
    </nav>
  </header>

  <?php if ($error !== '' || $manifest === null): ?>
    <section class="card error"><h1>Provenance Receipt</h1><p><?= h($error) ?></p></section>
  <?php else: ?>
    <section class="card receipt">
      <div class="label">Verifiable asset receipt</div>
      <h1><?= h($manifest['asset']['title']) ?></h1>
      <p class="hash"><?= h($manifest['manifest_sha256']) ?></p>
      <div class="actions">
        <a href="/ipmdb/viewer.php?asset_id=<?= rawurlencode($assetId) ?>">Asset</a>
        <a href="/ipmdb/provenance.php?asset_id=<?= rawurlencode($assetId) ?>&amp;format=json">Public JSON</a>
        <a href="/ipmdb/relationship_explorer.php?asset_id=<?= rawurlencode($assetId) ?>">Graph</a>
      </div>
    </section>

    <section class="card">
      <div class="label">Asset</div>
      <div class="grid">
        <div class="box"><div class="label">Asset ID</div><strong><?= h($manifest['asset']['asset_id']) ?></strong></div>
        <div class="box"><div class="label">Version</div><strong><?= h($manifest['asset']['version']) ?></strong></div>
        <div class="box"><div class="label">Status</div><strong><?= h($manifest['asset']['status']) ?></strong></div>
        <div class="box"><div class="label">Category</div><strong><?= h($manifest['asset']['category']) ?></strong></div>
      </div>
      <p><?= h(provenance_excerpt($manifest['asset']['idea'], 420)) ?></p>
      <div class="label">Content SHA-256</div>
      <p class="hash"><?= h($manifest['asset']['content_sha256']) ?></p>
    </section>

    <section class="card">
      <div class="label">History</div>
      <h2><?= count($manifest['versions']) ?> archived version<?= count($manifest['versions']) === 1 ? '' : 's' ?></h2>
      <p class="muted">Each version has its own content fingerprint.</p>
    </section>

    <section class="card">
      <div class="label">Context</div>
      <h2><?= count($manifest['relationships']) ?> relationship<?= count($manifest['relationships']) === 1 ? '' : 's' ?></h2>
      <?php foreach ($manifest['relationships'] as $relationship): ?>
        <div class="relationship">
          <strong><?= h($relationship['source_asset_id']) ?></strong>
          &nbsp;<?= h($relationship['relationship_type']) ?>&nbsp;
          <strong><?= h($relationship['target_asset_id']) ?></strong>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
