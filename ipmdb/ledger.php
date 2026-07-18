<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/ledger.php
|--------------------------------------------------------------------------
| IPMdb Asset Ledger
| Ideas 2 Assets
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/functions.php';

function ipmdb_ledger_config(): array
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

try {
    $config = ipmdb_ledger_config();

    if (
        !isset($config['db'])
        || !is_array($config['db'])
        || !isset($config['db']['dsn'], $config['db']['user'], $config['db']['pass'])
    ) {
        throw new RuntimeException('Database configuration is incomplete.');
    }

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

} catch (Throwable $e) {
    error_log('IPMdb ledger database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The IPMdb ledger is temporarily unavailable.');
}

$search   = trim((string)($_GET['q'] ?? ''));
$status   = trim((string)($_GET['status'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));

$where  = [];
$params = [];

if ($search !== '') {
    $searchParts = [];

    foreach (['asset_id', 'title', 'idea', 'category', 'status'] as $index => $field) {
        $parameter = 'search_' . $index;
        $searchParts[] = $field . ' LIKE :' . $parameter;
        $params[$parameter] = '%' . $search . '%';
    }

    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

if ($status !== '') {

    $where[] = "status = :status";
    $params['status'] = $status;

}

if ($category !== '') {

    $where[] = "category = :category";
    $params['category'] = $category;

}

$sql = "

SELECT
    asset_id,
    title,
    category,
    status,
    version,
    idea,
    created_at

FROM ipmdb_assets

";

if ($where) {

    $sql .= " WHERE " . implode(' AND ', $where);

}

$sql .= "

ORDER BY created_at DESC

LIMIT 500

";

try {
    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    $stmt->execute();
    $assets = $stmt->fetchAll();

    $categoryStmt = $pdo->query("
        SELECT DISTINCT category
        FROM ipmdb_assets
        WHERE category IS NOT NULL
          AND category <> ''
        ORDER BY category ASC
    ");

    $categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

    $statusStmt = $pdo->query("
        SELECT DISTINCT status
        FROM ipmdb_assets
        WHERE status IS NOT NULL
          AND status <> ''
        ORDER BY status ASC
    ");

    $statuses = $statusStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('IPMdb ledger query failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The IPMdb ledger is temporarily unavailable.');
}

?><!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<title>IPMdb Ledger</title>

<?= ipmdb_render_asset_styles() ?>

<style>

:root{

--bg:#07111d;
--panel:#0f1b2c;
--line:#20344e;
--text:#eef5ff;
--muted:#8ea8c7;
--blue:#58a6ff;

}

*{

box-sizing:border-box;

}

body{

margin:0;
background:linear-gradient(135deg,#020617,#0f172a,#07111d);
color:var(--text);
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;

}

main{

width:min(1500px,96vw);
margin:auto;
padding:24px;

}

header{

display:flex;
justify-content:space-between;
align-items:center;
gap:18px;
margin-bottom:28px;

}

.logo{

font-size:2rem;
font-weight:900;

}

nav{

display:flex;
gap:10px;
flex-wrap:wrap;

}

nav a{

text-decoration:none;
color:white;
padding:10px 14px;
border-radius:999px;
border:1px solid var(--line);

}

.hero{

margin-bottom:24px;

}

.hero h1{

margin:0;
font-size:clamp(2rem,5vw,4rem);

}

.hero p{

margin-top:10px;
color:var(--muted);

}

.searchbar{

display:grid;
grid-template-columns:2fr 1fr 1fr auto;
gap:12px;
margin:26px 0;

}

.searchbar input,
.searchbar select{

background:#091321;
color:white;
border:1px solid var(--line);
padding:12px;
border-radius:14px;

}

.searchbar button{

background:#2563eb;
color:white;
border:none;
padding:12px 22px;
border-radius:14px;
cursor:pointer;
font-weight:700;

}

.table{

border:1px solid var(--line);
border-radius:22px;
overflow-x:auto;
-webkit-overflow-scrolling:touch;

}

table{

width:100%;
border-collapse:collapse;

}

thead{

background:#132236;

}

th{

text-align:left;
padding:14px;
font-size:.8rem;
letter-spacing:.08em;
text-transform:uppercase;
color:#bcd2ef;

}

td{

padding:16px;
vertical-align:top;
border-top:1px solid rgba(255,255,255,.05);

}

tr:hover{

background:rgba(88,166,255,.05);

}

.asset-title{

font-size:1.15rem;
font-weight:900;
margin-bottom:6px;

}

.asset-id{

font-size:.72rem;
color:var(--muted);

}

.asset-link{

display:block;
color:inherit;
text-decoration:none;
border-radius:12px;
padding:4px;
margin:-4px;

}

.asset-link:hover .asset-title,
.asset-link:focus-visible .asset-title{

color:var(--blue);

}

.asset-link:focus-visible{

outline:3px solid var(--blue);
outline-offset:4px;

}

.asset-row.is-clickable,
.asset-row.is-clickable td,
.asset-row.is-clickable .asset-link{

pointer-events:auto !important;

}

.asset-row.is-clickable{

cursor:pointer;

}

.open-asset{

display:inline-flex;
align-items:center;
justify-content:center;
margin-top:16px;
padding:10px 16px;
border-radius:999px;
background:#2563eb;
color:white;
font-size:.82rem;
font-weight:900;
letter-spacing:.04em;
text-decoration:none;
position:relative;
z-index:5;

}

.open-asset:hover,
.open-asset:focus-visible{

background:#1d4ed8;
outline:3px solid var(--blue);
outline-offset:3px;

}

.idea{

margin-top:8px;
color:#cfdceb;
line-height:1.45;

}

.badge{

display:inline-block;
padding:6px 10px;
border-radius:999px;
border:1px solid var(--line);
background:rgba(37,99,235,.12);
color:#dbeafe;
font-size:.82rem;
font-weight:700;

}

.badge.draft{

background:rgba(245,158,11,.12);
color:#fbbf24;

}

.badge.active{

background:rgba(34,197,94,.12);
color:#4ade80;

}

.badge.archived{

background:rgba(148,163,184,.12);
color:#cbd5e1;

}

.badge.review{

background:rgba(168,85,247,.12);
color:#d8b4fe;

}

.btn{

display:inline-flex;
align-items:center;
justify-content:center;
gap:6px;
padding:8px 12px;
border-radius:999px;
border:1px solid var(--line);
background:#132236;
color:white;
text-decoration:none;
font-size:.85rem;
font-weight:700;
transition:.18s;

}

.btn:hover{

background:#1d4ed8;
border-color:#3b82f6;

}

.actions{

display:flex;
flex-wrap:wrap;
gap:8px;

}

.empty{

padding:48px;
text-align:center;
color:var(--muted);
font-size:1.05rem;

}

footer{

margin-top:36px;
padding-top:20px;
border-top:1px solid var(--line);
display:flex;
justify-content:space-between;
color:var(--muted);

}

@media (max-width:1000px){

.searchbar{

grid-template-columns:1fr;

}

table{

min-width:900px;

}

}

</style>

</head>

<body>

<main>

<header>

<div class="logo">
IPMdb
</div>

<nav>

<a href="/ipmdb/lock.php">Lock Idea</a>

<a href="/ipmdb/search.php">Search</a>

<a href="/ipmdb/relationship_explorer.php">Relationships</a>

<a href="/ipmdb/admin.php">Admin</a>

</nav>

</header><section class="hero">

<h1>Asset Ledger</h1>

<p>
Browse locked ideas as assets. Titles lead. Asset IDs remain visible for precision.
</p>

</section>

<form class="searchbar" method="get" action="/ipmdb/ledger.php">

<input
type="search"
name="q"
placeholder="Search title, idea, category, status, or Asset ID"
value="<?= h($search) ?>">

<select name="status">

<option value="">All Statuses</option>

<?php foreach ($statuses as $option): ?>

<option
value="<?= h((string)$option) ?>"
<?= $status === (string)$option ? 'selected' : '' ?>>
<?= h((string)$option) ?>
</option>

<?php endforeach; ?>

</select>

<select name="category">

<option value="">All Categories</option>

<?php foreach ($categories as $option):
?>

<option
value="<?= h((string)$option) ?>"
<?= $category === (string)$option ? 'selected' : '' ?>>
<?= h((string)$option) ?>
</option>

<?php endforeach; ?>

</select>

<button type="submit">
Search
</button>

</form>

<section class="table">

<?php if (!$assets): ?>

<div class="empty">
No assets found.
</div>

<?php else: ?>

<table>

<thead>

<tr>

<th>Asset</th>

<th>Category</th>

<th>Status</th>

<th>Version</th>

<th>Created</th>

<th>Actions</th>

</tr>

</thead>

<tbody><?php foreach ($assets as $asset): ?>

<?php

$id = ipmdb_asset_id($asset);

$title = ipmdb_asset_title($asset);

$viewerUrl = $id !== ''
    ? '/ipmdb/viewer.php?asset_id=' . rawurlencode($id)
    : '';

$idea = trim((string)($asset['idea'] ?? ''));

$ideaLength = function_exists('mb_strlen') ? mb_strlen($idea) : strlen($idea);

if ($ideaLength > 140) {
    $idea = (function_exists('mb_substr')
        ? mb_substr($idea, 0, 139)
        : substr($idea, 0, 139)) . '…';
}

$statusValue = strtolower(trim((string)($asset['status'] ?? 'draft')));

$badgeClass = 'badge';

if (str_contains($statusValue, 'draft')) {
    $badgeClass .= ' draft';
} elseif (str_contains($statusValue, 'archived')) {
    $badgeClass .= ' archived';
} elseif (str_contains($statusValue, 'review') || str_contains($statusValue, 'proposed')) {
    $badgeClass .= ' review';
} else {
    $badgeClass .= ' active';
}

?>

<tr
class="asset-row<?= $id !== '' ? ' is-clickable' : '' ?>"
<?php if ($id !== ''): ?>
data-viewer-url="<?= h($viewerUrl) ?>"
tabindex="0"
role="link"
aria-label="View <?= h($title) ?>"
<?php endif; ?>
>

<td>

<?php if ($id !== ''): ?>

<a
class="asset-link"
href="<?= h($viewerUrl) ?>"
aria-label="View <?= h($title) ?>">

<?php else: ?>

<div class="asset-link">

<?php endif; ?>

<div class="asset-title">
<?= h($title) ?>
</div>

<?php if ($id !== ''): ?>

<div class="asset-id">
<?= h($id) ?>
</div>

<?php endif; ?>

<?php if ($idea !== ''): ?>

<div class="idea">
<?= h($idea) ?>
</div>

<?php endif; ?>

<?php if ($id !== ''): ?>

</a>

<?php else: ?>

</div>

<?php endif; ?>

<?php if ($id !== ''): ?>

<a
class="open-asset"
href="<?= h($viewerUrl) ?>">
Open Asset
</a>

<?php endif; ?>

</td>

<td>
<?= h(ipmdb_asset_category($asset)) ?>
</td>

<td>
<span class="<?= h($badgeClass) ?>">
<?= h(ipmdb_asset_status($asset)) ?>
</span>
</td>

<td>
<?= h(ipmdb_asset_version($asset)) ?>
</td>

<td>
<?= h(ipmdb_format_date((string)($asset['created_at'] ?? ''))) ?>
</td>

<td>

<div class="actions">

<?php if ($id !== ''): ?>

<a
class="btn"
href="/ipmdb/viewer.php?asset_id=<?= rawurlencode($id) ?>">
View
</a>

<a
class="btn"
href="/ipmdb/edit.php?asset_id=<?= rawurlencode($id) ?>">
Edit
</a>

<a
class="btn"
href="/ipmdb/relationship_add.php?asset_id=<?= rawurlencode($id) ?>">
Relate
</a>

<?php else: ?>

<span aria-hidden="true">—</span>

<?php endif; ?>

</div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</section>

<footer>

<span><strong>IPMdb</strong> · Ideas 2 Assets</span>

<span>Every idea has value.</span>

</footer>

</main>

<script>
(() => {
    const openRow = (row) => {
        const url = row.dataset.viewerUrl || '';

        if (url !== '') {
            window.location.assign(url);
        }
    };

    document
        .querySelectorAll('.asset-row[data-viewer-url]')
        .forEach((row) => {
            row.addEventListener('click', (event) => {
                const control = event.target.closest(
                    'a, button, input, select, textarea'
                );

                if (control) {
                    return;
                }

                openRow(row);
            });

            row.addEventListener('keydown', (event) => {
                if (
                    event.target !== row
                    || (event.key !== 'Enter' && event.key !== ' ')
                ) {
                    return;
                }

                event.preventDefault();
                openRow(row);
            });
        });
})();
</script>

</body>

</html>
