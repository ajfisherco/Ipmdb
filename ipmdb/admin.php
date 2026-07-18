<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

function h(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ipmdb_config(): array {
  $local = __DIR__ . '/config.local.php';
  if (is_file($local)) return require $local;
  return require __DIR__ . '/config.php';
}

$assets = [];
$totalAssets = 0;
$totalVersions = 0;
$newestAsset = null;
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

  $countStmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM ipmdb_assets
  ");

  $totalAssets = (int)($countStmt->fetch()['total'] ?? 0);

  try {
    $versionCountStmt = $pdo->query("
      SELECT COUNT(*) AS total
      FROM ipmdb_asset_versions
    ");

    $totalVersions = (int)($versionCountStmt->fetch()['total'] ?? 0);
  } catch (Throwable $ignored) {
    $totalVersions = 0;
  }

  $stmt = $pdo->query("
    SELECT asset_id, email, title, category, idea, version
    FROM ipmdb_assets
    ORDER BY asset_id DESC
  ");

  $assets = $stmt->fetchAll();
  $newestAsset = $assets[0] ?? null;

} catch (Throwable $e) {
  http_response_code(500);
  $error = ipmdb_public_error($e, 'Admin dashboard unavailable.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin | IPMdb</title>
<style>
body{margin:0;min-height:100svh;background:#020617;color:#e5f2ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
main{width:min(1320px,94vw);min-height:100svh;margin:0 auto;padding:28px 0 96px}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:24px}
.brand{font-size:clamp(42px,8vw,92px);font-weight:1000;letter-spacing:-.06em;line-height:.9}
.brand span{color:#86efac}
.sub{margin-top:10px;color:#9fb4ca;font-size:clamp(18px,3vw,28px);font-weight:800}
a{color:#60a5fa;text-decoration:none;font-weight:900}
.links{display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end;padding-top:12px}
.pill{border:1px solid rgba(148,163,184,.25);border-radius:999px;padding:10px 14px;background:rgba(15,23,42,.72);white-space:nowrap}
.logout-form{display:inline;margin:0}
.logout-form button{color:#60a5fa;font:inherit;font-weight:900;cursor:pointer}
.stats{display:grid;grid-template-columns:1fr 1fr 2fr;gap:14px;margin-bottom:18px}
.stat{border:1px solid rgba(148,163,184,.25);border-radius:24px;background:rgba(15,23,42,.78);padding:22px;box-shadow:0 18px 70px rgba(0,0,0,.28)}
.label{color:#9fb4ca;font-size:13px;font-weight:1000;letter-spacing:.13em;text-transform:uppercase;margin-bottom:8px}
.big{font-size:clamp(38px,7vw,76px);font-weight:1000;letter-spacing:-.05em;line-height:.95;word-break:break-word}
.green{color:#86efac}
input{width:100%;box-sizing:border-box;border:1px solid rgba(148,163,184,.25);border-radius:18px;background:rgba(15,23,42,.72);color:#e5f2ff;padding:18px 20px;font-size:20px;font-weight:800;margin-bottom:18px;outline:none}
.card{border:1px solid rgba(148,163,184,.25);border-radius:28px;background:rgba(15,23,42,.78);overflow:hidden;box-shadow:0 24px 90px rgba(0,0,0,.42)}
.head,.row{display:grid;grid-template-columns:1.05fr 1fr .55fr .85fr 1.2fr 1.7fr .95fr;gap:1px}
.head{background:rgba(96,165,250,.12);border-bottom:1px solid rgba(148,163,184,.25)}
.head div{color:#9fb4ca;font-size:13px;font-weight:1000;letter-spacing:.13em;text-transform:uppercase;padding:16px 18px}
.row{border-bottom:1px solid rgba(148,163,184,.22);background:rgba(2,6,23,.56)}
.row:last-child{border-bottom:0}
.cell{padding:16px 18px;font-size:17px;font-weight:750;line-height:1.35;word-break:break-word}
.asset-id{color:#86efac;font-weight:1000}
.category,.version{display:inline-block;border:1px solid rgba(134,239,172,.28);border-radius:999px;padding:7px 10px;background:rgba(21,128,61,.18);color:#bbf7d0;font-size:13px;font-weight:1000;text-transform:uppercase;letter-spacing:.04em}
.version{border-color:rgba(96,165,250,.32);background:rgba(37,99,235,.18);color:#bfdbfe}
.idea{color:#cfe7ff}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-block;text-align:center;border-radius:999px;padding:10px 14px;background:#2563eb;color:white;font-size:13px;font-weight:1000;letter-spacing:.04em;text-transform:uppercase}
.btn.edit{background:#15803d}
.btn.alt{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.20)}
.error,.empty{padding:clamp(24px,5vw,52px);font-size:clamp(24px,5vw,48px);font-weight:1000;letter-spacing:-.04em}
.error{color:#fecaca;background:rgba(127,29,29,.24)}
footer{position:fixed;left:0;right:0;bottom:0;padding:14px 22px;background:rgba(2,6,23,.88);border-top:1px solid rgba(148,163,184,.24);display:flex;justify-content:space-between;color:#9fb4ca;font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
@media(max-width:1040px){
.top{display:block}
.links{justify-content:flex-start;padding-top:18px}
.stats{grid-template-columns:1fr}
.head{display:none}
.row{display:block;padding:14px}
.cell{padding:8px 6px}
.cell::before{display:block;margin-bottom:3px;color:#9fb4ca;font-size:11px;font-weight:1000;letter-spacing:.12em;text-transform:uppercase}
.asset-cell::before{content:"Asset ID"}
.title-cell::before{content:"Title"}
.version-cell::before{content:"Version"}
.category-cell::before{content:"Category"}
.email-cell::before{content:"Email"}
.idea-cell::before{content:"Idea"}
.action-cell::before{content:"Actions"}
.actions{display:grid;grid-template-columns:1fr 1fr}
.btn{display:block}
}
</style>
</head>
<body>
<main>

<div class="top">
  <div>
    <div class="brand">Admin <span>IPMdb</span></div>
    <div class="sub">Control deck for locked ideas.</div>
  </div>

  <div class="links">
    <a class="pill" href="/ipmdb/">Lock Idea</a>
    <a class="pill" href="/ipmdb/ledger.php">Public Ledger</a>
    <form class="logout-form" method="post" action="/ipmdb/logout.php">
      <?= ipmdb_csrf_field() ?>
      <button class="pill" type="submit">Logout</button>
    </form>
  </div>
</div>

<?php if ($error !== ''): ?>

<section class="card">
  <div class="error"><?= h($error) ?></div>
</section>

<?php else: ?>

<section class="stats">
  <div class="stat">
    <div class="label">Total Assets</div>
    <div class="big green"><?= h((string)$totalAssets) ?></div>
  </div>

  <div class="stat">
    <div class="label">Saved Versions</div>
    <div class="big"><?= h((string)$totalVersions) ?></div>
  </div>

  <div class="stat">
    <div class="label">Newest Asset</div>
    <div class="big">
      <?php if ($newestAsset): ?>
        <a class="asset-id" href="/ipmdb/asset.php?id=<?= urlencode((string)$newestAsset['asset_id']) ?>">
          <?= h((string)$newestAsset['asset_id']) ?>
        </a>
      <?php else: ?>
        None yet.
      <?php endif; ?>
    </div>
  </div>
</section>

<input id="adminSearch" type="search" placeholder="Search asset ID, title, version, category, email, idea">

<section class="card">
<?php if (!$assets): ?>

  <div class="empty">No assets locked yet.</div>

<?php else: ?>

  <div class="head">
    <div>Asset ID</div>
    <div>Title</div>
    <div>Version</div>
    <div>Category</div>
    <div>Email</div>
    <div>Idea</div>
    <div>Actions</div>
  </div>

  <div id="adminRows">
  <?php foreach ($assets as $asset): ?>
    <?php
      $assetId = (string)($asset['asset_id'] ?? '');
      $title = (string)($asset['title'] ?? '');
      $version = (string)($asset['version'] ?? '1.0');
      $category = (string)($asset['category'] ?? 'Uncategorized');
      $email = (string)($asset['email'] ?? '');
      $idea = (string)($asset['idea'] ?? '');
      $search = strtolower($assetId . ' ' . $title . ' ' . $version . ' ' . $category . ' ' . $email . ' ' . $idea);
    ?>

    <article class="row" data-search="<?= h($search) ?>">
      <div class="cell asset-cell">
        <a class="asset-id" href="/ipmdb/asset.php?id=<?= urlencode($assetId) ?>">
          <?= h($assetId ?: 'Unknown') ?>
        </a>
      </div>

      <div class="cell title-cell">
        <?= h($title ?: 'Untitled Asset') ?>
      </div>

      <div class="cell version-cell">
        <span class="version"><?= h($version ?: '1.0') ?></span>
      </div>

      <div class="cell category-cell">
        <span class="category"><?= h($category ?: 'Uncategorized') ?></span>
      </div>

      <div class="cell email-cell">
        <?= h($email ?: 'No email') ?>
      </div>

      <div class="cell idea-cell idea">
        <?= h($idea ?: 'No idea entered.') ?>
      </div>

      <div class="cell action-cell">
        <div class="actions">
          <a class="btn edit" href="/ipmdb/admin_edit.php?id=<?= urlencode($assetId) ?>">
            Edit
          </a>

          <a class="btn alt" href="/ipmdb/asset.php?id=<?= urlencode($assetId) ?>">
            View
          </a>
        </div>
      </div>
    </article>

  <?php endforeach; ?>
  </div>

<?php endif; ?>
</section>

<?php endif; ?>

</main>

<footer>
  <span>Ideas 2 Assets</span>
  <span>IPMdb.ai</span>
</footer>

<script>
const input = document.getElementById('adminSearch');
const rows = Array.from(document.querySelectorAll('.row'));

if (input) {
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();

    rows.forEach(row => {
      const haystack = row.dataset.search || '';
      row.style.display = haystack.includes(q) ? '' : 'none';
    });
  });
}
</script>

</body>
</html>
