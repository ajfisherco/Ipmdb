<?php
declare(strict_types=1);

function ipmdb_config(): array {
  $local = __DIR__ . '/config.local.php';
  $main  = __DIR__ . '/config.php';

  if (is_file($local)) return require $local;
  if (is_file($main)) return require $main;

  http_response_code(500);
  exit('IPMdb config file missing.');
}

function e(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function short_text(?string $value, int $limit = 260): string {
  $value = trim((string)$value);
  if (strlen($value) <= $limit) return $value;
  return substr($value, 0, $limit) . '…';
}

$query = trim((string)($_GET['q'] ?? ''));
$assets = [];
$suggestions = [];
$error = '';

try {
  $config = ipmdb_config();

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

  $suggestStmt = $pdo->query("
    SELECT asset_id, title, category, status
    FROM ipmdb_assets
    ORDER BY created_at DESC
    LIMIT 250
  ");

  foreach ($suggestStmt->fetchAll() as $row) {
    foreach (['asset_id', 'title', 'category', 'status'] as $field) {
      $value = trim((string)($row[$field] ?? ''));
      if ($value !== '') {
        $suggestions[$value] = true;
      }
    }
  }

  $suggestions = array_keys($suggestions);
  sort($suggestions, SORT_NATURAL | SORT_FLAG_CASE);

  if ($query !== '') {
    $like = '%' . $query . '%';

    $stmt = $pdo->prepare("
      SELECT asset_id, title, category, idea, status, version, created_at
      FROM ipmdb_assets
      WHERE asset_id LIKE ?
         OR title LIKE ?
         OR category LIKE ?
         OR idea LIKE ?
         OR status LIKE ?
      ORDER BY created_at DESC
      LIMIT 75
    ");

    $stmt->execute([$like, $like, $like, $like, $like]);
    $assets = $stmt->fetchAll();
  }
} catch (Throwable $ex) {
  http_response_code(500);
  error_log('IPMdb search failed: ' . $ex->getMessage());
  $error = 'IPMdb search is temporarily unavailable.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>IPMdb Search</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box}

:root{
  --ink:#eaf2ff;
  --soft:#9fb3c8;
  --blue:#8fc7ff;
  --green:#9ff0c3;
  --deep:#020617;
  --glass:rgba(8,18,34,.68);
  --line:rgba(191,219,254,.22);
}

body{
  margin:0;
  min-height:100svh;
  color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
  background:
    radial-gradient(circle at 16% 10%,rgba(96,165,250,.34),transparent 28rem),
    radial-gradient(circle at 80% 18%,rgba(134,239,172,.22),transparent 24rem),
    radial-gradient(circle at 50% 110%,rgba(14,165,233,.18),transparent 34rem),
    linear-gradient(145deg,#020617,#061526 48%,#03140f);
}

body:before{
  content:"";
  position:fixed;
  inset:0;
  pointer-events:none;
  background:
    linear-gradient(120deg,transparent 0 38%,rgba(255,255,255,.035) 39%,transparent 41%),
    radial-gradient(circle at 50% 50%,transparent,rgba(0,0,0,.36));
}

a{
  color:inherit;
  text-decoration:none;
}

.wrap{
  width:min(1180px,94vw);
  margin:0 auto;
  padding:28px 0 54px;
  position:relative;
}

.top{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:18px;
  margin-bottom:24px;
}

.brand strong{
  display:block;
  color:var(--blue);
  font-size:clamp(40px,7vw,78px);
  line-height:.86;
  letter-spacing:-.075em;
  text-shadow:0 0 32px rgba(96,165,250,.28);
}

.brand span{
  display:block;
  margin-top:8px;
  color:var(--green);
  font-size:13px;
  letter-spacing:.26em;
  text-transform:uppercase;
}

.nav{
  display:flex;
  flex-wrap:wrap;
  justify-content:flex-end;
  gap:10px;
}

.btn,button{
  border:1px solid var(--line);
  background:rgba(15,23,42,.58);
  color:var(--ink);
  border-radius:999px;
  padding:12px 16px;
  font-weight:900;
  font-size:14px;
  cursor:pointer;
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.08),
    0 12px 32px rgba(0,0,0,.18);
}

.btn:hover,button:hover{
  background:rgba(37,99,235,.3);
  border-color:rgba(147,197,253,.52);
}

.panel{
  position:relative;
  overflow:hidden;
  border:1px solid var(--line);
  background:
    linear-gradient(135deg,rgba(15,23,42,.88),rgba(5,35,28,.72)),
    radial-gradient(circle at top right,rgba(96,165,250,.22),transparent 21rem);
  border-radius:34px;
  padding:clamp(22px,4.4vw,42px);
  box-shadow:0 28px 100px rgba(0,0,0,.38);
}

.panel:before{
  content:"";
  position:absolute;
  inset:-40%;
  background:
    radial-gradient(circle,rgba(147,197,253,.08),transparent 34%),
    conic-gradient(from 180deg,transparent,rgba(134,239,172,.08),transparent,rgba(96,165,250,.08),transparent);
  opacity:.72;
  pointer-events:none;
}

.panel > *{
  position:relative;
}

h1{
  margin:0 0 22px;
  font-size:clamp(42px,8vw,88px);
  line-height:.86;
  letter-spacing:-.08em;
}

form{
  display:flex;
  gap:10px;
  margin-bottom:18px;
}

input[type="search"]{
  width:100%;
  min-height:60px;
  border:1px solid rgba(191,219,254,.28);
  border-radius:999px;
  background:rgba(2,6,23,.66);
  color:var(--ink);
  padding:0 20px;
  font-size:19px;
  outline:none;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.06);
}

input[type="search"]:focus{
  border-color:rgba(147,197,253,.95);
  box-shadow:
    0 0 0 4px rgba(96,165,250,.16),
    0 0 42px rgba(96,165,250,.16);
}

.msg{
  border:1px solid rgba(148,163,184,.24);
  background:rgba(2,6,23,.42);
  border-radius:22px;
  padding:16px 18px;
  color:var(--soft);
  line-height:1.55;
}

.error{
  border-color:rgba(251,113,133,.45);
  color:#fecdd3;
}

.results{
  display:grid;
  gap:14px;
  margin-top:18px;
}

.asset{
  border:1px solid rgba(191,219,254,.2);
  background:
    linear-gradient(135deg,rgba(30,41,59,.72),rgba(15,23,42,.5)),
    radial-gradient(circle at top left,rgba(96,165,250,.13),transparent 20rem);
  border-radius:26px;
  padding:18px;
  box-shadow:0 16px 48px rgba(0,0,0,.22);
}

.asset-head{
  display:flex;
  justify-content:space-between;
  gap:14px;
  align-items:flex-start;
}

.asset-title{
  font-size:clamp(25px,3.4vw,40px);
  font-weight:950;
  letter-spacing:-.055em;
  line-height:.94;
  color:#dbeafe;
}

.meta,.idea{
  color:var(--soft);
  font-size:14px;
  line-height:1.55;
  margin-top:10px;
}

.idea{
  color:#d7e4f2;
  padding-top:8px;
}

.badge{
  color:#bbf7d0;
  border:1px solid rgba(134,239,172,.34);
  background:rgba(22,101,52,.2);
  border-radius:999px;
  padding:8px 11px;
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:.13em;
  white-space:nowrap;
}

.actions{
  display:flex;
  flex-wrap:wrap;
  gap:9px;
  margin-top:15px;
}

.actions .btn{
  padding:10px 14px;
  font-size:13px;
}

@media(max-width:720px){
  .top,form,.asset-head{
    flex-direction:column;
    align-items:stretch;
  }

  .nav{
    justify-content:flex-start;
  }

  button{
    width:100%;
  }
}
</style>
</head>

<body>
<main class="wrap">
  <header class="top">
    <a class="brand" href="/ipmdb/">
      <strong>IPMdb</strong>
      <span>Ideas 2 Assets</span>
    </a>

    <nav class="nav">
      <a class="btn" href="/ipmdb/">Lock Idea</a>
      <a class="btn" href="/ipmdb/ledger.php">Ledger</a>
      <a class="btn" href="/ipmdb/viewer.php">Viewer</a>
      <a class="btn" href="/ipmdb/admin.php">Admin</a>
    </nav>
  </header>

  <section class="panel">
    <h1>Search IPMdb</h1>

    <form id="ipmdb-search-form" method="get" action="/ipmdb/search.php">
      <input
        id="ipmdb-search-input"
        type="search"
        name="q"
        value="<?= e($query) ?>"
        placeholder="Search asset ID, title, idea, category, or status"
        list="ipmdb-suggestions"
        autocomplete="on"
        autofocus
      >

      <datalist id="ipmdb-suggestions">
        <?php foreach ($suggestions as $suggestion): ?>
          <option value="<?= e($suggestion) ?>"></option>
        <?php endforeach; ?>
      </datalist>

      <button type="submit">SEARCH</button>
    </form>

    <?php if ($error): ?>
      <div class="msg error"><?= e($error) ?></div>

    <?php elseif ($query === ''): ?>
      <div class="msg">
        Start typing to search the asset field, title, idea text, category, or status.
      </div>

    <?php elseif (!$assets): ?>
      <div class="msg">
        No matching assets found for “<?= e($query) ?>”.
      </div>

    <?php else: ?>
      <div class="msg">
        <?= count($assets) ?> result<?= count($assets) === 1 ? '' : 's' ?> found for “<?= e($query) ?>”.
      </div>

      <section class="results">
        <?php foreach ($assets as $row): ?>
          <article class="asset">
            <div class="asset-head">
              <div>
                <a class="asset-title" href="/ipmdb/viewer.php?asset_id=<?= urlencode((string)$row['asset_id']) ?>">
                  <?= e($row['title'] ?: $row['asset_id']) ?>
                </a>

                <div class="meta">
                  <?= e($row['asset_id']) ?>
                  · <?= e($row['category'] ?: 'Uncategorized') ?>
                  · Version <?= e($row['version'] ?: '1.0') ?>
                  · <?= e($row['created_at']) ?>
                </div>
              </div>

              <div class="badge"><?= e($row['status'] ?: 'Draft') ?></div>
            </div>

            <?php if (!empty($row['idea'])): ?>
              <div class="idea">
                <?= e(short_text((string)$row['idea'], 260)) ?>
              </div>
            <?php endif; ?>

            <div class="actions">
              <a class="btn" href="/ipmdb/viewer.php?asset_id=<?= urlencode((string)$row['asset_id']) ?>">View</a>
              <a class="btn" href="/ipmdb/edit.php?asset_id=<?= urlencode((string)$row['asset_id']) ?>">Edit</a>
              <a class="btn" href="/ipmdb/history.php?asset_id=<?= urlencode((string)$row['asset_id']) ?>">History</a>
              <a class="btn" href="/ipmdb/relationships.php?asset_id=<?= urlencode((string)$row['asset_id']) ?>">Relationships</a>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('ipmdb-search-form');
  const input = document.getElementById('ipmdb-search-input');

  if (!form || !input) {
    return;
  }

  input.focus();

  input.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === 'Return') {
      event.preventDefault();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      input.value = '';
      input.focus();
    }
  });
});
</script>
</body>
</html>
