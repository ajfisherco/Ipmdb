<?php
declare(strict_types=1);

function ipmdb_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function ipmdb_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('IPMDBSESSID');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => ipmdb_is_https(),
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ]);
}

function ipmdb_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
}

function ipmdb_csrf_token(): string
{
    ipmdb_start_session();

    if (!isset($_SESSION['ipmdb_csrf']) || !is_string($_SESSION['ipmdb_csrf'])) {
        $_SESSION['ipmdb_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['ipmdb_csrf'];
}

function ipmdb_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(ipmdb_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

function ipmdb_verify_csrf(?string $token = null): bool
{
    ipmdb_start_session();

    $expected = $_SESSION['ipmdb_csrf'] ?? '';
    $provided = $token ?? (string)($_POST['csrf_token'] ?? '');

    return is_string($expected)
        && $expected !== ''
        && $provided !== ''
        && hash_equals($expected, $provided);
}

function ipmdb_require_csrf(): void
{
    if (ipmdb_verify_csrf()) {
        return;
    }

    http_response_code(403);
    exit('The form expired. Go back, reload the page, and try again.');
}

function ipmdb_public_error(Throwable $error, string $publicMessage): string
{
    error_log($publicMessage . ': ' . $error->getMessage());
    return $publicMessage;
}

ipmdb_security_headers();
