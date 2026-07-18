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

function e(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ipmdb_fail(string $message, int $status = 400): never {
  http_response_code($status);
  echo '<!doctype html><html lang="en"><head>';
  echo '<meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>IPMdb | Save Error</title>';
  echo '</head>';
  echo '<body style="font-family:Arial,sans-serif;background:#020617;color:#e5e7eb;padding:24px;">';
  echo '<h1>IPMdb</h1>';
  echo '<p>' . e($message) . '</p>';
  echo '<p><a style="color:#86efac;" href="/ipmdb/ledger.php">Back to Ledger</a></p>';
  echo '</body></html>';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  ipmdb_fail('Invalid request method.', 405);
}

ipmdb_require_csrf();

$assetId  = trim((string)($_POST['asset_id'] ?? ''));
$title    = trim((string)($_POST['title'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));
$idea     = trim((string)($_POST['idea'] ?? ''));

if ($assetId === '') ipmdb_fail('Missing Asset ID.');
if ($title === '') ipmdb_fail('Missing title.');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) ipmdb_fail('Valid email required.');
if ($idea === '') ipmdb_fail('Missing idea.');

$config = ipmdb_config();

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

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    SELECT asset_id, email, title, category, idea, version
    FROM ipmdb_assets
    WHERE asset_id = :asset_id
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([':asset_id' => $assetId]);
  $current = $stmt->fetch();

  if (!$current) {
    $pdo->rollBack();
    ipmdb_fail('Asset not found.', 404);
  }

  $versionStmt = $pdo->prepare("
    SELECT COALESCE(MAX(version_number), 0) + 1
    FROM ipmdb_asset_versions
    WHERE asset_id = :asset_id
  ");
  $versionStmt->execute([':asset_id' => $assetId]);
  $archiveVersionNumber = (int)$versionStmt->fetchColumn();

  $insert = $pdo->prepare("
    INSERT INTO ipmdb_asset_versions
      (asset_id, version_number, email, title, category, idea)
    VALUES
      (:asset_id, :version_number, :email, :title, :category, :idea)
  ");
  $insert->execute([
    ':asset_id'       => (string)$current['asset_id'],
    ':version_number' => $archiveVersionNumber,
    ':email'          => (string)$current['email'],
    ':title'          => (string)$current['title'],
    ':category'       => (string)$current['category'],
    ':idea'           => (string)$current['idea'],
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
    ':email'    => $email,
    ':title'    => $title,
    ':category' => $category !== '' ? $category : 'Uncategorized',
    ':idea'     => $idea,
    ':version'  => $nextVersion,
    ':asset_id' => $assetId,
  ]);

  $pdo->commit();

  header('Location: /ipmdb/viewer.php?asset_id=' . rawurlencode($assetId));
  exit;

} catch (Throwable $err) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

  error_log('IPMdb version save failed: ' . $err->getMessage());
  ipmdb_fail('The asset version could not be saved.', 500);
}
