<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_merge.php
|--------------------------------------------------------------------------
| IPMdb Relationship Merge Utility
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';

$config = ipmdb_config();

$error = '';
$notice = '';

$assets = [];

try {

    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );

    if($_SERVER['REQUEST_METHOD']==='POST'){
        ipmdb_require_csrf();

        $master=
            trim((string)($_POST['master_asset_id'] ?? ''));

        $duplicate=
            trim((string)($_POST['duplicate_asset_id'] ?? ''));

        $archive=
            isset($_POST['archive']);

        if($master==='' || $duplicate===''){
            throw new RuntimeException(
                'Select both assets.'
            );
        }

        if($master===$duplicate){
            throw new RuntimeException(
                'Assets must be different.'
            );
        }

        $pdo->beginTransaction();

        $stmt=$pdo->prepare("
            UPDATE ipmdb_relationships
            SET source_asset_id=?
            WHERE source_asset_id=?
        ");

        $stmt->execute([
            $master,
            $duplicate
        ]);

        $stmt=$pdo->prepare("
            UPDATE ipmdb_relationships
            SET target_asset_id=?
            WHERE target_asset_id=?
        ");

        $stmt->execute([
            $master,
            $duplicate
        ]);

        $stmt=$pdo->prepare("
            DELETE r1
            FROM ipmdb_relationships r1
            INNER JOIN ipmdb_relationships r2
            ON
            r1.id>r2.id
            AND
            r1.source_asset_id=r2.source_asset_id
            AND
            r1.target_asset_id=r2.target_asset_id
            AND
            r1.relationship_type=r2.relationship_type
        ");

        $stmt->execute();        if($archive){

            $stmt=$pdo->prepare("
                UPDATE ipmdb_assets
                SET
                    status='Archived'
                WHERE asset_id=?
            ");

            $stmt->execute([$duplicate]);

        }else{

            $stmt=$pdo->prepare("
                DELETE
                FROM ipmdb_assets
                WHERE asset_id=?
            ");

            $stmt->execute([$duplicate]);

        }

        $pdo->commit();

        $notice='Assets merged successfully.';

    }

    $stmt=$pdo->query("
        SELECT
            asset_id,
            title
        FROM ipmdb_assets
        ORDER BY asset_id DESC
    ");

    $assets=$stmt->fetchAll();

}catch(Throwable $e){

    if(isset($pdo) && $pdo->inTransaction()){
        $pdo->rollBack();
    }

    $error=ipmdb_public_error($e, 'The assets could not be merged.');

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

Relationship Merge

</title>

<style>

body{
margin:40px;
background:#07111f;
color:#fff;
font:16px Arial;
}

.card{
max-width:760px;
margin:auto;
padding:24px;
background:#132235;
border-radius:16px;
}

label{
display:block;
margin-top:18px;
}

select{
width:100%;
padding:12px;
margin-top:8px;
}

button{
margin-top:24px;
padding:12px 18px;
font-weight:bold;
}

.ok{
color:#86efac;
}

.err{
color:#fca5a5;
}

</style>

</head>

<body>

<div class="card">

<h1>

Merge Assets

</h1>

<?php if($error): ?>

<p class="err">

<?=htmlspecialchars($error)?>

</p>

<?php endif; ?>

<?php if($notice): ?>

<p class="ok">

<?=htmlspecialchars($notice)?>

</p>

<?php endif; ?>

<form method="post">
<?= ipmdb_csrf_field() ?>
<label>

Master Asset

</label>

<select name="master_asset_id">

<?php foreach($assets as $asset): ?>

<option value="<?=htmlspecialchars($asset['asset_id'])?>">

<?=htmlspecialchars(
($asset['title'] ?: $asset['asset_id'])
)?>

</option>

<?php endforeach; ?>

</select>

<label>

Duplicate Asset

</label>

<select name="duplicate_asset_id">

<?php foreach($assets as $asset): ?>

<option value="<?=htmlspecialchars($asset['asset_id'])?>">

<?=htmlspecialchars(
($asset['title'] ?: $asset['asset_id'])
)?>

</option>

<?php endforeach; ?>

</select>

<p style="margin-top:20px">

<label>

<input
type="checkbox"
name="archive"
checked
>

Archive duplicate instead of deleting it.

</label>

</p>

<button type="submit">

Merge Assets

</button>

</form>

</div>

</body>

</html>
