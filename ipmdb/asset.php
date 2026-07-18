<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/asset.php
| IPMdb Asset Node
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/security.php';

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
    $s = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table
    ");
    $s->execute(['table' => $table]);
    return (int)$s->fetchColumn() > 0;
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

function val(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }
    return $default;
}

function asset_link(string $assetId): string {
    return 'viewer.php?asset_id=' . rawurlencode($assetId);
}
$error = '';
$asset = null;
$outgoing = [];
$incoming = [];
$versions = [];

try {
    $pdo = db();

    $assetId = trim((string)($_GET['id'] ?? $_GET['asset_id'] ?? ''));

    if ($assetId === '') {
        throw new RuntimeException('Missing Asset ID.');
    }

    $assetTable = detect_table($pdo, [
        'ipmdb_assets',
        'assets',
        'ideas',
        'ipmdb_ideas',
    ]);

    if (!$assetTable) {
        throw new RuntimeException('No asset table found.');
    }

    $assetCols = cols($pdo, $assetTable);
    $assetIdCol = pick($assetCols, ['asset_id', 'id', 'idea_id']);

    if (!$assetIdCol) {
        throw new RuntimeException('Could not detect asset ID column.');
    }

    $s = $pdo->prepare("SELECT * FROM `$assetTable` WHERE `$assetIdCol` = ? LIMIT 1");
    $s->execute([$assetId]);
    $asset = $s->fetch();

    if (!$asset) {
        throw new RuntimeException('Asset not found: ' . $assetId);
    }

    $relTable = detect_table($pdo, [
        'ipmdb_relationships',
        'relationships',
        'ipmdb_asset_relationships',
        'asset_relationships',
    ]);

    if ($relTable) {
        $relCols = cols($pdo, $relTable);

        $sourceCol = pick($relCols, [
            'source_asset_id',
            'from_asset_id',
            'asset_id_from',
            'parent_asset_id',
            'asset_id',
        ]);

        $targetCol = pick($relCols, [
            'target_asset_id',
            'to_asset_id',
            'asset_id_to',
            'child_asset_id',
            'related_asset_id',
        ]);

        if ($sourceCol && $targetCol) {
            $typeCol = pick($relCols, ['relationship_type', 'type', 'relation_type']);
            $notesCol = pick($relCols, ['notes', 'note', 'description']);
            $createdCol = pick($relCols, ['created_at', 'created']);

            $fieldList = "`$sourceCol`, `$targetCol`";
            if ($typeCol) $fieldList .= ", `$typeCol`";
            if ($notesCol) $fieldList .= ", `$notesCol`";
            if ($createdCol) $fieldList .= ", `$createdCol`";

            $s = $pdo->prepare("SELECT $fieldList FROM `$relTable` WHERE `$sourceCol` = ? ORDER BY 1 DESC LIMIT 100");
            $s->execute([$assetId]);
            $outgoing = $s->fetchAll();

            $s = $pdo->prepare("SELECT $fieldList FROM `$relTable` WHERE `$targetCol` = ? ORDER BY 1 DESC LIMIT 100");
            $s->execute([$assetId]);
            $incoming = $s->fetchAll();
        }
    }

    $historyTable = detect_table($pdo, [
        'ipmdb_asset_versions',
        'asset_versions',
        'ipmdb_versions',
        'versions',
    ]);

    if ($historyTable) {
        $hCols = cols($pdo, $historyTable);
        $hAssetCol = pick($hCols, ['asset_id', 'idea_id']);
        $hCreatedCol = pick($hCols, ['created_at', 'created', 'updated_at', 'version_created_at']);

        if ($hAssetCol) {
            $order = $hCreatedCol ? "ORDER BY `$hCreatedCol` DESC" : "ORDER BY 1 DESC";
            $s = $pdo->prepare("SELECT * FROM `$historyTable` WHERE `$hAssetCol` = ? $order LIMIT 50");
            $s->execute([$assetId]);
            $versions = $s->fetchAll();
        }
    }

} catch (Throwable $ex) {
    $error = ipmdb_public_error($ex, 'The asset could not be loaded.');
}

$title = $asset ? val($asset, ['title', 'asset_title', 'name'], 'Untitled Asset') : 'Asset';
$assetIdDisplay = $asset ? val($asset, ['asset_id', 'id', 'idea_id'], '') : '';

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>IPMdb Asset</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
    --bg:#06101d;
    --panel:#101e31;
    --text:#f4f8ff;
    --muted:#a9b8cc;
    --line:#29415f;
    --accent:#74d7ff;
    --good:#86ffb4;
    --bad:#ff8a8a;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:radial-gradient(circle at top,#173a60 0,#06101d 50%,#02060b 100%);
    color:var(--text);
}
.wrap{
    width:min(1180px,94vw);
    margin:0 auto;
    padding:28px 0 70px;
}
.nav,.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:22px;
}
.nav a,.actions a{
    color:var(--text);
    text-decoration:none;
    border:1px solid var(--line);
    border-radius:999px;
    padding:8px 12px;
    background:rgba(255,255,255,.04);
}
.actions a.primary{
    background:var(--accent);
    color:#03111f;
    font-weight:900;
}
.card{
    border:1px solid var(--line);
    background:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,.03));
    border-radius:24px;
    padding:22px;
    box-shadow:0 18px 60px rgba(0,0,0,.35);
    margin-bottom:18px;
}
.kicker{
    color:var(--accent);
    font-weight:900;
    letter-spacing:.18em;
    text-transform:uppercase;
}
h1{
    margin:0 0 6px;
    font-size:clamp(30px,5vw,58px);
}
h2{
    margin:0 0 12px;
}
p{color:var(--muted);line-height:1.5}
.meta{
    display:grid;
    grid-template-columns:repeat(4,minmax(140px,1fr));
    gap:12px;
    margin-top:18px;
}
.box{
    border:1px solid var(--line);
    border-radius:18px;
    padding:14px;
    background:rgba(0,0,0,.18);
}
.box strong{
    display:block;
    color:var(--accent);
    font-size:13px;
    text-transform:uppercase;
    letter-spacing:.08em;
    margin-bottom:6px;
}
.idea{
    white-space:pre-wrap;
    color:var(--text);
    background:rgba(0,0,0,.2);
    border:1px solid var(--line);
    border-radius:18px;
    padding:16px;
    line-height:1.55;
}
.notice{
    margin:16px 0;
    padding:14px 16px;
    border-radius:16px;
    border:1px solid rgba(255,138,138,.45);
    color:var(--bad);
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:10px;
    border-bottom:1px solid var(--line);
    text-align:left;
    vertical-align:top;
}
th{
    color:var(--accent);
    font-size:13px;
    text-transform:uppercase;
    letter-spacing:.08em;
}
a.asset{
    color:var(--accent);
    font-weight:900;
    text-decoration:none;
}
.badge{
    display:inline-block;
    border:1px solid var(--line);
    border-radius:999px;
    padding:5px 8px;
    color:var(--muted);
}
.empty{
    color:var(--muted);
    padding:14px 0;
}
@media(max-width:900px){
    .meta{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:620px){
    .meta{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="wrap">

<div class="nav">
  <a href="/ipmdb/">Lock</a>
  <a href="/ipmdb/ledger.php">Ledger</a>
  <a href="/ipmdb/search.php">Search</a>
  <a href="/ipmdb/relationship_explorer.php">Graph</a>
  <a href="/ipmdb/ecosystem.php">System Map</a>
  <a href="/ipmdb/dad/">DAD</a>
</div>
    <?php if ($error !== ''): ?>
        <div class="card">
<div class="kicker">IPMdb.ai · Ideas 2 Assets</div>            <h1>Asset</h1>
            <div class="notice"><?php echo e($error); ?></div>
        </div>
    <?php else: ?>

        <div class="card">
<div class="kicker">IPMdb.ai · Asset Workspace</div>            <h1><?php echo e($title); ?></h1>

<div class="actions">

    <a class="primary" href="/ipmdb/relationship_add.php?asset_id=<?php echo rawurlencode($assetIdDisplay); ?>">
        Add Relationship
    </a>

    <a href="/ipmdb/relationship_explorer.php?asset_id=<?php echo rawurlencode($assetIdDisplay); ?>">
        Relationship Explorer
    </a>

    <a href="/ipmdb/ai_relationships.php?asset_id=<?php echo rawurlencode($assetIdDisplay); ?>">
        AI Map · GPT-5.6
    </a>

    <a href="/ipmdb/dad/?asset_id=<?php echo rawurlencode($assetIdDisplay); ?>">
        Implement with DAD
    </a>

    <a href="/ipmdb/relationship_history.php?asset_id=<?php echo rawurlencode($assetIdDisplay); ?>">
        History
    </a>

    <a href="/ipmdb/provenance.php?asset_id=<?php echo rawurlencode($assetIdDisplay); ?>">
        Provenance Receipt
    </a>

    <a href="/ipmdb/ledger.php">
        Asset Ledger
    </a>

    <a href="/ipmdb/admin_edit.php?asset_id=<?php echo rawurlencode($assetIdDisplay); ?>">
        Edit
    </a>

</div>
            <div class="meta">
                <div class="box">
                    <strong>Asset ID</strong>
                    <a class="asset" href="<?php echo e(asset_link($assetIdDisplay)); ?>"><?php echo e($assetIdDisplay); ?></a>
                </div>
                <div class="box">
                    <strong>Status</strong>
                    <?php echo e(val($asset, ['status'], 'Draft')); ?>
                </div>
                <div class="box">
                    <strong>Version</strong>
                    <?php echo e(val($asset, ['version'], '1.0')); ?>
                </div>
                <div class="box">
                    <strong>Created</strong>
                    <?php echo e(val($asset, ['created_at', 'created'], '')); ?>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Idea</h2>
            <div class="idea"><?php echo e(val($asset, ['idea', 'idea_text', 'description', 'content', 'body'], 'No idea text found.')); ?></div>
        </div>

        <div class="card">
            <h2>Outgoing Relationships</h2>
            <?php if (!$outgoing): ?>
                <div class="empty">No outgoing relationships found.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Target Asset</th>
                            <th>Type</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($outgoing as $r): ?>
                        <?php
                        $target = val($r, ['target_asset_id','to_asset_id','asset_id_to','child_asset_id','related_asset_id']);
                        ?>
                        <tr>
                            <td><a class="asset" href="<?php echo e(asset_link($target)); ?>"><?php echo e($target); ?></a></td>
                            <td><span class="badge"><?php echo e(val($r, ['relationship_type','type','relation_type'], 'related')); ?></span></td>
                            <td><?php echo e(val($r, ['notes','note','description'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Incoming Relationships</h2>
            <?php if (!$incoming): ?>
                <div class="empty">No incoming relationships found.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Source Asset</th>
                            <th>Type</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($incoming as $r): ?>
                        <?php
                        $source = val($r, ['source_asset_id','from_asset_id','asset_id_from','parent_asset_id','asset_id']);
                        ?>
                        <tr>
                            <td><a class="asset" href="<?php echo e(asset_link($source)); ?>"><?php echo e($source); ?></a></td>
                            <td><span class="badge"><?php echo e(val($r, ['relationship_type','type','relation_type'], 'related')); ?></span></td>
                            <td><?php echo e(val($r, ['notes','note','description'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Version History</h2>
            <?php if (!$versions): ?>
                <div class="empty">No version history found.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Title</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($versions as $v): ?>
                        <tr>
                            <td><?php echo e(val($v, ['version'], '')); ?></td>
                            <td><?php echo e(val($v, ['status'], '')); ?></td>
                            <td><?php echo e(val($v, ['created_at','created','updated_at','version_created_at'], '')); ?></td>
                            <td><?php echo e(val($v, ['title','asset_title','name'], '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
