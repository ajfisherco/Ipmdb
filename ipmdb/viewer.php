<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IPMdb Asset Viewer
| Upload as: /httpdocs/ipmdb/viewer.php
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';

function viewer_load_config(): array
{
    $local = __DIR__ . '/config.local.php';
    $main  = __DIR__ . '/config.php';

    if (is_file($local)) {
        $config = require $local;
    } elseif (is_file($main)) {
        $config = require $main;
    } else {
        http_response_code(500);
        exit('IPMdb configuration is unavailable.');
    }

    if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
        throw new RuntimeException('IPMdb database configuration is invalid.');
    }

    return $config;
}

function viewer_excerpt(string $text, int $limit = 180): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) <= $limit
            ? $text
            : mb_substr($text, 0, $limit - 1) . '…';
    }

    return strlen($text) <= $limit
        ? $text
        : substr($text, 0, $limit - 3) . '...';
}

function viewer_asset_link(string $assetId): string
{
    return '/ipmdb/viewer.php?asset_id=' . rawurlencode($assetId);
}

function viewer_asset_node_link(string $assetId): string
{
    return '/ipmdb/asset.php?id=' . rawurlencode($assetId);
}

function viewer_explorer_link(string $assetId): string
{
    return '/ipmdb/relationship_explorer.php?asset_id=' . rawurlencode($assetId);
}

function viewer_add_relationship_link(string $assetId): string
{
    return '/ipmdb/relationship_add.php?asset_id=' . rawurlencode($assetId);
}

function viewer_bulk_relationship_link(string $assetId): string
{
    return '/ipmdb/relationship_bulk.php?asset_id=' . rawurlencode($assetId);
}

function viewer_history_link(string $assetId): string
{
    return '/ipmdb/history.php?asset_id=' . rawurlencode($assetId);
}

function viewer_provenance_link(string $assetId): string
{
    return '/ipmdb/provenance.php?asset_id=' . rawurlencode($assetId);
}

function viewer_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('IPMdb Viewer optional query failed: ' . $e->getMessage());
        return [];
    }
}

function viewer_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('IPMdb Viewer optional query failed: ' . $e->getMessage());
        return null;
    }
}

$assetId = trim((string) ($_GET['asset_id'] ?? $_GET['id'] ?? ''));
$assetId = substr($assetId, 0, 128);

$asset     = null;
$versions  = [];
$outgoing  = [];
$incoming  = [];
$recent    = [];
$prevAsset = null;
$nextAsset = null;
$error     = '';

try {
    $config = viewer_load_config();
    $db = $config['db'];

    foreach (['dsn', 'user', 'pass'] as $required) {
        if (!array_key_exists($required, $db)) {
            throw new RuntimeException('IPMdb database configuration is incomplete.');
        }
    }

    $pdo = new PDO(
        (string) $db['dsn'],
        (string) $db['user'],
        (string) $db['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]
    );

    if ($assetId !== '') {
        $asset = viewer_fetch_one(
            $pdo,
            'SELECT asset_id, title, category, idea, status, version, created_at, updated_at
             FROM ipmdb_assets WHERE asset_id = ? LIMIT 1',
            [$assetId]
        );
    }

    if ($asset !== null) {
        $versions = viewer_fetch_all(
            $pdo,
            'SELECT asset_id, version_number, CONCAT(\'1.\', version_number) AS version,
                    title, category, idea, saved_at AS created_at
             FROM ipmdb_asset_versions
             WHERE asset_id = ?
             ORDER BY created_at DESC
             LIMIT 25',
            [$assetId]
        );

        $outgoing = viewer_fetch_all(
            $pdo,
            'SELECT r.id, r.source_asset_id, r.target_asset_id, r.relationship_type,
                    r.note, r.created_at,
                    a.asset_id, a.title, a.category, a.idea, a.status, a.version,
                    a.created_at AS asset_created_at, a.updated_at AS asset_updated_at
             FROM ipmdb_relationships r
             INNER JOIN ipmdb_assets a
                ON a.asset_id = r.target_asset_id
             WHERE r.source_asset_id = ?
             ORDER BY r.created_at DESC
             LIMIT 50',
            [$assetId]
        );

        $incoming = viewer_fetch_all(
            $pdo,
            'SELECT r.id, r.source_asset_id, r.target_asset_id, r.relationship_type,
                    r.note, r.created_at,
                    a.asset_id, a.title, a.category, a.idea, a.status, a.version,
                    a.created_at AS asset_created_at, a.updated_at AS asset_updated_at
             FROM ipmdb_relationships r
             INNER JOIN ipmdb_assets a
                ON a.asset_id = r.source_asset_id
             WHERE r.target_asset_id = ?
             ORDER BY r.created_at DESC
             LIMIT 50',
            [$assetId]
        );

        $prevAsset = viewer_fetch_one(
            $pdo,
            'SELECT asset_id
             FROM ipmdb_assets
             WHERE asset_id < ?
             ORDER BY asset_id DESC
             LIMIT 1',
            [$assetId]
        );

        $nextAsset = viewer_fetch_one(
            $pdo,
            'SELECT asset_id
             FROM ipmdb_assets
             WHERE asset_id > ?
             ORDER BY asset_id ASC
             LIMIT 1',
            [$assetId]
        );
    }

    $recent = viewer_fetch_all(
        $pdo,
        'SELECT asset_id, title, category, idea, status, version, created_at, updated_at
         FROM ipmdb_assets
         ORDER BY created_at DESC
         LIMIT 10'
    );
} catch (Throwable $e) {
    error_log('IPMdb Viewer failed: ' . $e->getMessage());
    $error = 'The Viewer could not load the database. Please try again.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPMdb Viewer</title>
<?= ipmdb_render_asset_styles() ?>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{
    margin:0;
    min-height:100svh;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
    background:
        radial-gradient(circle at top left,rgba(59,130,246,.28),transparent 34%),
        linear-gradient(135deg,#020617,#0f172a 50%,#020617);
    color:#f8fafc;
}
main{width:min(1180px,94vw);margin:auto;padding:22px 0 42px}
.nav{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:18px}
.brand{color:#fff;text-decoration:none;font-weight:1000;letter-spacing:.04em}
.links,.actions{display:flex;flex-wrap:wrap;gap:8px}
.links a,.actions a{
    text-decoration:none;
    color:#dbeafe;
    padding:9px 12px;
    border-radius:999px;
    border:1px solid rgba(147,197,253,.22);
    background:rgba(15,23,42,.55);
}
.actions .primary{background:#93c5fd;color:#020617;font-weight:900}
.card{
    margin:16px 0;
    padding:18px;
    border-radius:22px;
    border:1px solid rgba(148,163,184,.18);
    background:rgba(2,6,23,.58);
    box-shadow:0 18px 60px rgba(0,0,0,.22);
}
.sub{color:#93c5fd;text-transform:uppercase;letter-spacing:.10em;font-size:.76rem;font-weight:900;margin-bottom:8px}
h1,h2{margin:0 0 12px;font-size:clamp(1.35rem,4vw,2.2rem)}
.idea{white-space:pre-wrap;line-height:1.55;font-size:1.08rem;color:#e2e8f0}
.meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.meta-box{padding:12px;border-radius:16px;border:1px solid rgba(148,163,184,.16);background:rgba(15,23,42,.46)}
.meta-box span{display:block;color:#94a3b8;font-size:.74rem;text-transform:uppercase;margin-bottom:4px}
.meta-box strong{color:#fff}
.relationship-row{
    display:grid;
    grid-template-columns:minmax(180px,1.2fr) minmax(110px,.6fr) minmax(240px,2fr);
    gap:12px;
    padding:12px;
    border-radius:16px;
    border:1px solid rgba(148,163,184,.16);
    background:rgba(15,23,42,.42);
    margin-bottom:10px;
}
.badge{display:inline-block;padding:5px 9px;border-radius:999px;border:1px solid rgba(147,197,253,.22);background:rgba(15,23,42,.5)}
.small{color:#94a3b8;line-height:1.45}
.asset-list{display:grid;gap:12px}
.asset-id-link{color:#bfdbfe;text-decoration:none;font-weight:1000}
.asset-id-link:hover{text-decoration:underline}
.notice,.error{padding:16px;border-radius:18px}
.notice{border:1px solid rgba(147,197,253,.28);background:rgba(30,64,175,.18);color:#dbeafe}
.error{border:1px solid rgba(248,113,113,.35);background:rgba(127,29,29,.26);color:#fecaca}
.foot{display:flex;justify-content:space-between;gap:14px;margin-top:28px;padding-top:18px;border-top:1px solid rgba(148,163,184,.16);color:#94a3b8;font-size:.9rem}
@media(max-width:760px){.relationship-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
<nav class="nav" aria-label="Primary">
    <a class="brand" href="/ipmdb/">IPMdb · IDEAS 2 ASSETS</a>
    <div class="links">
        <a href="/ipmdb/lock.php">Lock Idea</a>
        <a href="/ipmdb/ledger.php">Ledger</a>
        <a href="/ipmdb/search.php">Search</a>
        <a href="/ipmdb/relationship_explorer.php">Relationships</a>
        <a href="/ipmdb/admin.php">Admin</a>
    </div>
</nav>

<?php if ($error !== ''): ?>
    <section class="error">
        <strong>Viewer error.</strong>
        <div><?= h($error) ?></div>
    </section>

<?php elseif ($assetId === ''): ?>
    <section class="card">
        <div class="sub">Asset Viewer</div>
        <h1>Choose an Asset</h1>
        <p class="small">Open a recent asset below, or search the complete public ledger.</p>
        <div class="actions">
            <a class="primary" href="/ipmdb/search.php">Search Assets</a>
            <a href="/ipmdb/ledger.php">Open Ledger</a>
            <a href="/ipmdb/lock.php">Lock Idea</a>
        </div>
    </section>

<?php elseif ($asset === null): ?>
    <section class="notice">
        <strong>Asset not found.</strong>
        <div class="small">Check the Asset ID or choose another record below.</div>
    </section>

<?php else: ?>
    <?php $currentAssetId = ipmdb_asset_id($asset); ?>
    <?= ipmdb_render_asset_header($asset) ?>

    <section class="card">
        <div class="sub">Actions</div>
        <h2>Asset Navigation</h2>
        <div class="actions">
            <a class="primary" href="<?= h(viewer_asset_node_link($currentAssetId)) ?>">Open Asset Node</a>
            <a href="<?= h(viewer_add_relationship_link($currentAssetId)) ?>">Add Relationship</a>
            <a href="<?= h(viewer_bulk_relationship_link($currentAssetId)) ?>">Bulk Relationships</a>
            <a href="<?= h(viewer_explorer_link($currentAssetId)) ?>">Relationship Explorer</a>
            <a href="<?= h(viewer_history_link($currentAssetId)) ?>">Version History</a>
            <a href="<?= h(viewer_provenance_link($currentAssetId)) ?>">Provenance Receipt</a>
            <a href="/ipmdb/ledger.php">Ledger</a>
            <a href="/ipmdb/search.php">Search</a>
            <a href="/ipmdb/admin_edit.php?asset_id=<?= rawurlencode($currentAssetId) ?>">Edit</a>
            <?php if ($prevAsset !== null): ?>
                <a href="<?= h(viewer_asset_link(ipmdb_asset_id($prevAsset))) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($nextAsset !== null): ?>
                <a href="<?= h(viewer_asset_link(ipmdb_asset_id($nextAsset))) ?>">Next</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="sub">Asset</div>
        <h2>Idea</h2>
        <div class="idea"><?= h((string) ($asset['idea'] ?? '')) ?></div>
    </section>

    <section class="card">
        <div class="sub">Record</div>
        <h2>Metadata</h2>
        <div class="meta-grid">
            <div class="meta-box"><span>Title</span><strong><?= h(ipmdb_asset_title($asset)) ?></strong></div>
            <div class="meta-box"><span>Status</span><strong><?= h(ipmdb_asset_status($asset)) ?></strong></div>
            <div class="meta-box"><span>Category</span><strong><?= h(ipmdb_asset_category($asset)) ?></strong></div>
            <div class="meta-box"><span>Version</span><strong><?= h(ipmdb_asset_version($asset)) ?></strong></div>
            <div class="meta-box"><span>Created</span><strong><?= h(ipmdb_format_date($asset['created_at'] ?? '')) ?></strong></div>
            <div class="meta-box"><span>Updated</span><strong><?= h(ipmdb_format_date($asset['updated_at'] ?? '')) ?></strong></div>
            <div class="meta-box">
                <span>Asset ID</span>
                <strong><a class="asset-id-link" href="<?= h(viewer_asset_node_link($currentAssetId)) ?>"><?= h($currentAssetId) ?></a></strong>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="sub">Graph</div>
        <h2>Outgoing Relationships</h2>
        <?php if ($outgoing === []): ?>
            <div class="small">No outgoing relationships found.</div>
        <?php else: ?>
            <?php foreach ($outgoing as $item): ?>
                <?php
                    $targetId = (string) ($item['target_asset_id'] ?? '');
                    $type = (string) ($item['relationship_type'] ?? $item['type'] ?? 'related');
                    $note = (string) ($item['note'] ?? $item['notes'] ?? '');
                ?>
                <div class="relationship-row">
                    <div>
                        <div class="small">Target Asset</div>
                        <a class="asset-id-link" href="<?= h(viewer_asset_link($targetId)) ?>"><?= h($targetId) ?></a>
                    </div>
                    <div><span class="badge"><?= h($type) ?></span></div>
                    <div class="small">
                        <strong><?= h(ipmdb_asset_title($item)) ?></strong><br>
                        <?= h($note !== '' ? $note : viewer_excerpt((string) ($item['idea'] ?? ''), 180)) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="sub">Graph</div>
        <h2>Incoming Relationships</h2>
        <?php if ($incoming === []): ?>
            <div class="small">No incoming relationships found.</div>
        <?php else: ?>
            <?php foreach ($incoming as $item): ?>
                <?php
                    $sourceId = (string) ($item['source_asset_id'] ?? '');
                    $type = (string) ($item['relationship_type'] ?? $item['type'] ?? 'related');
                    $note = (string) ($item['note'] ?? $item['notes'] ?? '');
                ?>
                <div class="relationship-row">
                    <div>
                        <div class="small">Source Asset</div>
                        <a class="asset-id-link" href="<?= h(viewer_asset_link($sourceId)) ?>"><?= h($sourceId) ?></a>
                    </div>
                    <div><span class="badge"><?= h($type) ?></span></div>
                    <div class="small">
                        <strong><?= h(ipmdb_asset_title($item)) ?></strong><br>
                        <?= h($note !== '' ? $note : viewer_excerpt((string) ($item['idea'] ?? ''), 180)) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if ($versions !== []): ?>
        <section class="card">
            <div class="sub">History</div>
            <h2>Version History</h2>
            <div class="asset-list">
                <?php foreach ($versions as $version): ?>
                    <article class="ipmdb-asset-card">
                        <div class="ipmdb-asset-card-title"><?= h(ipmdb_asset_version($version)) ?></div>
                        <div class="ipmdb-asset-meta"><?= h(ipmdb_format_date($version['created_at'] ?? '')) ?></div>
                        <?php if (!empty($version['idea'])): ?>
                            <p class="ipmdb-asset-snippet"><?= h(viewer_excerpt((string) $version['idea'], 220)) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php if ($recent !== []): ?>
    <section class="card">
        <div class="sub">Ledger</div>
        <h2>Recent Assets</h2>
        <div class="asset-list">
            <?php foreach ($recent as $item): ?>
                <?= ipmdb_render_asset_card($item) ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<footer class="foot">
    <span><strong>IPMdb</strong></span>
    <span>Ideas 2 Assets</span>
</footer>
</main>
</body>
</html>
