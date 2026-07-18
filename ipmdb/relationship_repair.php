<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_repair.php
| IPMdb Relationship Repair
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

function ipmdb_config(): array {
    $local = __DIR__ . '/config.local.php';
    $main  = __DIR__ . '/config.php';

    if (is_file($local)) return require $local;
    if (is_file($main)) return require $main;

    http_response_code(500);
    exit('IPMdb config file missing.');
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO {
    $c = ipmdb_config();

    return new PDO(
        $c['db']['dsn'],
        $c['db']['user'],
        $c['db']['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function table_exists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SHOW TABLES LIKE ?");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

function detect_table(PDO $pdo, array $names): ?string {
    foreach ($names as $name) {
        if (table_exists($pdo, $name)) return $name;
    }
    return null;
}

function cols(PDO $pdo, string $table): array {
    $s = $pdo->query("SHOW COLUMNS FROM `$table`");
    return array_column($s->fetchAll(), 'Field');
}

function pick(array $cols, array $names): ?string {
    foreach ($names as $name) {
        if (in_array($name, $cols, true)) return $name;
    }
    return null;
}

$error = '';
$rows = [];
$fixed = 0;

try {
    $pdo = db();

    $relTable = detect_table($pdo, [
        'ipmdb_relationships',
        'relationships',
        'ipmdb_asset_relationships',
        'asset_relationships',
    ]);

    $assetTable = detect_table($pdo, [
        'ipmdb_assets',
        'assets',
        'ideas',
        'ipmdb_ideas',
    ]);

    if (!$relTable) throw new RuntimeException('No relationship table found.');
    if (!$assetTable) throw new RuntimeException('No asset table found.');

    $relCols = cols($pdo, $relTable);
    $assetCols = cols($pdo, $assetTable);

    $relIdCol = pick($relCols, ['relationship_id', 'id']);
    $sourceCol = pick($relCols, ['source_asset_id', 'from_asset_id', 'asset_id_from', 'parent_asset_id', 'asset_id']);
    $targetCol = pick($relCols, ['target_asset_id', 'to_asset_id', 'asset_id_to', 'child_asset_id', 'related_asset_id']);
    $assetIdCol = pick($assetCols, ['asset_id', 'id', 'idea_id']);

    if (!$sourceCol || !$targetCol) {
        throw new RuntimeException('Could not detect relationship source/target columns.');
    }

    if (!$assetIdCol) {
        throw new RuntimeException('Could not detect asset ID column.');
    }

    $sql = "SELECT * FROM `$relTable` ORDER BY 1 DESC LIMIT 500";
    $rels = $pdo->query($sql)->fetchAll();

    foreach ($rels as $r) {
        $source = trim((string)($r[$sourceCol] ?? ''));
        $target = trim((string)($r[$targetCol] ?? ''));

        if ($source === '' || $target === '') {
            $rows[] = [
                'id' => $relIdCol ? ($r[$relIdCol] ?? '') : '',
                'source' => $source,
                'target' => $target,
                'problem' => trim(($source === '' ? 'Missing source ' : '') . ($target === '' ? 'Missing target' : '')),
            ];
        }
    }

} catch (Throwable $ex) {
    $error = ipmdb_public_error($ex, 'The relationship report could not be generated.');
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>IPMdb Relationship Repair</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{
    margin:0;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:#07111f;
    color:#f4f8ff;
}
.wrap{
    width:min(1100px,94vw);
    margin:0 auto;
    padding:28px 0 60px;
}
.nav{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:22px;
}
.nav a{
    color:#f4f8ff;
    text-decoration:none;
    border:1px solid #29415f;
    border-radius:999px;
    padding:8px 12px;
}
.card{
    border:1px solid #29415f;
    border-radius:24px;
    padding:22px;
    background:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,.03));
}
h1{
    margin:0 0 8px;
    font-size:clamp(30px,5vw,56px);
}
p{
    color:#a9b8cc;
    line-height:1.5;
}
.notice{
    margin:16px 0;
    padding:14px 16px;
    border-radius:16px;
    border:1px solid #29415f;
}
.bad{color:#ff8a8a}
.good{color:#86ffb4}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:18px;
}
th,td{
    padding:10px;
    border-bottom:1px solid #29415f;
    text-align:left;
}
th{
    color:#74d7ff;
    text-transform:uppercase;
    font-size:13px;
    letter-spacing:.08em;
}
code{
    background:rgba(255,255,255,.08);
    border:1px solid #29415f;
    border-radius:8px;
    padding:2px 6px;
}
</style>
</head>
<body>
<div class="wrap">

    <div class="nav">
        <a href="index.php">Lock Idea</a>
        <a href="ledger.php">Ledger</a>
        <a href="relationships.php">Relationships</a>
        <a href="relationship_bulk.php">Bulk</a>
        <a href="relationship_validate.php">Validate</a>
        <a href="relationship_history.php">History</a>
    </div>

    <div class="card">
        <h1>Relationship Repair</h1>

        <?php if ($error !== ''): ?>
            <div class="notice bad"><?php echo e($error); ?></div>
        <?php else: ?>

            <p>
                This page checks relationship rows with missing source or target asset IDs.
                It is diagnostic only, so it cannot damage the ledger.
            </p>

            <?php if (!$rows): ?>
                <div class="notice good">No missing relationship asset IDs found.</div>
            <?php else: ?>
                <div class="notice bad"><?php echo e(count($rows)); ?> relationship row(s) need attention.</div>

                <table>
                    <thead>
                        <tr>
                            <th>Relationship</th>
                            <th>Source Asset</th>
                            <th>Target Asset</th>
                            <th>Problem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo e($row['id']); ?></td>
                                <td><code><?php echo e($row['source']); ?></code></td>
                                <td><code><?php echo e($row['target']); ?></code></td>
                                <td class="bad"><?php echo e($row['problem']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php endif; ?>
    </div>

</div>
</body>
</html>
