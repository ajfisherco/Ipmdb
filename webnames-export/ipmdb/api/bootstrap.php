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

function ipmdb_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ipmdb_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST ?: [];
}

function ipmdb_pdo(): PDO
{
    $config = ipmdb_config()['db'];
    return new PDO($config['dsn'], $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function ipmdb_clean(string $value, int $max = 1000): string
{
    $value = trim($value);
    $value = strip_tags($value);
    return mb_substr($value, 0, $max);
}
