<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/launch_check.php
| IPMdb Launch Check
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '1');
error_reporting(E_ALL);

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$root = __DIR__;

$required = [
    'index.php',
    'lock.php',
    'success.php',
    'ledger.php',
    'viewer.php',
    'asset.php',
    'assets.php',
    'search.php',
    'relationships.php',
    'relationship_add.php',
    'relationship_history.php',
    'relationship_bulk.php',
    'relationship_validate.php',
    'relationship_repair.php',
    'relationship_suggest.php',
    'admin.php',
    'admin_edit.php',
    'config.php',
];

$results = [];

foreach ($required as $file) {
    $path = $root . '/' . $file;

    $exists = is_file($path);
    $size = $exists ? filesize($path) : 0;
    $readable = $exists && is_readable($path);

    $status = 'Missing';

    if ($exists && $readable && $size > 0) {
        $status = 'Present';
    }

    if ($exists && $size === 0) {
        $status = 'Empty';
    }

    $results[] = [
        'file' => $file,
        'status' => $status,
        'size' => $size,
        'readable' => $readable ? 'Yes' : 'No',
    ];
}

$present = count(array_filter($results, fn($r) => $r['status'] === 'Present'));
$missing = count(array_filter($results, fn($r) => $r['status'] === 'Missing'));
$empty = count(array_filter($results, fn($r) => $r['status'] === 'Empty'));

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>IPMdb Launch Check</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{
    margin:0;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:#07111f;
    color:#f8fafc;
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
    margin-bottom:20px;
}
.nav a{
    color:#dbeafe;
    text-decoration:none;
    border:1px solid rgba(147,197,253,.25);
    border-radius:999px;
    padding:8px 12px;
    background:rgba(15,23,42,.55);
}
.card{
    border:1px solid rgba(148,163,184,.22);
    border-radius:24px;
    padding:20px;
    background:rgba(2,6,23,.58);
    margin-bottom:18px;
}
h1{
    margin:0 0 8px;
    font-size:clamp(32px,5vw,58px);
}
.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    gap:12px;
}
.stat{
    border:1px solid rgba(148,163,184,.18);
    border-radius:18px;
    padding:14px;
    background:rgba(15,23,42,.5);
}
.stat span{
    display:block;
    color:#94a3b8;
    text-transform:uppercase;
    letter-spacing:.08em;
    font-size:.75rem;
}
.stat strong{
    font-size:2rem;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    border-bottom:1px solid rgba(148,163,184,.18);
    padding:10px;
    text-align:left;
}
th{
    color:#93c5fd;
    text-transform:uppercase;
    letter-spacing:.08em;
    font-size:.75rem;
}
.good{color:#86efac}
.bad{color:#fca5a5}
.warn{color:#fde68a}
code{
    background:rgba(255,255,255,.08);
    border:1px solid rgba(148,163,184,.2);
    border-radius:8px;
    padding:2px 6px;
}
</style>
</head>
<body>
<div class="wrap">

<nav class="nav">
    <a href="/ipmdb/">Home</a>
    <a href="/ipmdb/lock.php">Lock</a>
    <a href="/ipmdb/ledger.php">Ledger</a>
    <a href="/ipmdb/search.php">Search</a>
    <a href="/ipmdb/relationships.php">Relationships</a>
    <a href="/ipmdb/admin.php">Admin</a>
</nav>

<section class="card">
    <h1>IPMdb Launch Check</h1>

    <div class="grid">
        <div class="stat"><span>Present</span><strong class="good"><?= e($present) ?></strong></div>
        <div class="stat"><span>Missing</span><strong class="bad"><?= e($missing) ?></strong></div>
        <div class="stat"><span>Empty</span><strong class="warn"><?= e($empty) ?></strong></div>
        <div class="stat"><span>Total Checked</span><strong><?= e(count($required)) ?></strong></div>
    </div>
</section>

<section class="card">
    <table>
        <thead>
            <tr>
                <th>File</th>
                <th>Status</th>
                <th>Size</th>
                <th>Readable</th>
                <th>Open</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $row): ?>
            <?php
                $class = $row['status'] === 'Present' ? 'good' : ($row['status'] === 'Empty' ? 'warn' : 'bad');
            ?>
            <tr>
                <td><code><?= e($row['file']) ?></code></td>
                <td class="<?= e($class) ?>"><?= e($row['status']) ?></td>
                <td><?= e((string)$row['size']) ?> bytes</td>
                <td><?= e($row['readable']) ?></td>
                <td>
                    <?php if ($row['status'] !== 'Missing'): ?>
                        <a class="good" href="/ipmdb/<?= e($row['file']) ?>">Open</a>
                    <?php else: ?>
                        <span class="bad">Unavailable</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <?php if ($missing === 0 && $empty === 0): ?>
        <p class="good">Launch skeleton is complete. Proceed to live workflow testing.</p>
    <?php else: ?>
        <p class="warn">Launch skeleton needs attention before release candidate.</p>
    <?php endif; ?>
</section>

</div>
</body>
</html>