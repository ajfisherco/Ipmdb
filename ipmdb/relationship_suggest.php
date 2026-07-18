<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

function suggestion_config(): array
{
    $path = is_file(__DIR__ . '/config.local.php')
        ? __DIR__ . '/config.local.php'
        : __DIR__ . '/config.php';

    return require $path;
}

function suggestion_words(string $text): array
{
    $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    $text = preg_replace('/[^\pL\pN\s]+/u', ' ', $text) ?? '';
    $parts = preg_split('/\s+/u', trim($text)) ?: [];
    $stopWords = array_fill_keys([
        'about', 'after', 'also', 'asset', 'because', 'been', 'being', 'from',
        'have', 'idea', 'into', 'more', 'that', 'their', 'there', 'these',
        'they', 'this', 'those', 'what', 'when', 'where', 'which', 'with',
        'would', 'your',
    ], true);

    $words = [];

    foreach ($parts as $word) {
        $length = function_exists('mb_strlen') ? mb_strlen($word) : strlen($word);

        if ($length < 4 || isset($stopWords[$word])) {
            continue;
        }

        $words[$word] = true;
    }

    return array_keys($words);
}

function suggestion_score(array $source, array $candidate): array
{
    $sourceText = implode(' ', [
        $source['title'] ?? '',
        $source['category'] ?? '',
        $source['idea'] ?? '',
    ]);
    $candidateText = implode(' ', [
        $candidate['title'] ?? '',
        $candidate['category'] ?? '',
        $candidate['idea'] ?? '',
    ]);

    $shared = array_values(array_intersect(
        suggestion_words($sourceText),
        suggestion_words($candidateText)
    ));
    $score = count($shared) * 2;

    if (
        trim((string)($source['category'] ?? '')) !== ''
        && strcasecmp(
            (string)($source['category'] ?? ''),
            (string)($candidate['category'] ?? '')
        ) === 0
    ) {
        $score += 4;
    }

    return ['score' => $score, 'shared' => array_slice($shared, 0, 8)];
}

$assetId = substr(trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? '')), 0, 128);
$asset = null;
$suggestions = [];
$error = '';

try {
    if ($assetId === '') {
        throw new InvalidArgumentException('Choose an asset first.');
    }

    $config = suggestion_config();
    $db = $config['db'] ?? [];
    $pdo = new PDO(
        (string)($db['dsn'] ?? ''),
        (string)($db['user'] ?? ''),
        (string)($db['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $stmt = $pdo->prepare(
        'SELECT asset_id, title, category, idea, status, version
         FROM ipmdb_assets
         WHERE asset_id = ?
         LIMIT 1'
    );
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();

    if (!$asset) {
        http_response_code(404);
        throw new RuntimeException('Asset not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT asset_id, title, category, idea, status, version
         FROM ipmdb_assets
         WHERE asset_id <> ?
         ORDER BY created_at DESC
         LIMIT 500'
    );
    $stmt->execute([$assetId]);

    foreach ($stmt->fetchAll() as $candidate) {
        $match = suggestion_score($asset, $candidate);

        if ($match['score'] < 2) {
            continue;
        }

        $candidate['suggestion_score'] = $match['score'];
        $candidate['shared_terms'] = $match['shared'];
        $suggestions[] = $candidate;
    }

    usort($suggestions, static function (array $left, array $right): int {
        return ($right['suggestion_score'] <=> $left['suggestion_score'])
            ?: strcmp((string)$right['asset_id'], (string)$left['asset_id']);
    });
    $suggestions = array_slice($suggestions, 0, 20);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    $error = ipmdb_public_error($exception, 'Relationship suggestions are temporarily unavailable.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relationship Suggestions | IPMdb</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100svh;background:linear-gradient(135deg,#020617,#0f172a);color:#e5f2ff;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}main{width:min(1000px,94vw);margin:auto;padding:28px 0 60px}a{color:#93c5fd;text-decoration:none}.nav,.actions{display:flex;gap:10px;flex-wrap:wrap}.nav{justify-content:space-between;align-items:center;margin-bottom:24px}.brand{font-size:clamp(30px,7vw,58px);font-weight:1000}.card{margin:14px 0;padding:20px;border:1px solid #334155;border-radius:22px;background:rgba(15,23,42,.78)}.hero{border-color:#60a5fa}.id,.score{color:#86efac;font-weight:900}.muted{color:#94a3b8}.terms{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0}.terms span,.actions a{padding:7px 10px;border:1px solid #334155;border-radius:999px}.error{color:#fecaca;border-color:#ef4444}h1,h2{margin:0 0 8px}
</style>
</head>
<body>
<main>
  <nav class="nav" aria-label="Primary">
    <a class="brand" href="/ipmdb/">IPMdb</a>
    <div class="actions">
      <a href="/ipmdb/ledger.php">Ledger</a>
      <a href="/ipmdb/relationship_explorer.php">Graph</a>
    </div>
  </nav>

  <?php if ($error !== ''): ?>
    <section class="card error"><h1>Suggestions</h1><p><?= h($error) ?></p></section>
  <?php elseif ($asset): ?>
    <section class="card hero">
      <div class="id"><?= h((string)$asset['asset_id']) ?></div>
      <h1><?= h(ipmdb_asset_title($asset)) ?></h1>
      <p class="muted">Candidate relationships ranked by shared concepts and category.</p>
    </section>

    <?php if ($suggestions === []): ?>
      <section class="card"><h2>No strong match yet</h2><p class="muted">More connected assets will improve the suggestions.</p></section>
    <?php else: ?>
      <?php foreach ($suggestions as $item): ?>
        <article class="card">
          <div class="score">Match score <?= h((string)$item['suggestion_score']) ?></div>
          <h2><?= h(ipmdb_asset_title($item)) ?></h2>
          <div class="id"><?= h((string)$item['asset_id']) ?></div>
          <?php if ($item['shared_terms'] !== []): ?>
            <div class="terms">
              <?php foreach ($item['shared_terms'] as $term): ?><span><?= h((string)$term) ?></span><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="actions">
            <a href="/ipmdb/viewer.php?asset_id=<?= rawurlencode((string)$item['asset_id']) ?>">View</a>
            <a href="/ipmdb/relationship_add.php?asset_id=<?= rawurlencode($assetId) ?>&amp;related_asset_id=<?= rawurlencode((string)$item['asset_id']) ?>">Relate</a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
