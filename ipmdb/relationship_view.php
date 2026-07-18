<?php
declare(strict_types=1);

function h(?string $v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ipmdb_config(): array {
  $local = __DIR__ . '/config.local.php';
  if (is_file($local)) return require $local;
  return require __DIR__ . '/config.php';
}

function ipmdb_pdo(): PDO {
  $c = ipmdb_config();
  return new PDO($c['db']['dsn'], $c['db']['user'], $c['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
  ]);
}

$relationshipId = trim((string)($_GET['id'] ?? $_GET['relationship_id'] ?? ''));
$assetId = trim((string)($_GET['asset_id'] ?? ''));

$error = '';
$relationship = null;
$assets = [];

try {
  $pdo = ipmdb_pdo();

  if ($relationshipId !== '') {
    $stmt = $pdo->prepare("
      SELECT *
      FROM ipmdb_relationships
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([':id' => $relationshipId]);
    $relationship = $stmt->fetch();
  }

  if (!$relationship && $assetId !== '') {
    $stmt = $pdo->prepare("
      SELECT *
      FROM ipmdb_relationships
      WHERE source_asset_id = :asset_id
         OR target_asset_id = :asset_id
      ORDER BY id DESC
      LIMIT 1
    ");
    $stmt->execute([':asset_id' => $assetId]);
    $relationship = $stmt->fetch();
  }

  if ($relationship) {
    $ids = [];

    foreach (['source_asset_id', 'target_asset_id'] as $key) {
      if (!empty($relationship[$key])) {
        $ids[] = (string)$relationship[$key];
      }
    }

    $ids = array_values(array_unique($ids));

    if ($ids) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));

      $stmt = $pdo->prepare("
        SELECT asset_id, title, category, idea, created_at
        FROM ipmdb_assets
        WHERE asset_id IN ($placeholders)
        ORDER BY created_at DESC
      ");

      $stmt->execute($ids);
      $assets = $stmt->fetchAll();
    }
  }

} catch (Throwable $e) {
  error_log('IPMdb relationship view failed: ' . $e->getMessage());
  $error = 'The relationship could not be loaded.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relationship View · IPMdb.ai</title>
<style>
:root{
  --bg:#061225;
  --card:#0d1d35;
  --line:#24405f;
  --text:#eaf4ff;
  --muted:#9db4cc;
  --blue:#5bbcff;
  --green:#28e08f;
}
*{box-sizing:border-box}
body{
  margin:0;
  font-family:system-ui,-apple-system,Segoe UI,sans-serif;
  background:radial-gradient(circle at top,#12365f,var(--bg));
  color:var(--text);
}
main{
  width:min(1000px,94vw);
  margin:0 auto;
  padding:28px 0 70px;
}
.card{
  background:rgba(13,29,53,.94);
  border:1px solid var(--line);
  border-radius:24px;
  padding:22px;
  box-shadow:0 24px 80px rgba(0,0,0,.35);
}
.brand{
  color:var(--blue);
  font-weight:1000;
  letter-spacing:.08em;
  text-transform:uppercase;
}
h1{
  margin:8px 0 10px;
  font-size:clamp(32px,7vw,68px);
  line-height:.95;
}
.meta{
  color:var(--muted);
  margin-bottom:18px;
}
.grid{
  display:grid;
  gap:12px;
}
.box{
  border:1px solid var(--line);
  border-radius:18px;
  padding:15px;
  background:rgba(255,255,255,.04);
}
.label{
  color:var(--muted);
  font-size:12px;
  font-weight:900;
  letter-spacing:.12em;
  text-transform:uppercase;
}
.value{
  margin-top:6px;
  font-size:18px;
  font-weight:800;
  word-break:break-word;
}
.actions{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:10px;
  margin-top:18px;
}
.btn{
  display:block;
  text-align:center;
  text-decoration:none;
  color:var(--text);
  border:1px solid var(--line);
  border-radius:999px;
  padding:13px 14px;
  font-weight:900;
  text-transform:uppercase;
  font-size:12px;
  letter-spacing:.08em;
}
.green{
  background:linear-gradient(135deg,var(--green),#17875b);
  color:#02120b;
  border:0;
}
.asset a{
  color:var(--blue);
  font-weight:900;
  text-decoration:none;
}
.err{
  border-color:#ff6b6b;
  color:#ffd9d9;
}
footer{
  margin-top:18px;
  color:var(--muted);
  display:flex;
  justify-content:space-between;
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:.12em;
}
@media(max-width:760px){
  .actions{grid-template-columns:1fr}
}
</style>
</head>
<body>
<main>
<section class="card">
  <div class="brand">IPMdb.ai</div>
  <h1>Relationship View</h1>
  <div class="meta">Ideas 2 Assets</div>

  <?php if ($error): ?>
    <div class="box err">
      <div class="label">Error</div>
      <div class="value"><?= h($error) ?></div>
    </div>
  <?php elseif (!$relationship): ?>
    <div class="box">
      <div class="label">No Relationship Found</div>
      <div class="value">No matching relationship was found for this request.</div>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($relationship as $key => $value): ?>
        <?php if ($value !== null && $value !== ''): ?>
          <div class="box">
            <div class="label"><?= h((string)$key) ?></div>
            <div class="value"><?= h((string)$value) ?></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($assets): ?>
      <h2>Related Assets</h2>
      <div class="grid">
        <?php foreach ($assets as $a): ?>
          <div class="box asset">
            <div class="label"><?= h($a['asset_id'] ?? '') ?></div>
            <div class="value">
              <a href="/ipmdb/asset.php?asset_id=<?= urlencode((string)$a['asset_id']) ?>">
                <?= h($a['title'] ?: 'Untitled Asset') ?>
              </a>
            </div>
            <p><?= h(mb_strimwidth((string)($a['idea'] ?? ''), 0, 260, '…')) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="actions">
    <a class="btn green" href="/ipmdb/">Lock Idea</a>
    <a class="btn" href="/ipmdb/assets.php">Ledger</a>
    <a class="btn" href="/ipmdb/relationships.php">Relationships</a>
    <a class="btn" href="/ipmdb/search.php">Search</a>
  </div>
</section>

<footer>
  <span>IPMdb.ai</span>
  <span>Ideas 2 Assets</span>
</footer>
</main>
</body>
</html>
