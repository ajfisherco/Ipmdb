<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_matrix.php
|--------------------------------------------------------------------------
| IPMdb Relationship Matrix
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';

$config = ipmdb_config();

$error = '';

$assets = [];
$matrix = [];

try {

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

    $assets = $pdo->query("
        SELECT
            asset_id,
            title
        FROM ipmdb_assets
        ORDER BY asset_id
    ")->fetchAll();

    $relationships = $pdo->query("
        SELECT
            source_asset_id,
            target_asset_id,
            relationship_type
        FROM ipmdb_relationships
    ")->fetchAll();

    foreach ($relationships as $row) {

        $matrix[
            $row['source_asset_id']
        ][
            $row['target_asset_id']
        ] = $row['relationship_type'];

    }

} catch (Throwable $e) {

    error_log('IPMdb relationship matrix failed: ' . $e->getMessage());
    $error = 'The relationship matrix could not be loaded.';

}

?>
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<title>

Relationship Matrix

</title>

<style>

body{
margin:20px;
font:14px Arial;
background:#07111f;
color:#fff;
}

table{

border-collapse:collapse;

}

th,
td{

border:1px solid #29405b;

padding:6px;

text-align:center;

}

th{

background:#132235;

position:sticky;
top:0;

}

td:first-child{

background:#132235;

font-weight:bold;

position:sticky;
left:0;

}

.yes{

background:#2563eb;

}

a{

color:#fff;

text-decoration:none;

}

.wrap{

overflow:auto;

max-height:85vh;

}

</style>

</head>

<body>

<h1>

Relationship Matrix

</h1>

<?php if($error): ?>

<p>

<?=htmlspecialchars($error)?>

</p>

<?php else: ?>

<div class="wrap">

<table>

<tr>

<th></th>

<?php foreach($assets as $column): ?>

<th>

<?=htmlspecialchars(
$column['title'] ?: $column['asset_id']
)?>

</th>

<?php endforeach; ?>

</tr>

<?php foreach($assets as $row): ?>

<tr>

<td>

<?=htmlspecialchars(
$row['title'] ?: $row['asset_id']
)?>

</td><?php foreach($assets as $column): ?>

<?php
$source = (string)$row['asset_id'];
$target = (string)$column['asset_id'];
$type = (string)($matrix[$source][$target] ?? '');
?>

<?php if($type !== ''): ?>

<td class="yes">

<a
href="relationships.php?asset_id=<?=urlencode($source)?>"
title="<?=htmlspecialchars($type)?>"
>

<?=htmlspecialchars($type)?>

</a>

</td>

<?php elseif($source === $target): ?>

<td>

—

</td>

<?php else: ?>

<td>

<a
href="relationship_add.php?asset_id=<?=urlencode($source)?>"
title="Create relationship"
>

+

</a>

</td>

<?php endif; ?>

<?php endforeach; ?>

</tr>

<?php endforeach; ?>

</table>

</div>

<?php endif; ?>

<p>

<a href="relationship_explorer.php">Explorer</a>
 ·
<a href="relationship_dashboard.php">Dashboard</a>
 ·
<a href="relationship_export.php?format=csv">Export CSV</a>

</p>

</body>

</html>
