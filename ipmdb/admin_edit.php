<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
ipmdb_require_login();

function h(?string $v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function clean_text(string $v, int $max): string {
  $v = trim(strip_tags($v));
  return mb_substr($v, 0, $max, 'UTF-8');
}

function ipmdb_config(): array {
  $local = __DIR__ . '/config.local.php';
  if (is_file($local)) return require $local;
  return require __DIR__ . '/config.php';
}

$config = ipmdb_config();

$assetId = trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? $_POST['asset_id'] ?? ''));
$asset = null;
$error = '';
$success = '';

$categories = [
  'Uncategorized',
  'Technology',
  'Software',
  'Hardware',
  'Manufacturing',
  'Energy',
  'Transportation',
  'Governance',
  'Housing',
  'DAD',
  'COPO',
  'PCWM',
  'Public Service',
  'Economic Security',
  'Sandola',
  'Other',
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

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS ipmdb_asset_versions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      asset_id VARCHAR(80) NOT NULL,
      version_number INT UNSIGNED NOT NULL,
      email VARCHAR(255) NULL,
      title VARCHAR(255) NULL,
      category VARCHAR(255) NULL,
      idea MEDIUMTEXT NULL,
      saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY asset_id_index (asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  try {
    $pdo->exec("ALTER TABLE ipmdb_assets MODIFY version VARCHAR(40) NOT NULL DEFAULT '1.0'");
  } catch (Throwable $ignored) {
  }

  if ($assetId === '') {
    $error = 'Missing Asset ID.';
  } else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      ipmdb_require_csrf();
      $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
      $title = clean_text((string)($_POST['title'] ?? ''), 120);
      $category = clean_text((string)($_POST['category'] ?? 'Uncategorized'), 120);
      $idea = clean_text((string)($_POST['idea'] ?? ''), 5000);

      if ($category === '') {
        $category = 'Uncategorized';
      }

      if (!$email || $title === '' || $idea === '') {
        $error = 'Title, email, and idea are required.';
      } else {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
          SELECT asset_id, email, title, category, idea, version
          FROM ipmdb_assets
          WHERE asset_id = :asset_id
          LIMIT 1
          FOR UPDATE
        ");

        $stmt->execute([
          ':asset_id' => $assetId,
        ]);

        $current = $stmt->fetch();

        if (!$current) {
          $pdo->rollBack();
          $error = 'Asset not found.';
        } else {
          $versionStmt = $pdo->prepare("
            SELECT COALESCE(MAX(version_number), 0) + 1
            FROM ipmdb_asset_versions
            WHERE asset_id = :asset_id
          ");

          $versionStmt->execute([
            ':asset_id' => $assetId,
          ]);

          $archiveVersionNumber = (int)$versionStmt->fetchColumn();

          $insert = $pdo->prepare("
            INSERT INTO ipmdb_asset_versions
              (asset_id, version_number, email, title, category, idea)
            VALUES
              (:asset_id, :version_number, :email, :title, :category, :idea)
          ");

          $insert->execute([
            ':asset_id' => (string)$current['asset_id'],
            ':version_number' => $archiveVersionNumber,
            ':email' => (string)$current['email'],
            ':title' => (string)$current['title'],
            ':category' => (string)$current['category'],
            ':idea' => (string)$current['idea'],
          ]);

          $nextVersion = '1.' . $archiveVersionNumber;

          $update = $pdo->prepare("
            UPDATE ipmdb_assets
            SET
              email = :email,
              title = :title,
              category = :category,
              idea = :idea,
              version = :version
            WHERE asset_id = :asset_id
            LIMIT 1
          ");

          $update->execute([
            ':email' => $email,
            ':title' => $title,
            ':category' => $category,
            ':idea' => $idea,
            ':version' => $nextVersion,
            ':asset_id' => $assetId,
          ]);

          $pdo->commit();

          header('Location: /ipmdb/asset.php?id=' . rawurlencode($assetId));
          exit;
        }
      }
    }

    $stmt = $pdo->prepare("
      SELECT asset_id, email, title, category, idea, version
      FROM ipmdb_assets
      WHERE asset_id = :asset_id
      LIMIT 1
    ");

    $stmt->execute([
      ':asset_id' => $assetId,
    ]);

    $asset = $stmt->fetch();

    if (!$asset && $error === '') {
      $error = 'Asset not found.';
    }
  }
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  $error = ipmdb_public_error($e, 'The asset could not be saved.');
}

$title = $asset['title'] ?? '';
$email = $asset['email'] ?? '';
$category = $asset['category'] ?? 'Uncategorized';
$idea = $asset['idea'] ?? '';
$version = $asset['version'] ?? '1.0';

if (!in_array($category, $categories, true)) {
  $categories[] = $category;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit Asset | IPMdb</title>
<style>
body{margin:0;min-height:100svh;background:#020617;color:#e5f2ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
main{width:min(900px,94vw);margin:0 auto;padding:28px 0 96px}
.top{display:flex;justify-content:space-between;gap:18px;margin-bottom:22px}
.brand{font-size:clamp(42px,8vw,92px);font-weight:1000;letter-spacing:-.06em;line-height:.9}
.brand span{color:#86efac}
.sub{margin-top:10px;color:#9fb4ca;font-size:clamp(18px,3vw,28px);font-weight:800}
a{color:#60a5fa;text-decoration:none;font-weight:900}
.links{display:flex;gap:12px;flex-wrap:wrap;padding-top:12px}
.pill{border:1px solid rgba(148,163,184,.25);border-radius:999px;padding:10px 14px;background:rgba(15,23,42,.72)}
.card{border:1px solid rgba(148,163,184,.25);border-radius:28px;background:rgba(15,23,42,.78);box-shadow:0 24px 90px rgba(0,0,0,.42);overflow:hidden}
form{padding:clamp(20px,5vw,44px)}
.asset-id{color:#86efac;font-size:clamp(20px,4vw,36px);font-weight:1000;margin-bottom:10px;word-break:break-word}
.version-pill{display:inline-block;margin-bottom:20px;border:1px solid rgba(96,165,250,.32);border-radius:999px;padding:8px 12px;background:rgba(37,99,235,.18);color:#bfdbfe;font-size:14px;font-weight:1000;text-transform:uppercase;letter-spacing:.08em}
label{display:block;color:#9fb4ca;font-size:13px;font-weight:1000;letter-spacing:.13em;text-transform:uppercase;margin:18px 0 8px}
input,select,textarea{width:100%;box-sizing:border-box;border:1px solid rgba(148,163,184,.25);border-radius:18px;background:rgba(2,6,23,.56);color:#e5f2ff;padding:18px 20px;font-size:20px;font-weight:800;outline:none}
select{appearance:auto}
textarea{min-height:260px;resize:vertical;line-height:1.45}
.message{margin-bottom:18px;border-radius:18px;padding:16px 18px;font-size:18px;font-weight:900}
.success{color:#bbf7d0;background:rgba(21,128,61,.24);border:1px solid rgba(134,239,172,.3)}
.error{color:#fecaca;background:rgba(127,29,29,.24);border:1px solid rgba(254,202,202,.3)}
.actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}
button,.btn{display:block;width:100%;box-sizing:border-box;text-align:center;border:0;cursor:pointer;border-radius:999px;padding:16px 18px;background:#2563eb;color:#fff;font-size:16px;font-weight:1000;text-transform:uppercase}
.btn.alt{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.20)}
footer{position:fixed;left:0;right:0;bottom:0;padding:14px 22px;background:rgba(2,6,23,.88);border-top:1px solid rgba(148,163,184,.24);display:flex;justify-content:space-between;color:#9fb4ca;font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
@media(max-width:760px){.top{display:block}.actions{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
  <div class="top">
    <div>
      <div class="brand">Edit <span>Asset</span></div>
      <div class="sub">Administrator editing console.</div>
    </div>

    <div class="links">
      <a class="pill" href="/ipmdb/admin.php">Admin</a>
      <a class="pill" href="/ipmdb/ledger.php">Ledger</a>
    </div>
  </div>

  <section class="card">
    <?php if ($asset): ?>
      <form method="post" action="/ipmdb/admin_edit.php">
        <?= ipmdb_csrf_field() ?>
        <input type="hidden" name="asset_id" value="<?= h($assetId) ?>">

        <div class="asset-id"><?= h($assetId) ?></div>
        <div class="version-pill">Version <?= h((string)$version) ?></div>

        <?php if ($success !== ''): ?>
          <div class="message success"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="message error"><?= h($error) ?></div>
        <?php endif; ?>

        <label for="title">Title</label>
        <input id="title" name="title" value="<?= h($title) ?>" maxlength="120" required>

        <label for="category">Category</label>
        <select id="category" name="category" required>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat) ?>" <?= $cat === $category ? 'selected' : '' ?>>
              <?= h($cat) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= h($email) ?>" required>

        <label for="idea">Idea</label>
        <textarea id="idea" name="idea" maxlength="5000" required><?= h($idea) ?></textarea>

        <div class="actions">
          <button type="submit">Save Version</button>
          <a class="btn alt" href="/ipmdb/asset.php?id=<?= urlencode($assetId) ?>">View Asset</a>
        </div>
      </form>
    <?php else: ?>
      <form>
        <div class="message error"><?= h($error ?: 'Asset unavailable.') ?></div>

        <div class="actions">
          <a class="btn" href="/ipmdb/admin.php">Admin</a>
          <a class="btn alt" href="/ipmdb/ledger.php">Ledger</a>
        </div>
      </form>
    <?php endif; ?>
  </section>
</main>

<footer>
  <span>Ideas 2 Assets</span>
  <span>IPMdb.ai</span>
</footer>
</body>
</html>
