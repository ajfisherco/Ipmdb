<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function ipmdb_config(): array
{
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        return require $local;
    }
    return require __DIR__ . '/config.php';
}

function ipmdb_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ipmdb_pdo(): PDO
{
    $config = ipmdb_config();
    $dsn = $config['db']['dsn'] ?? '';
    $user = $config['db']['user'] ?? '';
    $pass = $config['db']['pass'] ?? '';

    if ($dsn === '' || $user === '') {
        ipmdb_json(['ok' => false, 'error' => 'Database configuration is incomplete.'], 500);
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function ipmdb_request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        ipmdb_json(['ok' => false, 'error' => 'Invalid JSON request.'], 400);
    }
    return $data;
}

function ipmdb_clean(string $value, int $limit = 3000): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_substr($value, 0, $limit);
}
