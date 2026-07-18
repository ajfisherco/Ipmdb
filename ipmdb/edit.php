<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

function ipmdb_config(): array {
  $local = __DIR__ . '/config.local.php';
  $main  = __DIR__ . '/config.php';

  if (is_file($local)) return require $local;
  if (is_file($main)) return require $main;

  http_response_code(500);
  exit('IPMdb config file missing.');
}

function e(?string $v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$assetId = trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? ''));
$error = '';
$asset = null;

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

  if ($assetId === '') {
    $error = 'Missing Asset ID.';
  } else {
    $stmt = $pdo->prepare("SELECT * FROM ipmdb_assets WHERE asset_id = ? LIMIT 1");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();

    if (!$asset) {
      http_response_code(404);
      $error = 'Asset not found.';
    }
  }
} catch (Throwable $ex) {
  http_response_code(500);
  $error = ipmdb_public_error($ex, 'The asset could not be loaded for editing.');
}

$title = (string)($asset['title'] ?? '');
$category = (string)($asset['category'] ?? 'Uncategorized');
$email = (string)($asset['email'] ?? '');
$idea = (string)($asset['idea'] ?? '');
$status = (string)($asset['status'] ?? 'Draft');
$version = (string)($asset['version'] ?? '1.0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Asset | IPMdb</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100svh;background:radial-gradient(circle at top left,rgba(96,165,250,.22),transparent 34rem),radial-gradient(circle at bottom right,rgba(134,239,172,.16),transparent 32rem),#020617;color:#e5e7eb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
a{color:inherit;text-decoration:none}
.wrap{width:min(1120px,94vw);margin:0 auto;padding:24px 0 44px}
.top{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px}
.brand strong{display:block;color:#60a5fa;font-size:clamp(32px,6vw,58px);line-height:.9;letter-spacing:-.06em}
.brand span{color:#94a3b8;font-size:13px;letter-spacing:.22em;text-transform:uppercase}
.nav,.actions{display:flex;flex-wrap:wrap;gap:9px}
.nav{justify-content:flex-end}
.btn,button{border:1px solid rgba(148,163,184,.28);background:rgba(96,165,250,.14);color:#e5e7eb;border-radius:999px;padding:10px 14px;font-weight:900;font-size:14px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;min-height:42px}
button.primary{background:rgba(134,239,172,.16);border-color:rgba(134,239,172,.32)}
.panel{border:1px solid rgba(148,163,184,.28);background:rgba(15,23,42,.88);border-radius:24px;padding:clamp(18px,4vw,30px);box-shadow:0 24px 80px rgba(0,0,0,.28)}
h1{margin:0;font-size:clamp(34px,7vw,68px);line-height:.9;letter-spacing:-.07em}
.sub{color:#94a3b8;margin:10px 0 18px;line-height:1.45}
.meta{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:18px}
.pill{border:1px solid rgba(148,163,184,.28);background:rgba(2,6,23,.48);border-radius:999px;padding:8px 11px;color:#cbd5e1;font-size:13px}
label{display:block;margin:13px 0 6px;color:#94a3b8;font-size:13px;letter-spacing:.15em;text-transform:uppercase;font-weight:900}
input,textarea{width:100%;border:1px solid rgba(148,163,184,.32);background:rgba(2,6,23,.72);color:#e5e7eb;border-radius:18px;padding:13px 14px;font-size:18px;outline:none}
input:focus,textarea:focus{border-color:rgba(96,165,250,.8);box-shadow:0 0 0 4px rgba(96,165,250,.16)}
textarea{min-height:220px;resize:vertical;line-height:1.5}
textarea.expanded{min-height:70svh}
.gauge-wrap{margin-top:10px;display:grid;gap:7px}
.gauge-top{display:flex;justify-content:space-between;gap:12px;color:#94a3b8;font-size:13px}
.gauge{height:10px;border-radius:999px;overflow:hidden;background:rgba(148,163,184,.18)}
.bar{width:0%;height:100%;background:linear-gradient(90deg,#60a5fa,#86efac);transition:width .12s linear,background .12s linear}
.bar.warn{background:linear-gradient(90deg,#facc15,#fb923c)}
.bar.danger{background:linear-gradient(90deg,#fb7185,#ef4444)}
.error{border:1px solid rgba(251,113,133,.45);background:rgba(127,29,29,.28);color:#fecdd3;padding:16px;border-radius:18px;line-height:1.55}
@media(max-width:720px){.top{align-items:flex-start;flex-direction:column}.nav,.actions{justify-content:flex-start}.btn,button{width:100%}.gauge-top{flex-direction:column}}
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
<a class="btn" href="/ipmdb/search.php" tabindex="1">Search</a>
<a class="btn" href="/ipmdb/ledger.php" tabindex="2">Ledger</a>
<a class="btn" href="/ipmdb/viewer.php?asset_id=<?= rawurlencode($assetId) ?>" tabindex="3">Viewer</a>
<a class="btn" href="/ipmdb/history.php?asset_id=<?= rawurlencode($assetId) ?>" tabindex="4">History</a>
</nav>
</header>

<?php if ($error): ?>

<section class="error"><?= e($error) ?></section>

<?php else: ?>

<section class="panel">
<h1>Edit Asset</h1>
<p class="sub">Update the live asset while preserving version history.</p>

<div class="meta">
<span class="pill"><?= e($assetId) ?></span>
<span class="pill">Status: <?= e($status) ?></span>
<span class="pill">Version: <?= e($version) ?></span>
</div>

<form method="post" action="/ipmdb/save_version.php">
<?= ipmdb_csrf_field() ?>
<input type="hidden" name="asset_id" value="<?= e($assetId) ?>">

<label for="title">Title</label>
<input id="title" name="title" value="<?= e($title) ?>" maxlength="180" autocomplete="off" tabindex="5">

<label for="category">Category</label>
<input id="category" name="category" value="<?= e($category) ?>" maxlength="120" autocomplete="off" tabindex="6">

<label for="email">Originator Email</label>
<input id="email" name="email" type="email" value="<?= e($email) ?>" maxlength="180" autocomplete="email" tabindex="7">

<label for="idea">Idea</label>
<textarea id="idea" name="idea" maxlength="5000" tabindex="8"><?= e($idea) ?></textarea>

<div class="gauge-wrap">
<div class="gauge-top">
<span id="count">0 / 5000</span>
<button class="btn" type="button" id="expandBtn" tabindex="9">⛶ Expand</button>
</div>
<div class="gauge"><div class="bar" id="bar"></div></div>
</div>

<div class="actions" style="margin-top:22px;">
<button class="primary" type="submit" tabindex="10">Save Version</button>
<a class="btn" href="/ipmdb/viewer.php?asset_id=<?= rawurlencode($assetId) ?>" tabindex="11">Cancel</a>
<a class="btn" href="/ipmdb/history.php?asset_id=<?= rawurlencode($assetId) ?>" tabindex="12">History</a>
<a class="btn" href="/ipmdb/ledger.php" tabindex="13">Ledger</a>
</div>
</form>
</section>

<?php endif; ?>

</main>

<script>
const idea=document.getElementById('idea');
const count=document.getElementById('count');
const bar=document.getElementById('bar');
const expandBtn=document.getElementById('expandBtn');

function updateGauge(){
  if(!idea||!count||!bar)return;
  const max=Number(idea.getAttribute('maxlength')||5000);
  const len=idea.value.length;
  const pct=Math.min(100,(len/max)*100);
  count.textContent=len+' / '+max;
  bar.style.width=pct+'%';
  bar.classList.remove('warn','danger');
  if(len>=4800){bar.classList.add('danger');}
  else if(len>=4500){bar.classList.add('warn');}
}

if(idea){
  idea.addEventListener('input',updateGauge);
  updateGauge();
}

if(expandBtn&&idea){
  expandBtn.addEventListener('click',function(){
    idea.classList.toggle('expanded');
    expandBtn.textContent=idea.classList.contains('expanded')?'↧ Collapse':'⛶ Expand';
    idea.focus();
  });
}
</script>
</body>
</html>
