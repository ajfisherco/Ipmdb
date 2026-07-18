<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/relationship_functions.php';
if (!function_exists('ipmdb_config')) {
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
}

$assetId = trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? ''));
$type    = trim((string)($_GET['type'] ?? ''));
$q       = trim((string)($_GET['q'] ?? ''));

$error = '';
$focusAsset = null;

$graphData = [
    'focus_asset_id' => '',
    'assets' => [],
    'relationships' => [],
    'counts' => [
        'assets' => 0,
        'relationships' => 0,
    ],
];

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

    if ($assetId !== '') {
        $focusAsset = ipmdb_get_asset($pdo, $assetId);
    }

    if ($q !== '') {
        $assets = ipmdb_search_assets($pdo, $q, 50);
        $relationships = ipmdb_get_relationships($pdo, null);

        if ($type !== '') {
            $relationships = ipmdb_rel_filter_relationships_by_type($relationships, $type);
        }

        $allowed = [];
        foreach ($assets as $asset) {
            if (!empty($asset['asset_id'])) {
                $allowed[(string)$asset['asset_id']] = true;
            }
        }

        $relationships = array_values(array_filter($relationships, static function (array $rel) use ($allowed): bool {
            return isset($allowed[(string)($rel['source_asset_id'] ?? '')])
                || isset($allowed[(string)($rel['target_asset_id'] ?? '')]);
        }));

        $graphData = [
            'focus_asset_id' => $assetId,
            'assets' => $assets,
            'relationships' => $relationships,
            'counts' => [
                'assets' => count($assets),
                'relationships' => count($relationships),
            ],
        ];
    } else {
        $graphData = ipmdb_rel_prepare_graph(
            $pdo,
            $assetId !== '' ? $assetId : null,
            $type !== '' ? $type : null,
            100
        );
    }

    if (!$focusAsset && $assetId !== '') {
        $error = 'Focus asset was not found.';
    }
} catch (Throwable $e) {
    error_log('IPMdb relationship explorer failed: ' . $e->getMessage());
    $error = 'The relationship graph could not be loaded.';
}

$pageTitle = ipmdb_rel_page_title($assetId);
$assets = $graphData['assets'] ?? [];
$relationships = $graphData['relationships'] ?? [];
$graphJson = ipmdb_rel_graph_json($graphData);

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo ipmdb_rel_h($pageTitle); ?> · IPMdb.ai</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/ipmdb/assets/css/relationship_graph.css">
</head>
<body>

<div class="ipmdb-shell">

<header class="topbar">
    <div>
        <a class="brand" href="/ipmdb/">IPMdb.ai</a>
        <p>IDEAS 2 ASSETS</p>
    </div>

    <nav>
        <a href="/ipmdb/search.php">Search</a>
        <a href="/ipmdb/ledger.php">Ledger</a>
        <a href="/ipmdb/viewer.php">Viewer</a>
        <a href="/ipmdb/relationship_explorer.php">Explorer</a>
        <a href="/ipmdb/admin.php">Admin</a>
        <a href="/ipmdb/lock.php">Lock Idea</a>
    </nav>
</header>

<main class="explorer">

<section class="hero">
    <div>
        <p class="eyebrow">Relationship Explorer</p>
        <h1>Asset Domain Map</h1>
        <p class="lede">
            Explore how IPMdb assets connect, depend, document, implement, and evolve together.
        </p>
    </div>

    <div class="hero-actions">
        <a href="/ipmdb/relationship_add.php<?php echo $assetId !== '' ? '?asset_id=' . rawurlencode($assetId) : ''; ?>">
            Add Relationship
        </a>
        <a href="/ipmdb/relationships.php">Relationship Editor</a>
    </div>
</section>

<?php if ($error !== ''): ?>
<section class="notice error">
    <?php echo ipmdb_rel_h($error); ?>
</section>
<?php endif; ?>

<section class="search-panel">
    <form method="get" action="/ipmdb/relationship_explorer.php">
        <label for="q">Search Assets</label>
        <div class="search-row">
            <input id="q" name="q" value="<?php echo ipmdb_rel_h($q); ?>" placeholder="Search assets, ideas, categories or Asset ID">
            <?php if ($assetId !== ''): ?>
                <input type="hidden" name="asset_id" value="<?php echo ipmdb_rel_h($assetId); ?>">
            <?php endif; ?>
            <?php if ($type !== ''): ?>
                <input type="hidden" name="type" value="<?php echo ipmdb_rel_h($type); ?>">
            <?php endif; ?>
            <button type="submit">Search</button>
            <a class="clear" href="/ipmdb/relationship_explorer.php">Reset</a>
        </div>
    </form>
</section>

<?php echo ipmdb_rel_render_stats($graphData); ?>

<section class="layout-grid">

<aside class="sidebar">
    <section class="panel">
        <h2>Filters</h2>
        <?php echo ipmdb_rel_render_type_filters($type, $assetId); ?>
    </section>

    <section class="panel">
        <h2>Focus</h2>

        <?php if ($focusAsset): ?>
            <?php echo ipmdb_rel_render_asset_card($focusAsset, $assetId); ?>
        <?php elseif ($assetId !== ''): ?>
            <p class="muted">No asset found for <?php echo ipmdb_rel_h($assetId); ?>.</p>
        <?php else: ?>
            <p class="muted">No focus asset selected.</p>
        <?php endif; ?>
    </section>
</aside>

<section class="graph-zone">
    <div class="graph-toolbar">
        <div>
            <h2>Relationship Graph</h2>
            <p><?php echo number_format(count($assets)); ?> Assets · <?php echo number_format(count($relationships)); ?> Relationships</p>
        </div>

        <div class="graph-buttons">
            <button type="button" data-graph-action="fit">Fit</button>
            <button type="button" data-graph-action="reset">Reset</button>
            <button type="button" data-graph-action="labels">Labels</button>
        </div>
    </div>

    <div id="relationshipGraph"
         class="relationship-graph"
         data-api="/ipmdb/relationship_api.php<?php
            $params = [];
            if ($assetId !== '') {
                $params['asset_id'] = $assetId;
            }
            if ($type !== '') {
                $params['type'] = $type;
            }
            if ($params) {
                echo '?' . http_build_query($params);
            }
         ?>">
        <noscript>
            JavaScript is required for the interactive graph.
        </noscript>
    </div>

    <script type="application/json" id="relationshipGraphData"><?php echo $graphJson; ?></script>
</section>

<aside class="details-panel">
    <section class="panel selected-panel">
        <h2>Selected Asset</h2>

        <div id="selectedAssetBox" class="selected-box">
            <?php if ($focusAsset): ?>
                <p class="asset-code"><?php echo ipmdb_rel_h($assetId); ?></p>
                <h3><?php echo ipmdb_rel_h((string)($focusAsset['title'] ?? $assetId)); ?></h3>

                <dl>
                    <dt>Category</dt>
                    <dd><?php echo ipmdb_rel_h(ipmdb_rel_category_label((string)($focusAsset['category'] ?? 'Uncategorized'))); ?></dd>

                    <dt>Status</dt>
                    <dd><?php echo ipmdb_rel_h(ipmdb_rel_status_label((string)($focusAsset['status'] ?? 'Draft'))); ?></dd>

                    <dt>Version</dt>
                    <dd><?php echo ipmdb_rel_h((string)($focusAsset['version'] ?? '1.0')); ?></dd>
                </dl>

                <p><?php echo ipmdb_rel_h(ipmdb_rel_trim_words((string)($focusAsset['idea'] ?? ''), 260)); ?></p>

                <div class="detail-actions">
                    <a href="<?php echo ipmdb_rel_h(ipmdb_rel_asset_url($assetId)); ?>">View</a>
                    <a href="<?php echo ipmdb_rel_h(ipmdb_rel_edit_url($assetId)); ?>">Edit</a>
                    <a href="<?php echo ipmdb_rel_h(ipmdb_rel_history_url($assetId)); ?>">History</a>
                    <a href="<?php echo ipmdb_rel_h(ipmdb_rel_add_url($assetId)); ?>">Relate</a>
                </div>
            <?php else: ?>
                <p class="muted">Click an asset node to inspect it here.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <h2>Relationships</h2>
        <?php echo ipmdb_rel_render_relationship_list($relationships, $assets); ?>
    </section>
</aside>

</section>

<section class="asset-strip">
    <div class="section-head">
        <div>
            <h2>Assets in View</h2>
            <p>Current graph scope.</p>
        </div>
        <a href="/ipmdb/ledger.php">Open Ledger</a>
    </div>

    <?php echo ipmdb_rel_render_asset_list($assets, $assetId); ?>
</section>

</main>

<footer class="footer">
    <strong>IPMdb.ai</strong>
    <span>Ideas 2 Assets</span>
</footer>
</div>

<script src="https://unpkg.com/cytoscape@3.30.4/dist/cytoscape.min.js"></script>
<script src="/ipmdb/assets/js/relationship_graph.js"></script>

</body>
</html>
