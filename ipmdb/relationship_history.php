<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_history.php
|--------------------------------------------------------------------------
| IPMdb Relationship History
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';

$config = ipmdb_config();

$error = '';
$relationship = null;

$id = (int)($_GET['id'] ?? 0);

try {
    if ($id < 1) {
        throw new RuntimeException('Relationship not specified.');
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

    $stmt = $pdo->prepare("
        SELECT
            r.*,
            s.title AS source_title,
            t.title AS target_title
        FROM ipmdb_relationships r
        LEFT JOIN ipmdb_assets s
            ON s.asset_id = r.source_asset_id
        LEFT JOIN ipmdb_assets t
            ON t.asset_id = r.target_asset_id
        WHERE r.id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);
    $relationship = $stmt->fetch();

    if (!$relationship) {
        http_response_code(404);
        throw new RuntimeException('Relationship not found.');
    }

} catch (Throwable $e) {
    error_log('IPMdb relationship history failed: ' . $e->getMessage());
    $error = 'The relationship history could not be loaded.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Relationship History · IPMdb</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:40px;background:#07111f;color:#e5e7eb;font:16px Arial,sans-serif}
.card{max-width:900px;margin:auto;padding:24px;background:#132235;border-radius:18px}
a{color:#7dd3fc}
.bad{color:#fca5a5}
.muted{color:#94a3b8}
.row{padding:14px 0;border-bottom:1px solid #29405b}
pre{white-space:pre-wrap;background:#07111f;padding:14px;border-radius:12px}
</style>
</head>
<body>

<div class="card">

<h1>Relationship History</h1>

<?php if ($error): ?>

<p class="bad"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>

<?php else: ?>

<div class="row">
<strong>ID:</strong>
<?= htmlspecialchars((string)$relationship['id'], ENT_QUOTES, 'UTF-8') ?>
</div>

<div class="row">
<strong>Source:</strong>
<a href="viewer.php?asset_id=<?= urlencode((string)$relationship['source_asset_id']) ?>">
<?= htmlspecialchars((string)($relationship['source_title'] ?: $relationship['source_asset_id']), ENT_QUOTES, 'UTF-8') ?>
</a>
</div>

<div class="row">
<strong>Target:</strong>
<a href="viewer.php?asset_id=<?= urlencode((string)$relationship['target_asset_id']) ?>">
<?= htmlspecialchars((string)($relationship['target_title'] ?: $relationship['target_asset_id']), ENT_QUOTES, 'UTF-8') ?>
</a>
</div>

<div class="row">
<strong>Type:</strong>
<?= htmlspecialchars((string)$relationship['relationship_type'], ENT_QUOTES, 'UTF-8') ?>
</div>

<div class="row">
<strong>Created:</strong>
<?= htmlspecialchars((string)($relationship['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
</div>

<div class="row">
<strong>Note:</strong>
<pre><?= htmlspecialchars((string)($relationship['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre>
</div>

<p class="muted">
Full version history is not available until relationship edit logging is added.
This page currently shows the current recorded relationship state.
</p>

<p>
<a href="relationship_edit.php?id=<?= urlencode((string)$relationship['id']) ?>">Edit</a>
 ·
<a href="relationships.php?asset_id=<?= urlencode((string)$relationship['source_asset_id']) ?>">Relationships</a>
 ·
<a href="relationship_explorer.php?asset_id=<?= urlencode((string)$relationship['source_asset_id']) ?>">Explorer</a>
</p>

<?php endif; ?>

</div>

</body>
</html>
