<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_bulk.php
|--------------------------------------------------------------------------
| IPMdb Bulk Relationship Creator
| IDEAS 2 ASSETS
|--------------------------------------------------------------------------
*/

function ipmdb_config(): array
{
    $local = __DIR__ . '/config.local.php';
    $main  = __DIR__ . '/config.php';

    if (is_file($local)) {
        return require $local;
    }

    if (is_file($main)) {
        return require $main;
    }

    http_response_code(500);
    exit('IPMdb config file missing.');
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    $config = ipmdb_config();

    return new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = [];

    foreach ($stmt->fetchAll() as $row) {
        $cols[] = (string)$row['Field'];
    }

    return $cols;
}

function has_col(array $cols, string $name): bool
{
    return in_array($name, $cols, true);
}

function first_col(array $cols, array $options): ?string
{
    foreach ($options as $option) {
        if (has_col($cols, $option)) {
            return $option;
        }
    }

    return null;
}

function asset_label(array $asset): string
{
    $title = trim((string)($asset['title'] ?? ''));

    if ($title !== '') {
        return $title;
    }

    return 'Untitled Asset';
}

$pdo = db();

$assetCols = table_columns($pdo, 'ipmdb_assets');
$relCols   = table_columns($pdo, 'ipmdb_relationships');

$assetIdCol = first_col($assetCols, ['asset_id', 'id']);
$titleCol   = first_col($assetCols, ['title', 'asset_title', 'name']);
$emailCol   = first_col($assetCols, ['email', 'originator_email', 'originator']);
$ideaCol    = first_col($assetCols, ['idea', 'idea_text', 'description', 'body']);
$statusCol  = first_col($assetCols, ['status']);
$versionCol = first_col($assetCols, ['version']);
$createdCol = first_col($assetCols, ['created_at', 'created']);

if ($assetIdCol === null) {
    http_response_code(500);
    exit('Asset ID column missing.');
}

$sourceCol = first_col($relCols, ['source_asset_id', 'from_asset_id', 'asset_id']);
$targetCol = first_col($relCols, ['target_asset_id', 'to_asset_id', 'related_asset_id']);
$typeCol   = first_col($relCols, ['relationship_type', 'type']);
$noteCol   = first_col($relCols, ['note', 'notes']);
$relCreatedCol = first_col($relCols, ['created_at', 'created']);

if ($sourceCol === null || $targetCol === null) {
    http_response_code(500);
    exit('Relationship source/target columns missing.');
}

$currentId = trim((string)($_GET['asset_id'] ?? $_POST['asset_id'] ?? ''));
$q         = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));

$message = '';
$error   = '';
$currentAsset = null;
$results = [];

$relationshipTypes = [
    'relates_to' => 'Relates To',
    'depends_on' => 'Depends On',
    'part_of' => 'Part Of',
    'blocks' => 'Blocks',
    'implements' => 'Implements',
    'enhances' => 'Enhances',
    'documents' => 'Documents',
    'supersedes' => 'Supersedes',
];

try {
    if ($currentId !== '') {
        $stmt = $pdo->prepare("SELECT * FROM ipmdb_assets WHERE `$assetIdCol` = ? LIMIT 1");
        $stmt->execute([$currentId]);
        $currentAsset = $stmt->fetch() ?: null;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_create') {
        ipmdb_require_csrf();
        if (!$currentAsset) {
            throw new RuntimeException('Current asset not found.');
        }

        $targets = $_POST['target_asset_ids'] ?? [];
        if (!is_array($targets)) {
            $targets = [];
        }

        $targets = array_values(array_unique(array_filter(array_map(
            static fn($v): string => trim((string)$v),
            $targets
        ))));

        $targets = array_values(array_filter(
            $targets,
            static fn($v): bool => $v !== '' && $v !== $currentId
        ));

        $relationshipType = trim((string)($_POST['relationship_type'] ?? 'relates_to'));
        if (!array_key_exists($relationshipType, $relationshipTypes)) {
            $relationshipType = 'relates_to';
        }

        $note = trim((string)($_POST['note'] ?? ''));

        if (!$targets) {
            throw new RuntimeException('Select at least one related asset.');
        }

        $inserted = 0;
        $skipped  = 0;

        foreach ($targets as $targetId) {
            $check = $pdo->prepare("
                SELECT COUNT(*)
                FROM ipmdb_relationships
                WHERE `$sourceCol` = ?
                  AND `$targetCol` = ?
            ");
            $check->execute([$currentId, $targetId]);

            if ((int)$check->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            $cols = [$sourceCol, $targetCol];
            $vals = [$currentId, $targetId];
            $marks = ['?', '?'];

            if ($typeCol !== null) {
                $cols[] = $typeCol;
                $vals[] = $relationshipType;
                $marks[] = '?';
            }

            if ($noteCol !== null) {
                $cols[] = $noteCol;
                $vals[] = $note;
                $marks[] = '?';
            }

            if ($relCreatedCol !== null) {
                $cols[] = $relCreatedCol;
                $vals[] = date('Y-m-d H:i:s');
                $marks[] = '?';
            }

            $colSql = implode(', ', array_map(static fn($c): string => "`$c`", $cols));
            $markSql = implode(', ', $marks);

            $insert = $pdo->prepare("
                INSERT INTO ipmdb_relationships ($colSql)
                VALUES ($markSql)
            ");
            $insert->execute($vals);

            $inserted++;
        }

        header('Location: relationships.php?asset_id=' . rawurlencode($currentId) . '&bulk_added=' . $inserted . '&bulk_skipped=' . $skipped);
        exit;
    }

    if ($currentAsset && $q !== '') {
        $searchParts = [];
        $params = [];

        foreach ([$assetIdCol, $titleCol, $emailCol, $ideaCol, $statusCol] as $col) {
            if ($col !== null) {
                $searchParts[] = "`$col` LIKE ?";
                $params[] = '%' . $q . '%';
            }
        }

        $params[] = $currentId;

        $where = implode(' OR ', $searchParts);

        $stmt = $pdo->prepare("
            SELECT *
            FROM ipmdb_assets
            WHERE ($where)
              AND `$assetIdCol` <> ?
            ORDER BY `$createdCol` DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
} catch (Throwable $ex) {
    $error = ipmdb_public_error($ex, 'The relationships could not be saved.');
}

if (isset($_GET['bulk_added'])) {
    $message = (int)$_GET['bulk_added'] . ' relationship(s) added.';
    if (isset($_GET['bulk_skipped']) && (int)$_GET['bulk_skipped'] > 0) {
        $message .= ' ' . (int)$_GET['bulk_skipped'] . ' duplicate(s) skipped.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>IPMdb Bulk Relationships</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
    --bg:#07111f;
    --panel:#0c1b2e;
    --panel2:#10243c;
    --text:#eef6ff;
    --muted:#9fb6ca;
    --line:rgba(255,255,255,.16);
    --accent:#7dd3fc;
    --good:#86efac;
    --bad:#fca5a5;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:
        radial-gradient(circle at top left,rgba(125,211,252,.18),transparent 32rem),
        linear-gradient(135deg,#06101d,#0b1728 55%,#08121f);
    color:var(--text);
}
a{color:inherit}
.wrap{
    width:min(1180px,94vw);
    margin:0 auto;
    padding:28px 0 56px;
}
.top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:22px;
}
.brand h1{
    margin:0;
    font-size:clamp(28px,5vw,56px);
    letter-spacing:.04em;
}
.brand p{
    margin:4px 0 0;
    color:var(--muted);
    font-weight:700;
    letter-spacing:.18em;
}
.nav{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
}
.btn,button{
    appearance:none;
    border:1px solid var(--line);
    background:rgba(255,255,255,.08);
    color:var(--text);
    border-radius:999px;
    padding:11px 15px;
    font-weight:800;
    text-decoration:none;
    cursor:pointer;
}
.btn:hover,button:hover{
    background:rgba(125,211,252,.18);
}
.grid{
    display:grid;
    grid-template-columns:1fr;
    gap:18px;
}
.card{
    background:linear-gradient(180deg,rgba(255,255,255,.08),rgba(255,255,255,.04));
    border:1px solid var(--line);
    border-radius:24px;
    padding:20px;
    box-shadow:0 22px 60px rgba(0,0,0,.28);
}
.card h2{
    margin:0 0 14px;
    font-size:24px;
}
.asset-title{
    font-size:30px;
    font-weight:900;
    margin-bottom:8px;
}
.meta{
    color:var(--muted);
    line-height:1.7;
}
.badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.07);
    color:var(--muted);
    font-size:13px;
    font-weight:800;
}
.formline{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}
input[type="text"],textarea,select{
    width:100%;
    border:1px solid var(--line);
    background:rgba(255,255,255,.08);
    color:var(--text);
    border-radius:16px;
    padding:14px 15px;
    font:inherit;
    outline:none;
}
select{
    max-width:280px;
}
textarea{
    min-height:88px;
    resize:vertical;
}
.searchbox{
    flex:1 1 360px;
}
.notice{
    border:1px solid var(--line);
    border-radius:18px;
    padding:14px 16px;
    margin-bottom:16px;
}
.notice.good{
    color:var(--good);
    background:rgba(134,239,172,.08);
}
.notice.bad{
    color:var(--bad);
    background:rgba(252,165,165,.08);
}
.results{
    display:grid;
    gap:12px;
    margin-top:14px;
}
.result{
    display:grid;
    grid-template-columns:auto 1fr;
    gap:14px;
    align-items:start;
    padding:14px;
    border:1px solid var(--line);
    border-radius:18px;
    background:rgba(255,255,255,.05);
}
.result input{
    width:22px;
    height:22px;
    margin-top:4px;
}
.result-title{
    font-weight:900;
    font-size:18px;
}
.result-meta{
    color:var(--muted);
    margin-top:4px;
    line-height:1.5;
}
.actions{
    margin-top:18px;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
}
.empty{
    color:var(--muted);
    padding:16px 0;
}
.footer{
    margin-top:28px;
    padding-top:18px;
    border-top:1px solid var(--line);
    color:var(--muted);
}
@media (min-width:900px){
    .grid{
        grid-template-columns:380px 1fr;
    }
}
</style>
</head>
<body>
<div class="wrap">

    <div class="top">
        <div class="brand">
            <h1>IPMdb</h1>
            <p>IDEAS 2 ASSETS</p>
        </div>
        <div class="nav">
            <a class="btn" href="lock.php">Lock Idea</a>
            <a class="btn" href="search.php">Search</a>
            <a class="btn" href="ledger.php">Ledger</a>
            <a class="btn" href="admin.php">Admin</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice good"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice bad"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="grid">
        <section class="card">
            <h2>Bulk Relationships</h2>

            <?php if (!$currentAsset): ?>
                <div class="empty">
                    Current asset missing. Open this page from a relationship map using an asset ID.
                </div>
                <form method="get" class="formline">
                    <input type="text" name="asset_id" placeholder="Asset ID">
                    <button type="submit">Load Asset</button>
                </form>
            <?php else: ?>
                <div class="asset-title"><?php echo e(asset_label($currentAsset)); ?></div>
                <div class="meta">
                    Asset ID: <?php echo e((string)$currentAsset[$assetIdCol]); ?><br>
                    <?php if ($emailCol !== null): ?>
                        Origin: <?php echo e((string)($currentAsset[$emailCol] ?? '')); ?><br>
                    <?php endif; ?>
                    <?php if ($statusCol !== null): ?>
                        Status: <?php echo e((string)($currentAsset[$statusCol] ?? '')); ?>
                    <?php endif; ?>
                    <?php if ($versionCol !== null): ?>
                        · Version: <?php echo e((string)($currentAsset[$versionCol] ?? '')); ?>
                    <?php endif; ?>
                    <?php if ($createdCol !== null): ?>
                        <br>Created: <?php echo e((string)($currentAsset[$createdCol] ?? '')); ?>
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <a class="btn" href="relationships.php?asset_id=<?php echo rawurlencode($currentId); ?>">Back to Map</a>
                    <a class="btn" href="asset.php?asset_id=<?php echo rawurlencode($currentId); ?>">View Asset</a>
                    <a class="btn" href="ledger.php">Ledger</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Search Assets</h2>

            <?php if ($currentAsset): ?>
                <form method="get" class="formline">
                    <input type="hidden" name="asset_id" value="<?php echo e($currentId); ?>">
                    <input class="searchbox" type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search by title, email, asset ID, status, or idea text">
                    <button type="submit">Search</button>
                </form>

                <?php if ($q === ''): ?>
                    <div class="empty">Search for assets, then select every relationship to create in one pass.</div>
                <?php elseif (!$results): ?>
                    <div class="empty">No matching assets found.</div>
                <?php else: ?>
                    <form method="post">
                        <?= ipmdb_csrf_field() ?>
                        <input type="hidden" name="action" value="bulk_create">
                        <input type="hidden" name="asset_id" value="<?php echo e($currentId); ?>">
                        <input type="hidden" name="q" value="<?php echo e($q); ?>">

                        <div class="results">
                            <?php foreach ($results as $row): ?>
                                <?php $rid = (string)$row[$assetIdCol]; ?>
                                <label class="result">
                                    <input type="checkbox" name="target_asset_ids[]" value="<?php echo e($rid); ?>">
                                    <div>
                                        <div class="result-title"><?php echo e(asset_label($row)); ?></div>
                                        <div class="result-meta">
                                            <?php echo e($rid); ?>
                                            <?php if ($emailCol !== null && trim((string)($row[$emailCol] ?? '')) !== ''): ?>
                                                · <?php echo e((string)$row[$emailCol]); ?>
                                            <?php endif; ?>
                                            <?php if ($statusCol !== null): ?>
                                                · Status: <?php echo e((string)($row[$statusCol] ?? '')); ?>
                                            <?php endif; ?>
                                            <?php if ($versionCol !== null): ?>
                                                · Version: <?php echo e((string)($row[$versionCol] ?? '')); ?>
                                            <?php endif; ?>
                                            <?php if ($createdCol !== null): ?>
                                                · Created: <?php echo e((string)($row[$createdCol] ?? '')); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="actions">
                            <select name="relationship_type">
                                <?php foreach ($relationshipTypes as $value => $label): ?>
                                    <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="actions">
                            <textarea name="note" placeholder="Optional shared relationship note"></textarea>
                        </div>

                        <div class="actions">
                            <button type="submit">Create Selected Relationships</button>
                            <span class="badge"><?php echo count($results); ?> result(s)</span>
                        </div>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty">Load a current asset before searching.</div>
            <?php endif; ?>
        </section>
    </div>

    <div class="footer">
        IPMdb.ai · Ideas 2 Assets.
    </div>

</div>
</body>
</html>
