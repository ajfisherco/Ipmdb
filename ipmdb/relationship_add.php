<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_add.php
|--------------------------------------------------------------------------
| IPMdb Relationship Creator
| Ideas 2 Assets
|--------------------------------------------------------------------------
*/

function ipmdb_config(): array
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

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$relationshipTypes = [
    'relates_to' => 'Relates To',
    'depends_on' => 'Depends On',
    'part_of' => 'Part Of',
    'blocks' => 'Blocks',
    'implements' => 'Implements',
    'enhances' => 'Enhances',
    'documents' => 'Documents',
    'supersedes' => 'Supersedes',
];

$config = ipmdb_config();

$error = '';
$assetId = trim((string) ($_GET['asset_id'] ?? $_POST['asset_id'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

$currentAsset = null;
$results = [];
$pdo = null;

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

    if ($assetId !== '') {
        $stmt = $pdo->prepare("
            SELECT asset_id, title, status, version, created_at
            FROM ipmdb_assets
            WHERE asset_id = ?
            LIMIT 1
        ");
        $stmt->execute([$assetId]);
        $currentAsset = $stmt->fetch();

        if (!$currentAsset) {
            $error = 'That starting asset could not be found. Choose an asset below.';
            $assetId = '';
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        ipmdb_require_csrf();
        if (!$currentAsset || $assetId === '') {
            throw new RuntimeException('Choose a starting asset first.');
        }

        $relatedAssetId = trim((string) ($_POST['related_asset_id'] ?? ''));
        $relationshipType = trim((string) ($_POST['relationship_type'] ?? 'relates_to'));
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($relatedAssetId === '') {
            throw new RuntimeException('Choose a related asset.');
        }

        if ($relatedAssetId === $assetId) {
            throw new RuntimeException('An asset cannot be related to itself.');
        }

        if (!array_key_exists($relationshipType, $relationshipTypes)) {
            throw new RuntimeException('Choose a valid relationship type.');
        }

        $check = $pdo->prepare("
            SELECT 1
            FROM ipmdb_assets
            WHERE asset_id = ?
            LIMIT 1
        ");
        $check->execute([$relatedAssetId]);

        if (!$check->fetchColumn()) {
            throw new RuntimeException('The related asset could not be found.');
        }

        $duplicate = $pdo->prepare("
            SELECT 1
            FROM ipmdb_relationships
            WHERE source_asset_id = ?
              AND target_asset_id = ?
              AND relationship_type = ?
            LIMIT 1
        ");
        $duplicate->execute([
            $assetId,
            $relatedAssetId,
            $relationshipType,
        ]);

        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('This relationship already exists.');
        }

        $insert = $pdo->prepare("
            INSERT INTO ipmdb_relationships
                (source_asset_id, target_asset_id, relationship_type, note, created_at)
            VALUES
                (?, ?, ?, ?, NOW())
        ");

        $insert->execute([
            $assetId,
            $relatedAssetId,
            $relationshipType,
            $note,
        ]);

        header('Location: /ipmdb/relationships.php?asset_id=' . rawurlencode($assetId));
        exit;
    }

    if ($assetId === '') {
        if ($search === '') {
            $stmt = $pdo->query("
                SELECT asset_id, title, status, version, created_at
                FROM ipmdb_assets
                ORDER BY created_at DESC
                LIMIT 25
            ");
            $results = $stmt->fetchAll();
        } else {
            $like = '%' . $search . '%';

            $stmt = $pdo->prepare("
                SELECT asset_id, title, status, version, created_at
                FROM ipmdb_assets
                WHERE asset_id LIKE ?
                   OR title LIKE ?
                ORDER BY created_at DESC
                LIMIT 25
            ");
            $stmt->execute([$like, $like]);
            $results = $stmt->fetchAll();
        }
    } elseif ($search !== '') {
        $like = '%' . $search . '%';

        $stmt = $pdo->prepare("
            SELECT asset_id, title, status, version, created_at
            FROM ipmdb_assets
            WHERE asset_id <> ?
              AND (
                asset_id LIKE ?
                OR title LIKE ?
              )
            ORDER BY created_at DESC
            LIMIT 25
        ");

        $stmt->execute([
            $assetId,
            $like,
            $like,
        ]);

        $results = $stmt->fetchAll();
    }
} catch (Throwable $ex) {
    $error = ipmdb_public_error($ex, 'The relationship could not be saved.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>IPMdb Relationship Map · Add Relationship</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#020617;
  --panel:#0f172a;
  --panel2:#111827;
  --text:#e5e7eb;
  --muted:#94a3b8;
  --line:#1e293b;
  --blue:#38bdf8;
  --green:#86efac;
  --red:#f87171;
}
*{box-sizing:border-box}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at top left, rgba(56,189,248,.18), transparent 36rem),
    radial-gradient(circle at bottom right, rgba(134,239,172,.12), transparent 30rem),
    linear-gradient(135deg,#020617,#0f172a 55%,#020617);
  color:var(--text);
  font-family:Arial, Helvetica, sans-serif;
}
main{
  width:min(1100px,94vw);
  margin:0 auto;
  padding:28px 0 48px;
}
.header{
  display:flex;
  justify-content:space-between;
  gap:18px;
  align-items:flex-start;
  border-bottom:1px solid var(--line);
  padding-bottom:18px;
  margin-bottom:22px;
}
h1{
  margin:0;
  font-size:clamp(28px,5vw,52px);
  letter-spacing:.04em;
}
.kicker{
  color:var(--blue);
  font-weight:800;
  letter-spacing:.16em;
  text-transform:uppercase;
}
a{color:var(--blue);text-decoration:none}
a:hover{text-decoration:underline}
.card{
  background:rgba(15,23,42,.84);
  border:1px solid var(--line);
  border-radius:22px;
  padding:20px;
  margin:18px 0;
  box-shadow:0 18px 60px rgba(0,0,0,.26);
}
.asset-title{
  font-size:28px;
  font-weight:900;
  margin:.2rem 0;
}
.meta{
  color:var(--muted);
  line-height:1.55;
}
.error{
  border-color:rgba(248,113,113,.45);
  color:#fecaca;
  background:rgba(127,29,29,.22);
}
form.search{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:14px;
}
input,select,textarea,button{
  font:inherit;
  border-radius:14px;
  border:1px solid var(--line);
  padding:13px 14px;
}
input,select,textarea{
  background:#020617;
  color:var(--text);
}
input[type="search"]{
  flex:1 1 260px;
}
textarea{
  width:100%;
  min-height:90px;
  resize:vertical;
}
button,.button{
  background:linear-gradient(135deg,#0284c7,#0ea5e9);
  color:white;
  border:0;
  font-weight:900;
  cursor:pointer;
  display:inline-block;
  padding:13px 16px;
  border-radius:14px;
}
button:hover,.button:hover{
  filter:brightness(1.1);
  text-decoration:none;
}
.button.secondary{
  background:#172033;
  border:1px solid var(--line);
}
.result{
  display:grid;
  grid-template-columns:minmax(0,1fr);
  gap:12px;
  border-top:1px solid var(--line);
  padding:18px 0;
}
.result:first-child{
  border-top:0;
}
.result-title{
  font-size:22px;
  font-weight:900;
}
.relationship-form{
  background:rgba(2,6,23,.52);
  border:1px solid var(--line);
  border-radius:18px;
  padding:16px;
}
label{
  display:block;
  color:var(--muted);
  font-weight:800;
  margin:12px 0 6px;
  letter-spacing:.04em;
  text-transform:uppercase;
  font-size:13px;
}
.nav,.actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.actions{
  margin-top:12px;
}
@media (max-width:720px){
  .header{
    flex-direction:column;
  }
  button,.button{
    min-height:48px;
  }
}
</style>
</head>
<body>
<main>

  <section class="header">
    <div>
      <div class="kicker">IPMdb</div>
      <h1>Relationship Map</h1>
      <div class="meta">Add Relationship · IDEAS 2 ASSETS</div>
    </div>
    <div class="nav">
      <?php if ($assetId !== ''): ?>
        <a class="button secondary" href="/ipmdb/relationships.php?asset_id=<?= e($assetId) ?>">Back</a>
      <?php else: ?>
        <a class="button secondary" href="/ipmdb/relationship_explorer.php">Back</a>
      <?php endif; ?>
      <a class="button" href="/ipmdb/ledger.php">Ledger</a>
    </div>
  </section>

  <?php if ($error !== ''): ?>
    <section class="card error">
      <?= e($error) ?>
    </section>
  <?php endif; ?>

  <?php if (!$currentAsset): ?>
    <section class="card">
      <div class="kicker">Step 1</div>
      <div class="asset-title">Choose the starting asset</div>
      <div class="meta">Search by title or asset ID, then tap Start Here.</div>

      <form class="search" method="get" action="/ipmdb/relationship_add.php">
        <input type="search" name="search" value="<?= e($search) ?>" placeholder="Title or asset ID" aria-label="Search assets">
        <button type="submit">Search</button>
      </form>
    </section>

    <section class="card">
      <?php if (!$results): ?>
        <div class="meta">No matching assets found.</div>
      <?php else: ?>
        <?php foreach ($results as $row): ?>
          <?php $rowAssetId = (string) ($row['asset_id'] ?? ''); ?>

          <div class="result">
            <div>
              <div class="result-title"><?= e($row['title'] ?? '') ?></div>
              <div class="meta">
                <?= e($rowAssetId) ?><br>
                Status: <?= e($row['status'] ?? '') ?> · Version: <?= e((string) ($row['version'] ?? '')) ?>
              </div>
              <div class="actions">
                <a class="button" href="/ipmdb/relationship_add.php?asset_id=<?= rawurlencode($rowAssetId) ?>">Start Here</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="card">
      <div class="kicker">Starting Asset</div>
      <div class="asset-title"><?= e($currentAsset['title'] ?? '') ?></div>
      <div class="meta">
        <?= e($currentAsset['asset_id'] ?? '') ?><br>
        Status: <?= e($currentAsset['status'] ?? '') ?> · Version: <?= e((string) ($currentAsset['version'] ?? '')) ?>
      </div>
      <div class="actions">
        <a class="button secondary" href="/ipmdb/relationship_add.php">Change Starting Asset</a>
      </div>
    </section>

    <section class="card">
      <div class="kicker">Step 2</div>
      <div class="asset-title">Choose the related asset</div>
      <div class="meta">Search by title or asset ID.</div>

      <form class="search" method="get" action="/ipmdb/relationship_add.php">
        <input type="hidden" name="asset_id" value="<?= e($assetId) ?>">
        <input type="search" name="search" value="<?= e($search) ?>" placeholder="Title or asset ID" aria-label="Search assets">
        <button type="submit">Search</button>
      </form>
    </section>

    <section class="card">
      <?php if ($search === ''): ?>
        <div class="meta">Search for the asset you want to connect.</div>
      <?php elseif (!$results): ?>
        <div class="meta">No matching assets found.</div>
      <?php else: ?>
        <?php foreach ($results as $row): ?>
          <?php $rowAssetId = (string) ($row['asset_id'] ?? ''); ?>

          <div class="result">
            <div>
              <div class="result-title"><?= e($row['title'] ?? '') ?></div>
              <div class="meta">
                <?= e($rowAssetId) ?><br>
                Status: <?= e($row['status'] ?? '') ?> · Version: <?= e((string) ($row['version'] ?? '')) ?>
              </div>
            </div>

            <form class="relationship-form" method="post" action="/ipmdb/relationship_add.php">
              <?= ipmdb_csrf_field() ?>
              <input type="hidden" name="asset_id" value="<?= e($assetId) ?>">
              <input type="hidden" name="related_asset_id" value="<?= e($rowAssetId) ?>">

              <label for="relationship_type_<?= e($rowAssetId) ?>">Relationship Type</label>
              <select id="relationship_type_<?= e($rowAssetId) ?>" name="relationship_type">
                <?php foreach ($relationshipTypes as $value => $label): ?>
                  <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>

              <label for="note_<?= e($rowAssetId) ?>">Note</label>
              <textarea id="note_<?= e($rowAssetId) ?>" name="note" placeholder="Optional relationship note"></textarea>

              <button type="submit">Create Relationship</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

</main>
</body>
</html>
