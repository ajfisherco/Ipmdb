<?php
declare(strict_types=1);

function ipmdb_config(): array {
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

function e(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return in_array($table, $tables, true);
  } catch (Throwable $ex) {
    return false;
  }
}

function columns_for(PDO $pdo, string $table): array {
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $cols[] = (string)$row['Field'];
    }

    return $cols;
  } catch (Throwable $ex) {
    return [];
  }
}

function first_existing(array $columns, array $wanted): ?string {
  foreach ($wanted as $col) {
    if (in_array($col, $columns, true)) {
      return $col;
    }
  }

  return null;
}

$config = ipmdb_config();
$assetId = trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? ''));

$asset = null;
$relationships = [];
$error = '';
$notice = '';
$relationshipTable = null;

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

  if ($assetId === '') {
    $error = 'Missing Asset ID.';
  } else {
    $stmt = $pdo->prepare("
      SELECT *
      FROM ipmdb_assets
      WHERE asset_id = ?
      LIMIT 1
    ");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();

    if (!$asset) {
      $error = 'Asset was not found.';
    }
  }

  if (!$error && $asset) {
    $candidateTables = [
      'ipmdb_relationships',
      'ipmdb_asset_relationships',
      'asset_relationships',
      'relationships'
    ];

    foreach ($candidateTables as $table) {
      if (table_exists($pdo, $table)) {
        $relationshipTable = $table;
        break;
      }
    }

    if (!$relationshipTable) {
      $notice = 'Relationship table has not been created yet.';
    } else {
      $cols = columns_for($pdo, $relationshipTable);

      $sourceCol = first_existing($cols, [
        'source_asset_id',
        'from_asset_id',
        'parent_asset_id',
        'asset_id',
        'left_asset_id'
      ]);

      $targetCol = first_existing($cols, [
        'target_asset_id',
        'to_asset_id',
        'child_asset_id',
        'related_asset_id',
        'right_asset_id'
      ]);

      $typeCol = first_existing($cols, [
        'relationship_type',
        'type',
        'relation_type',
        'relationship'
      ]);

      $noteCol = first_existing($cols, [
        'note',
        'notes',
        'description',
        'summary'
      ]);

      $createdCol = first_existing($cols, [
        'created_at',
        'created',
        'date_created'
      ]);

      if (!$sourceCol || !$targetCol) {
        $notice = 'Relationship table exists, but its source and target columns could not be detected.';
      } else {
        $typeSelect = $typeCol ? "r.`$typeCol` AS relationship_type" : "'related' AS relationship_type";
        $noteSelect = $noteCol ? "r.`$noteCol` AS relationship_note" : "'' AS relationship_note";
        $createdSelect = $createdCol ? "r.`$createdCol` AS relationship_created_at" : "NULL AS relationship_created_at";

        $sql = "
          SELECT
            r.`$sourceCol` AS source_asset_id,
            r.`$targetCol` AS target_asset_id,
            $typeSelect,
            $noteSelect,
            $createdSelect,
            a.asset_id,
            a.title,
            a.status,
            a.version,
            a.created_at
          FROM `$relationshipTable` r
          LEFT JOIN ipmdb_assets a
            ON a.asset_id = CASE
              WHEN r.`$sourceCol` = :asset_id_a THEN r.`$targetCol`
              ELSE r.`$sourceCol`
            END
          WHERE r.`$sourceCol` = :asset_id_b
             OR r.`$targetCol` = :asset_id_c
          ORDER BY relationship_created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':asset_id_a' => $assetId,
          ':asset_id_b' => $assetId,
          ':asset_id_c' => $assetId,
        ]);

        $relationships = $stmt->fetchAll();
      }
    }
  }
} catch (Throwable $ex) {
  http_response_code(500);
  require_once __DIR__ . '/includes/security.php';
  $error = ipmdb_public_error($ex, 'The relationship map could not be loaded.');
}

$title = $asset['title'] ?? 'Relationships';
$status = $asset['status'] ?? 'Unknown';
$version = $asset['version'] ?? '';
$createdAt = $asset['created_at'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?> | IPMdb Relationships</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --bg: #020617;
      --panel: rgba(15, 23, 42, .88);
      --panel2: rgba(30, 41, 59, .72);
      --line: rgba(148, 163, 184, .28);
      --text: #e5e7eb;
      --muted: #94a3b8;
      --blue: #60a5fa;
      --green: #86efac;
      --warn: #facc15;
      --bad: #fb7185;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-height: 100svh;
      background:
        radial-gradient(circle at top left, rgba(96,165,250,.22), transparent 34rem),
        radial-gradient(circle at bottom right, rgba(134,239,172,.16), transparent 32rem),
        var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .wrap {
      width: min(1180px, 94vw);
      margin: 0 auto;
      padding: 28px 0 48px;
    }

    .top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 22px;
    }

    .brand {
      display: grid;
      gap: 3px;
    }

    .brand strong {
      font-size: clamp(30px, 5vw, 56px);
      letter-spacing: -.05em;
      color: var(--blue);
      line-height: .95;
    }

    .brand span {
      color: var(--muted);
      font-size: 13px;
      letter-spacing: .22em;
      text-transform: uppercase;
    }

    .nav,
    .actions,
    .ipmdb-asset-actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 10px;
    }

    .btn,
    .button,
    .nav a,
    .ipmdb-asset-actions a {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: rgba(96,165,250,.14);
      color: var(--text);
      padding: 10px 14px;
      font-weight: 800;
      font-size: 14px;
    }

    .btn.primary,
    .ipmdb-asset-actions a:first-child {
      background: linear-gradient(135deg,#60a5fa,#86efac);
      border: 0;
      color: #020617;
    }

    .ipmdb-asset-actions {
      justify-content: flex-start;
      margin-top: 18px;
    }

    .hero,
    .card,
    .notice,
    .error {
      border: 1px solid var(--line);
      background: var(--panel);
      border-radius: 24px;
      box-shadow: 0 24px 80px rgba(0,0,0,.28);
    }

    .hero {
      padding: clamp(20px, 4vw, 34px);
      margin-bottom: 18px;
    }

    .kicker {
      color: var(--green);
      font-size: 13px;
      letter-spacing: .22em;
      text-transform: uppercase;
      margin-bottom: 10px;
    }

    h1 {
      margin: 0;
      font-size: clamp(34px, 7vw, 72px);
      letter-spacing: -.07em;
      line-height: .92;
      overflow-wrap: anywhere;
    }

    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 20px;
      color: var(--muted);
    }

    .pill {
      border: 1px solid var(--line);
      background: rgba(2,6,23,.5);
      border-radius: 999px;
      padding: 8px 11px;
      font-size: 13px;
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
    }

    .card {
      padding: 18px;
    }

    .relation-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 10px;
    }

    .relation-title {
      font-size: clamp(22px, 3vw, 34px);
      font-weight: 900;
      letter-spacing: -.04em;
      line-height: 1;
      overflow-wrap: anywhere;
    }

    .relation-type {
      color: var(--green);
      border: 1px solid rgba(134,239,172,.28);
      background: rgba(22,101,52,.18);
      padding: 7px 10px;
      border-radius: 999px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .12em;
      white-space: nowrap;
    }

    .small {
      color: var(--muted);
      font-size: 14px;
      line-height: 1.5;
      overflow-wrap: anywhere;
    }

    .note {
      margin-top: 12px;
      padding: 12px;
      border-radius: 16px;
      background: var(--panel2);
      color: var(--text);
      line-height: 1.55;
      overflow-wrap: anywhere;
    }

    .empty,
    .notice,
    .error {
      padding: 18px;
      line-height: 1.55;
      color: var(--muted);
    }

    .error {
      border-color: rgba(251,113,133,.4);
      color: #fecdd3;
    }

    .notice {
      border-color: rgba(250,204,21,.34);
      color: #fde68a;
    }

    .setup {
      margin-top: 16px;
      border: 1px solid var(--line);
      background: rgba(2,6,23,.72);
      border-radius: 18px;
      padding: 14px;
      overflow: auto;
    }

    pre {
      margin: 0;
      white-space: pre-wrap;
      color: #dbeafe;
      font-size: 13px;
      line-height: 1.45;
    }

    .foot {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      color: var(--muted);
      font-size: 13px;
      padding-top: 22px;
      flex-wrap: wrap;
    }

    .foot strong {
      color: var(--text);
    }

    @media (max-width: 720px) {
      .top {
        align-items: flex-start;
        flex-direction: column;
      }

      .nav,
      .actions,
      .ipmdb-asset-actions {
        justify-content: flex-start;
      }

      .relation-head {
        flex-direction: column;
      }

      .btn,
      .button,
      .nav a,
      .ipmdb-asset-actions a {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <main class="wrap">

    <?php require __DIR__ . '/includes/header.php'; ?>

    <?php if ($error): ?>
      <section class="error">
        <?= e($error) ?>
      </section>
    <?php else: ?>
      <section class="hero">
        <div class="kicker">Asset Relationship Map</div>
        <h1><?= e($title) ?></h1>

        <div class="meta">
          <span class="pill">Asset ID: <?= e($assetId) ?></span>
          <span class="pill">Status: <?= e($status) ?></span>

          <?php if ($version !== ''): ?>
            <span class="pill">Version: <?= e($version) ?></span>
          <?php endif; ?>

          <?php if ($createdAt !== ''): ?>
            <span class="pill">Created: <?= e($createdAt) ?></span>
          <?php endif; ?>
        </div>

        <?php require __DIR__ . '/includes/asset_actions.php'; ?>
      </section>

      <?php if ($notice): ?>
        <section class="notice">
          <?= e($notice) ?>

          <div class="setup">
<pre>CREATE TABLE ipmdb_relationships (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_asset_id VARCHAR(64) NOT NULL,
  target_asset_id VARCHAR(64) NOT NULL,
  relationship_type VARCHAR(64) NOT NULL DEFAULT 'related',
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX source_asset_id (source_asset_id),
  INDEX target_asset_id (target_asset_id),
  INDEX relationship_type (relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
          </div>
        </section>
      <?php elseif (!$relationships): ?>
        <section class="empty card">
          No relationships have been recorded for this asset yet.
        </section>
      <?php else: ?>
        <section class="grid">
          <?php foreach ($relationships as $rel): ?>
            <?php
              $relatedId = (string)($rel['asset_id'] ?? '');
              $relatedTitle = (string)($rel['title'] ?? '');
              $relType = (string)($rel['relationship_type'] ?? 'related');
              $relNote = (string)($rel['relationship_note'] ?? '');
              $relStatus = (string)($rel['status'] ?? '');
              $relVersion = (string)($rel['version'] ?? '');
              $relCreated = (string)($rel['created_at'] ?? '');
            ?>

            <article class="card">
              <div class="relation-head">
                <div>
                  <a class="relation-title" href="viewer.php?asset_id=<?= urlencode($relatedId) ?>">
                    <?= e($relatedTitle !== '' ? $relatedTitle : $relatedId) ?>
                  </a>

                  <div class="small">
                    Asset ID: <?= e($relatedId !== '' ? $relatedId : 'Unknown') ?>

                    <?php if ($relStatus !== ''): ?>
                      · Status: <?= e($relStatus) ?>
                    <?php endif; ?>

                    <?php if ($relVersion !== ''): ?>
                      · Version: <?= e($relVersion) ?>
                    <?php endif; ?>

                    <?php if ($relCreated !== ''): ?>
                      · Created: <?= e($relCreated) ?>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="relation-type">
                  <?= e($relType) ?>
                </div>
              </div>

              <?php if ($relNote !== ''): ?>
                <div class="note"><?= nl2br(e($relNote)) ?></div>
              <?php endif; ?>

              <div class="actions">
                <a class="button" href="viewer.php?asset_id=<?= urlencode($relatedId) ?>">Open Related Asset</a>
                <a class="button" href="relationships.php?asset_id=<?= urlencode($relatedId) ?>">Map This Asset</a>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/footer.php'; ?>

  </main>
</body>
</html>
