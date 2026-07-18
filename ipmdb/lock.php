<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';

function ipmdb_config(): array
{
    $path = is_file(__DIR__ . '/config.local.php')
        ? __DIR__ . '/config.local.php'
        : __DIR__ . '/config.php';

    $config = require $path;

    if (!is_array($config) || !is_array($config['db'] ?? null)) {
        throw new RuntimeException('Database configuration is unavailable.');
    }

    return $config;
}

function clean_text(string $value, int $max): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? '';

    return function_exists('mb_substr')
        ? mb_substr($value, 0, $max, 'UTF-8')
        : substr($value, 0, $max);
}

function clean_long_text(string $value, int $max): string
{
    $value = trim(strip_tags($value));
    $value = str_replace(["\r\n", "\r"], "\n", $value);

    return function_exists('mb_substr')
        ? mb_substr($value, 0, $max, 'UTF-8')
        : substr($value, 0, $max);
}

function ipmdb_next_asset_id(PDO $pdo): string
{
    $prefix = 'IPM-' . gmdate('Ymd') . '-';
    $stmt = $pdo->prepare(
        'SELECT asset_id
         FROM ipmdb_assets
         WHERE asset_id LIKE ?
         ORDER BY asset_id DESC
         LIMIT 1'
    );
    $stmt->execute([$prefix . '%']);

    $last = (string)($stmt->fetchColumn() ?: '');
    $next = $last === '' ? 1 : ((int)substr($last, -6) + 1);

    return $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}

function ipmdb_optional_mail(array $asset): void
{
    $mailFile = __DIR__ . '/mail.php';

    if (is_file($mailFile)) {
        require_once $mailFile;
    }

    if (!function_exists('ipmdb_send_acknowledgement')) {
        return;
    }

    try {
        ipmdb_send_acknowledgement($asset);
    } catch (Throwable $error) {
        error_log('IPMdb acknowledgement failed: ' . $error->getMessage());
    }
}

function ipmdb_rate_limit_lock(): void
{
    ipmdb_start_session();

    $cutoff = time() - 600;
    $attempts = array_values(array_filter(
        is_array($_SESSION['ipmdb_lock_attempts'] ?? null)
            ? $_SESSION['ipmdb_lock_attempts']
            : [],
        static fn($timestamp): bool => (int)$timestamp >= $cutoff
    ));

    if (count($attempts) >= 5) {
        header('Retry-After: 600');
        http_response_code(429);
        exit('Please wait ten minutes before locking another idea.');
    }

    $attempts[] = time();
    $_SESSION['ipmdb_lock_attempts'] = $attempts;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /ipmdb/');
    exit;
}

ipmdb_require_csrf();

if (trim((string)($_POST['website'] ?? '')) !== '') {
    http_response_code(204);
    exit;
}

ipmdb_rate_limit_lock();

$emailRaw = trim((string)($_POST['email'] ?? ''));
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
$title = clean_text((string)($_POST['title'] ?? ''), 120);
$idea = clean_long_text((string)($_POST['idea'] ?? ''), 5000);
$category = clean_text((string)($_POST['category'] ?? 'Uncategorized'), 120);
$category = $category !== '' ? $category : 'Uncategorized';

if (!$email || $title === '' || $idea === '') {
    http_response_code(422);
    exit('A valid email, title, and idea are required.');
}

try {
    $config = ipmdb_config();
    $db = $config['db'];

    if (empty($db['dsn']) || empty($db['user'])) {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    $pdo = new PDO(
        (string)$db['dsn'],
        (string)$db['user'],
        (string)($db['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $assetId = '';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $assetId = ipmdb_next_asset_id($pdo);

        try {
            $insert = $pdo->prepare(
                'INSERT INTO ipmdb_assets
                    (asset_id, email, title, category, idea, status, version)
                 VALUES
                    (:asset_id, :email, :title, :category, :idea, :status, :version)'
            );

            $insert->execute([
                'asset_id' => $assetId,
                'email' => (string)$email,
                'title' => $title,
                'category' => $category,
                'idea' => $idea,
                'status' => 'Draft',
                'version' => '1.0',
            ]);
            break;
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000' || $attempt === 4) {
                throw $error;
            }
            usleep(25000);
        }
    }

    $asset = [
        'asset_id' => $assetId,
        'email' => (string)$email,
        'title' => $title,
        'category' => $category,
        'idea' => $idea,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ];

    ipmdb_optional_mail($asset);

    header('Location: /ipmdb/viewer.php?asset_id=' . rawurlencode($assetId));
    exit;
} catch (Throwable $error) {
    error_log('IPMdb lock failed: ' . $error->getMessage());
    http_response_code(500);
    exit('The idea could not be locked. Please try again.');
}
