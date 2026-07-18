<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_edit.php
|--------------------------------------------------------------------------
| IPMdb Relationship Editor
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/relationship_types.php';

$config = ipmdb_config();

$error = '';
$notice = '';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$relationship = null;
$assets = [];

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

    if ($id < 1) {
        throw new RuntimeException('Relationship not specified.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM ipmdb_relationships
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $relationship = $stmt->fetch();

    if (!$relationship) {
        throw new RuntimeException('Relationship not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ipmdb_require_csrf();

        if (isset($_POST['delete'])) {

            $delete = $pdo->prepare("
                DELETE
                FROM ipmdb_relationships
                WHERE id = ?
                LIMIT 1
            ");

            $delete->execute([$id]);

            header(
                'Location: relationships.php?asset_id=' .
                rawurlencode(
                    (string)$relationship['source_asset_id']
                )
            );

            exit;

        }

        $source =
            trim((string)($_POST['source_asset_id'] ?? ''));

        $target =
            trim((string)($_POST['target_asset_id'] ?? ''));

        $type =
            trim((string)($_POST['relationship_type'] ?? 'relates_to'));

        $note =
            trim((string)($_POST['note'] ?? ''));

        if ($source === '' || $target === '') {
            throw new RuntimeException(
                'Source and Target assets are required.'
            );
        }

        if ($source === $target) {
            throw new RuntimeException(
                'An asset cannot reference itself.'
            );
        }

        if (!ipmdb_relationship_type_exists($type)) {
            throw new RuntimeException('Choose a valid relationship type.');
        }

        $update = $pdo->prepare("
            UPDATE ipmdb_relationships

            SET

                source_asset_id = ?,
                target_asset_id = ?,
                relationship_type = ?,
                note = ?

            WHERE id = ?

            LIMIT 1
        ");

        $update->execute([
            $source,
            $target,
            $type,
            $note,
            $id
        ]);

        header(
            'Location: relationships.php?asset_id=' .
            rawurlencode($source)
        );

        exit;

    }

    $stmt = $pdo->query("
        SELECT
            asset_id,
            title

        FROM ipmdb_assets

        ORDER BY asset_id DESC
    ");

    $assets = $stmt->fetchAll();

}
catch (Throwable $e) {

    $error = ipmdb_public_error($e, 'The relationship could not be updated.');

}

?>
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<title>
Relationship Editor · IPMdb
</title>

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<link
    rel="stylesheet"
    href="/ipmdb/assets/css/admin.css"
>

</head>

<body>

<div class="page">

<h1>

Relationship Editor

</h1>

<?php if ($error !== ''): ?>

<div class="error">

<?= e($error) ?>

</div>

<?php endif; ?>

<?php if ($relationship): ?>

<form
    method="post"
    action="relationship_edit.php"
>

<?= ipmdb_csrf_field() ?>

<input
    type="hidden"
    name="id"
    value="<?= e((string)$id) ?>"
>

<label>

Source Asset

</label>

<select name="source_asset_id">

<?php foreach ($assets as $asset): ?>

<option
value="<?= e((string)$asset['asset_id']) ?>"
<?= $relationship['source_asset_id']===$asset['asset_id']
? 'selected'
: '' ?>
>

<?= e(
    ($asset['title'] ?: $asset['asset_id'])
) ?>

</option>

<?php endforeach; ?>

</select>

<label>

Target Asset

</label>

<select name="target_asset_id">

<?php foreach ($assets as $asset): ?>

<option
value="<?= e((string)$asset['asset_id']) ?>"
<?= $relationship['target_asset_id']===$asset['asset_id']
? 'selected'
: '' ?>
>

<?= e(
    ($asset['title'] ?: $asset['asset_id'])
) ?>

</option>

<?php endforeach; ?>

</select><label>

Relationship Type

</label>

<select name="relationship_type">

<?php foreach (ipmdb_relationship_types() as $key => $type): ?>

<option
value="<?= e($key) ?>"
<?= $relationship['relationship_type']===$key
? 'selected'
: '' ?>
>

<?= e($type['label']) ?>

</option>

<?php endforeach; ?>

</select>

<label>

Note

</label>

<textarea
name="note"
rows="8"
><?= e((string)($relationship['note'] ?? '')) ?></textarea>

<div
style="
display:flex;
gap:12px;
margin-top:24px;
flex-wrap:wrap;
"
>

<button
type="submit"
>

Save Relationship

</button>

<button
type="submit"
name="delete"
value="1"
onclick="return confirm('Delete this relationship?');"
style="
background:#b91c1c;
color:#fff;
"
>

Delete Relationship

</button>

<a
href="relationships.php?asset_id=<?= urlencode((string)$relationship['source_asset_id']) ?>"
style="
padding:12px 18px;
text-decoration:none;
"
>

Cancel

</a>

</div>

</form>

<?php endif; ?>

</div></body>

</html>
