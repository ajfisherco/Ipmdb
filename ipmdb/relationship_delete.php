<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_delete.php
|--------------------------------------------------------------------------
| IPMdb Relationship Delete
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';

$config = ipmdb_config();

$error = '';
$relationship = null;

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

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
            id,
            source_asset_id,
            target_asset_id,
            relationship_type
        FROM ipmdb_relationships
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $relationship = $stmt->fetch();

    if (!$relationship) {
        http_response_code(404);
        throw new RuntimeException('Relationship not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ipmdb_require_csrf();

        $delete = $pdo->prepare("
            DELETE
            FROM ipmdb_relationships
            WHERE id = ?
            LIMIT 1
        ");

        $delete->execute([$id]);

        header(
            'Location: relationships.php?asset_id=' .
            rawurlencode((string)$relationship['source_asset_id'])
        );

        exit;
    }

} catch (Throwable $e) {

    $error = ipmdb_public_error($e, 'The relationship could not be deleted.');

}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delete Relationship</title>

<style>
body{
    margin:40px;
    font-family:Arial,sans-serif;
    background:#0f172a;
    color:#e5e7eb;
}
.card{
    max-width:640px;
    margin:auto;
    padding:24px;
    border:1px solid #334155;
    border-radius:16px;
    background:#111827;
}
.buttons{
    display:flex;
    gap:12px;
    margin-top:24px;
}
button,a{
    padding:12px 18px;
    border-radius:10px;
    text-decoration:none;
    font-weight:bold;
}
button{
    background:#b91c1c;
    color:#fff;
    border:none;
    cursor:pointer;
}
a{
    background:#1e293b;
    color:#fff;
}
.error{
    color:#fecaca;
}
</style>

</head>
<body>

<div class="card">

<?php if ($error): ?>

<p class="error"><?= e($error) ?></p>

<?php else: ?>

<h1>Delete Relationship?</h1>

<p>
<strong><?= e((string)$relationship['source_asset_id']) ?></strong>
&rarr;
<strong><?= e((string)$relationship['target_asset_id']) ?></strong>
</p>

<p>
Type:
<strong><?= e((string)$relationship['relationship_type']) ?></strong>
</p>

<form method="post">

<?= ipmdb_csrf_field() ?>

<input
type="hidden"
name="id"
value="<?= e((string)$id) ?>"
>

<div class="buttons">

<button type="submit">
Delete Relationship
</button>

<a href="relationships.php?asset_id=<?= urlencode((string)$relationship['source_asset_id']) ?>">
Cancel
</a>

</div>

</form>

<?php endif; ?>

</div>

</body>
</html>
