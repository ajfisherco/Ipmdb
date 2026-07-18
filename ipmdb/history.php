<?php
declare(strict_types=1);

function h(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ipmdb_config(): array {
  $local = __DIR__ . '/config.local.php';
  $main  = __DIR__ . '/config.php';

  if (is_file($local)) return require $local;
  if (is_file($main)) return require $main;

  http_response_code(500);
  exit('IPMdb config file missing.');
}

$assetId = trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? ''));
$asset = null;
$versions = [];
$error = '';

if ($assetId === '') {
  http_response_code(400);
  $error = 'Missing Asset ID.';
} else {
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

    $assetStmt = $pdo->prepare("
      SELECT asset_id, title, category, idea, status, version, created_at
      FROM ipmdb_assets
      WHERE asset_id = :asset_id
      LIMIT 1
    ");
    $assetStmt->execute([':asset_id' => $assetId]);
    $asset = $assetStmt->fetch();

    if (!$asset) {
      http_response_code(404);
      $error = 'Asset not found.';
    } else {
      $versionStmt = $pdo->prepare("
        SELECT id, asset_id, version_number, title, category, idea, saved_at
        FROM ipmdb_asset_versions
        WHERE asset_id = :asset_id
        ORDER BY version_number DESC, id DESC
      ");
      $versionStmt->execute([':asset_id' => $assetId]);
      $versions = $versionStmt->fetchAll();
    }
  } catch (Throwable $e) {
    http_response_code(500);
    error_log('IPMdb history failed: ' . $e->getMessage());
    $error = 'History is temporarily unavailable.';
  }
}

$currentVersion = (string)($asset['version'] ?? '1.0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($assetId ?: 'Asset') ?> History | IPMdb</title>
<style>
body{margin:0;min-height:100svh;background:radial-gradient(circle at top left,rgba(96,165,250,.26),transparent 38%),radial-gradient(circle at bottom right,rgba(134,239,172,.18),transparent 34%),#020617;color:#e5f2ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
main{width:min(1100px,94vw);margin:0 auto;padding:28px 0 96px}
.top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:22px}
.brand{font-size:clamp(40px,8vw,88px);font-weight:1000;letter-spacing:-.06em;line-height:.9}
.brand span{color:#86efac}
.sub{margin-top:10px;color:#9fb4ca;font-size:clamp(18px,3vw,28px);font-weight:800}
.links{display:flex;gap:12px;flex-wrap:wrap;padding-top:12px}
a{color:#60a5fa;text-decoration:none;font-weight:900}
.pill{border:1px solid rgba(148,163,184,.25);border-radius:999px;padding:10px 14px;background:rgba(15,23,42,.72);white-space:nowrap}
.card{border:1px solid rgba(148,163,184,.25);border-radius:28px;background:rgba(15,23,42,.78);box-shadow:0 24px 90px rgba(0,0,0,.42);overflow:hidden;margin-bottom:18px}
.hero{padding:clamp(22px,5vw,44px);border-bottom:1px solid rgba(148,163,184,.22)}
.asset-id{color:#86efac;font-size:clamp(18px,3vw,30px);font-weight:1000;letter-spacing:.04em;margin-bottom:10px;word-break:break-word}
h1{margin:0;font-size:clamp(38px,7vw,76px);line-height:.95;letter-spacing:-.05em;word-break:break-word}
.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:rgba(148,163,184,.22)}
.meta div{background:rgba(2,6,23,.56);padding:18px}
.label{color:#9fb4ca;font-size:12px;font-weight:1000;letter-spacing:.13em;text-transform:uppercase;margin-bottom:7px}
.value{font-size:clamp(18px,2.5vw,26px);font-weight:900;word-break:break-word}
.badge{display:inline-block;border:1px solid rgba(96,165,250,.32);border-radius:999px;padding:8px 12px;background:rgba(37,99,235,.18);color:#bfdbfe;font-weight:1000}
.version-card{padding:clamp(20px,4vw,34px);border-bottom:1px solid rgba(148,163,184,.22)}
.version-card:last-child{border-bottom:0}
.version-head{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.version-title{font-size:clamp(26px,5vw,46px);font-weight:1000;letter-spacing:-.04em}
.time{color:#9fb4ca;font-weight:800}
.grid{display:grid;grid-template-columns:1fr;gap:12px}
.field{border:1px solid rgba(148,163,184,.20);border-radius:20px;background:rgba(2,6,23,.42);padding:16px}
.idea{white-space:pre-wrap;line-height:1.45;color:#cfe7ff}
.error,.empty{padding:clamp(24px,5vw,52px);font-size:clamp(24px,5vw,48px);font-weight:1000;letter-spacing:-.04em}
.error{color:#fecaca;background:rgba(127,29,29,.24)}
.empty{color:#cfe7ff}
footer{position:fixed;left:0;right:0;bottom:0;padding:14px 22px;background:rgba(2,6,23,.88);border-top:1px solid rgba(148,163,184,.24);display:flex;justify-content:space-between;color:#9fb4ca;font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
@media(max-width:820px){.top{display:block}.meta{grid-template-columns:1fr}.links{padding-top:18px}}
</style>
</head>
<body>
<main>
  <div class="top">
    <div>
      <div class="brand">Asset <span>History</span></div>
      <div class="sub">Version trail for one locked idea.</div>
    </div>

    <div class="links">
      <a class="pill" href="/ipmdb/ledger.php">Ledger</a>

      <?php if ($assetId !== ''): ?>
        <a class="pill" href="/ipmdb/viewer.php?asset_id=<?= rawurlencode($assetId) ?>">Current Asset</a>
        <a class="pill" href="/ipmdb/edit.php?asset_id=<?= rawurlencode($assetId) ?>">Edit</a>
      <?php endif; ?>
    </div>
  </div>

  <section class="card">
    <?php if ($error !== ''): ?>
      <div class="error"><?= h($error) ?></div>

    <?php elseif ($asset): ?>
      <div class="hero">
        <div class="asset-id"><?= h($asset['asset_id']) ?></div>
        <h1><?= h($asset['title'] ?: 'Untitled Asset') ?></h1>
      </div>

      <div class="meta">
        <div>
          <div class="label">Current Version</div>
          <div class="value"><span class="badge"><?= h($currentVersion) ?></span></div>
        </div>

        <div>
          <div class="label">Status</div>
          <div class="value"><?= h($asset['status'] ?: 'Draft') ?></div>
        </div>

        <div>
          <div class="label">Category</div>
          <div class="value"><?= h($asset['category'] ?: 'Uncategorized') ?></div>
        </div>

        <div>
          <div class="label">Created</div>
          <div class="value"><?= h($asset['created_at'] ?: 'Unknown') ?></div>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($error === '' && $asset): ?>
    <section class="card">
      <div class="version-card">
        <div class="version-head">
          <div class="version-title">Current Live Version <?= h($currentVersion) ?></div>
          <div class="time">Current record</div>
        </div>

        <div class="grid">
          <div class="field">
            <div class="label">Title</div>
            <div class="value"><?= h($asset['title'] ?: 'Untitled Asset') ?></div>
          </div>

          <div class="field">
            <div class="label">Category</div>
            <div class="value"><?= h($asset['category'] ?: 'Uncategorized') ?></div>
          </div>

          <div class="field">
            <div class="label">Idea</div>
            <div class="value idea"><?= h($asset['idea'] ?: 'No idea entered.') ?></div>
          </div>
        </div>
      </div>

      <?php if (!$versions): ?>
        <div class="empty">No archived versions yet.</div>
      <?php else: ?>
        <?php foreach ($versions as $version): ?>
          <div class="version-card">
            <div class="version-head">
              <div class="version-title">Archived Version 1.<?= h((string)$version['version_number']) ?></div>
              <div class="time"><?= h((string)$version['saved_at']) ?></div>
            </div>

            <div class="grid">
              <div class="field">
                <div class="label">Title</div>
                <div class="value"><?= h((string)$version['title']) ?></div>
              </div>

              <div class="field">
                <div class="label">Category</div>
                <div class="value"><?= h((string)$version['category']) ?></div>
              </div>

              <div class="field">
                <div class="label">Idea</div>
                <div class="value idea"><?= h((string)$version['idea']) ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<footer>
  <span>Ideas 2 Assets</span>
  <span>IPMdb.ai</span>
</footer>
</body>
</html>
