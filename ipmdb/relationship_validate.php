<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/relationship_types.php';

$config = ipmdb_config();

$error = '';
$issues = [];

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $relationships = $pdo->query("
        SELECT *
        FROM ipmdb_relationships
        ORDER BY id
    ")->fetchAll();

    $assetExists = $pdo->prepare("
        SELECT 1
        FROM ipmdb_assets
        WHERE asset_id = ?
        LIMIT 1
    ");

    $seen = [];

    foreach ($relationships as $row) {
        $id = (int)($row['id'] ?? 0);
        $source = trim((string)($row['source_asset_id'] ?? ''));
        $target = trim((string)($row['target_asset_id'] ?? ''));
        $type = trim((string)($row['relationship_type'] ?? ''));

        if ($source === '') {
            $issues[] = ['id' => $id, 'problem' => 'Missing source asset ID'];
            continue;
        }

        if ($target === '') {
            $issues[] = ['id' => $id, 'problem' => 'Missing target asset ID'];
            continue;
        }

        $assetExists->execute([$source]);
        if (!$assetExists->fetchColumn()) {
            $issues[] = ['id' => $id, 'problem' => 'Source asset does not exist'];
        }

        $assetExists->execute([$target]);
        if (!$assetExists->fetchColumn()) {
            $issues[] = ['id' => $id, 'problem' => 'Target asset does not exist'];
        }

        if ($source === $target) {
            $issues[] = ['id' => $id, 'problem' => 'Asset points to itself'];
        }

        if ($type === '' || !ipmdb_relationship_type_exists($type)) {
            $issues[] = ['id' => $id, 'problem' => 'Invalid relationship type'];
        }

        $key = $source . '|' . $target . '|' . $type;

        if (isset($seen[$key])) {
            $issues[] = ['id' => $id, 'problem' => 'Duplicate relationship'];
        }

        $seen[$key] = true;
    }
} catch (Throwable $e) {
    error_log('IPMdb relationship validation failed: ' . $e->getMessage());
    $error = 'The relationship validation report could not be loaded.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Relationship Validator</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:40px;background:#07111f;color:#fff;font:15px Arial}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #284057}
a{color:#7dd3fc}
.good{color:#86efac}
.bad{color:#fca5a5}
</style>
</head>
<body>

<h1>Relationship Validator</h1>

<?php if ($error !== ''): ?>

<p class="bad"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>

<?php elseif (!$issues): ?>

<p class="good">No validation issues found.</p>

<?php else: ?>

<table>
<tr>
<th>ID</th>
<th>Problem</th>
<th>Action</th>
</tr>

<?php foreach ($issues as $issue): ?>
<tr>
<td><?= htmlspecialchars((string)$issue['id'], ENT_QUOTES, 'UTF-8') ?></td>
<td><?= htmlspecialchars($issue['problem'], ENT_QUOTES, 'UTF-8') ?></td>
<td>
<a href="relationship_edit.php?id=<?= urlencode((string)$issue['id']) ?>">Edit</a>
 |
<a href="relationship_delete.php?id=<?= urlencode((string)$issue['id']) ?>">Delete</a>
</td>
</tr>
<?php endforeach; ?>

</table>

<?php endif; ?>

</body>
</html>
