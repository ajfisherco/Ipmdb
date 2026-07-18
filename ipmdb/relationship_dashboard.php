<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_dashboard.php
|--------------------------------------------------------------------------
| IPMdb Relationship Dashboard
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/relationship_types.php';

$config = ipmdb_config();

$error='';

$stats=[
    'assets'=>0,
    'relationships'=>0,
    'orphans'=>0,
    'today'=>0
];

$recent=[];
$types=[];
$connected=[];

try{

$pdo=new PDO(
    $config['db']['dsn'],
    $config['db']['user'],
    $config['db']['pass'],
[
PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
PDO::ATTR_EMULATE_PREPARES=>false,
]);

$stats['assets']=(int)$pdo
->query("SELECT COUNT(*) FROM ipmdb_assets")
->fetchColumn();

$stats['relationships']=(int)$pdo
->query("SELECT COUNT(*) FROM ipmdb_relationships")
->fetchColumn();

$stats['today']=(int)$pdo
->query("
SELECT COUNT(*)
FROM ipmdb_relationships
WHERE DATE(created_at)=CURDATE()
")
->fetchColumn();

$stats['orphans']=(int)$pdo
->query("
SELECT COUNT(*)
FROM ipmdb_assets a
WHERE NOT EXISTS(
SELECT 1
FROM ipmdb_relationships r
WHERE
r.source_asset_id=a.asset_id
OR
r.target_asset_id=a.asset_id
)
")
->fetchColumn();

$stmt=$pdo->query("
SELECT
relationship_type,
COUNT(*) total
FROM ipmdb_relationships
GROUP BY relationship_type
ORDER BY total DESC
");

$types=$stmt->fetchAll();

$stmt=$pdo->query("
SELECT
a.asset_id,
a.title,
COUNT(*) links
FROM ipmdb_assets a
LEFT JOIN ipmdb_relationships r
ON
a.asset_id=r.source_asset_id
OR
a.asset_id=r.target_asset_id
GROUP BY a.asset_id,a.title
ORDER BY links DESC
LIMIT 20
");

$connected=$stmt->fetchAll();

$stmt=$pdo->query("
SELECT
r.*,
s.title source_title,
t.title target_title
FROM ipmdb_relationships r
LEFT JOIN ipmdb_assets s
ON s.asset_id=r.source_asset_id
LEFT JOIN ipmdb_assets t
ON t.asset_id=r.target_asset_id
ORDER BY r.created_at DESC
LIMIT 20
");

$recent=$stmt->fetchAll();

}catch(Throwable $e){

error_log('IPMdb relationship dashboard failed: ' . $e->getMessage());
$error='The relationship dashboard could not be loaded.';

}

?><!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<title>

Relationship Dashboard

</title>

<style>

body{

margin:0;
padding:32px;
background:#07111f;
color:#fff;
font:16px Arial;

}

.grid{

display:grid;
grid-template-columns:
repeat(auto-fit,minmax(220px,1fr));
gap:20px;

}

.card{

background:#132235;
padding:22px;
border-radius:18px;

}

.card h2{

margin:0 0 10px;

}

.big{

font-size:46px;
font-weight:bold;

}

table{

width:100%;
border-collapse:collapse;

}

td,th{

padding:10px;
border-bottom:1px solid #29405b;
text-align:left;

}

a{

color:#80d7ff;

}

.section{

margin-top:40px;

}

</style>

</head>

<body>

<h1>

Relationship Dashboard

</h1>

<?php if($error): ?>

<p><?=htmlspecialchars($error)?></p>

<?php else: ?>

<div class="grid">

<div class="card">

<h2>Assets</h2>

<div class="big">

<?=$stats['assets']?>

</div>

</div>

<div class="card">

<h2>Relationships</h2>

<div class="big">

<?=$stats['relationships']?>

</div>

</div>

<div class="card">

<h2>Orphans</h2>

<div class="big">

<?=$stats['orphans']?>

</div>

</div>

<div class="card">

<h2>Today</h2>

<div class="big">

<?=$stats['today']?>

</div>

</div>

</div><div class="section">

<h2>

Relationship Types

</h2>

<table>

<tr>

<th>Type</th>
<th>Count</th>

</tr>

<?php foreach($types as $type): ?>

<tr>

<td>

<?=htmlspecialchars($type['relationship_type'])?>

</td>

<td>

<?=$type['total']?>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<div class="section">

<h2>

Most Connected Assets

</h2>

<table>

<tr>

<th>Asset</th>
<th>Connections</th>

</tr>

<?php foreach($connected as $row): ?>

<tr>

<td>

<a href="relationships.php?asset_id=<?=urlencode($row['asset_id'])?>">

<?=htmlspecialchars(
$row['title'] ?: $row['asset_id']
)?>

</a>

</td>

<td>

<?=$row['links']?>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<div class="section">

<h2>

Recent Relationships

</h2>

<table>

<tr>

<th>Source</th>
<th>Type</th>
<th>Target</th>
<th>Date</th>

</tr>

<?php foreach($recent as $row): ?>

<tr>

<td>

<a href="viewer.php?asset_id=<?=urlencode($row['source_asset_id'])?>">

<?=htmlspecialchars(
$row['source_title'] ?: $row['source_asset_id']
)?>

</a>

</td>

<td>

<?=htmlspecialchars($row['relationship_type'])?>

</td>

<td>

<a href="viewer.php?asset_id=<?=urlencode($row['target_asset_id'])?>">

<?=htmlspecialchars(
$row['target_title'] ?: $row['target_asset_id']
)?>

</a>

</td>

<td>

<?=htmlspecialchars($row['created_at'])?>

</td>

</tr>

<?php endforeach; ?>

</table>

</div><div class="section">

<h2>

Quick Actions

</h2>

<div class="grid">

<div class="card">
<a href="relationship_explorer.php">
Relationship Explorer
</a>
</div>

<div class="card">
<a href="relationship_add.php">
Add Relationship
</a>
</div>

<div class="card">
<a href="relationship_import.php">
Import Relationships
</a>
</div>

<div class="card">
<a href="relationship_export.php?format=json">
Export JSON
</a>
</div>

<div class="card">
<a href="relationship_export.php?format=csv">
Export CSV
</a>
</div>

<div class="card">
<a href="ledger.php">
Asset Ledger
</a>
</div>

</div>

</div>

<?php endif; ?>

</body>

</html>
