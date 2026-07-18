<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_import.php
|--------------------------------------------------------------------------
| IPMdb Relationship Import (CSV)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/relationship_types.php';

$config = ipmdb_config();

$error = '';
$notice = '';
$results = [
    'imported' => 0,
    'skipped'  => 0,
    'failed'   => 0,
];

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        ipmdb_require_csrf();

        if (
            !isset($_FILES['csv']) ||
            $_FILES['csv']['error'] !== UPLOAD_ERR_OK
        ) {
            throw new RuntimeException('CSV upload failed.');
        }

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');

        if (!$fh) {
            throw new RuntimeException('Unable to read CSV.');
        }

        $pdo->beginTransaction();

        fgetcsv($fh); // header

        $assetExists = $pdo->prepare("
            SELECT 1
            FROM ipmdb_assets
            WHERE asset_id = ?
            LIMIT 1
        ");

        $duplicate = $pdo->prepare("
            SELECT id
            FROM ipmdb_relationships
            WHERE source_asset_id = ?
              AND target_asset_id = ?
              AND relationship_type = ?
            LIMIT 1
        ");

        $insert = $pdo->prepare("
            INSERT INTO ipmdb_relationships
            (
                source_asset_id,
                target_asset_id,
                relationship_type,
                note,
                created_at
            )
            VALUES
            (
                ?, ?, ?, ?, NOW()
            )
        ");

        while (($row = fgetcsv($fh)) !== false) {

            $source = trim((string)($row[0] ?? ''));
            $target = trim((string)($row[1] ?? ''));
            $type   = trim((string)($row[2] ?? 'relates_to'));
            $note   = trim((string)($row[3] ?? ''));

            if ($source === '' || $target === '') {
                $results['failed']++;
                continue;
            }

            if (!ipmdb_relationship_type_exists($type)) {
                $type = 'relates_to';
            }

            $assetExists->execute([$source]);
            if (!$assetExists->fetchColumn()) {
                $results['failed']++;
                continue;
            }

            $assetExists->execute([$target]);
            if (!$assetExists->fetchColumn()) {
                $results['failed']++;
                continue;
            }

            $duplicate->execute([
                $source,
                $target,
                $type
            ]);

            if ($duplicate->fetch()) {
                $results['skipped']++;
                continue;
            }

            $insert->execute([
                $source,
                $target,
                $type,
                $note
            ]);

            $results['imported']++;
        }

        fclose($fh);

        $pdo->commit();

        $notice =
            'Import complete. ' .
            $results['imported'] .
            ' imported, ' .
            $results['skipped'] .
            ' skipped, ' .
            $results['failed'] .
            ' failed.';
    }

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = ipmdb_public_error($e, 'The relationship CSV could not be imported.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relationship Import</title>
<style>
body{margin:40px;font:16px Arial,sans-serif;background:#0f172a;color:#e5e7eb}
.card{max-width:760px;margin:auto;padding:24px;background:#111827;border:1px solid #334155;border-radius:16px}
input,button{margin-top:16px}
button{padding:12px 18px;font-weight:bold}
.ok{color:#86efac}
.err{color:#fca5a5}
pre{background:#020617;padding:12px;border-radius:10px}
</style>
</head>
<body>

<div class="card">

<h1>Relationship Import</h1>

<p>CSV format:</p>

<pre>source_asset_id,target_asset_id,relationship_type,note</pre>

<?php if ($error): ?>
<p class="err"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($notice): ?>
<p class="ok"><?= e($notice) ?></p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

<?= ipmdb_csrf_field() ?>

<input
type="file"
name="csv"
accept=".csv"
required>

<br>

<button type="submit">
Import Relationships
</button>

</form>

</div>

</body>
</html>
