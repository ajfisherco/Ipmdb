<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';

ipmdb_start_session();

function ipmdb_auth_config(): array
{
    static $auth = null;

    if (is_array($auth)) {
        return $auth;
    }

    $path = is_file(__DIR__ . '/config.local.php')
        ? __DIR__ . '/config.local.php'
        : __DIR__ . '/config.php';

    $config = require $path;
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];

    return $auth;
}

function ipmdb_logged_in(): bool
{
    $admin = $_SESSION['ipmdb_admin'] ?? null;

    if (!is_array($admin) || empty($admin['email']) || empty($admin['last_seen'])) {
        return false;
    }

    $minutes = max(15, (int)(ipmdb_auth_config()['session_minutes'] ?? 60));

    if ((int)$admin['last_seen'] < time() - ($minutes * 60)) {
        ipmdb_logout();
        return false;
    }

    $_SESSION['ipmdb_admin']['last_seen'] = time();
    return true;
}

function ipmdb_require_login(): void
{
    if (ipmdb_logged_in()) {
        return;
    }

    header('Location: /ipmdb/login.php');
    exit;
}

function ipmdb_login_is_rate_limited(): bool
{
    $attempts = $_SESSION['ipmdb_login_attempts'] ?? [];
    $cutoff = time() - 900;
    $attempts = array_values(array_filter(
        is_array($attempts) ? $attempts : [],
        static fn($timestamp): bool => (int)$timestamp >= $cutoff
    ));

    $_SESSION['ipmdb_login_attempts'] = $attempts;
    return count($attempts) >= 5;
}

function ipmdb_login(string $email, string $password): bool
{
    if (ipmdb_login_is_rate_limited()) {
        return false;
    }

    $auth = ipmdb_auth_config();
    $expectedEmail = trim((string)($auth['email'] ?? ''));
    $passwordHash = trim((string)($auth['password_hash'] ?? ''));

    $valid = $expectedEmail !== ''
        && $passwordHash !== ''
        && strcasecmp(trim($email), $expectedEmail) === 0
        && password_verify($password, $passwordHash);

    if (!$valid) {
        $_SESSION['ipmdb_login_attempts'][] = time();
        return false;
    }

    session_regenerate_id(true);
    unset($_SESSION['ipmdb_login_attempts']);

    $_SESSION['ipmdb_admin'] = [
        'email' => $expectedEmail,
        'login_time' => time(),
        'last_seen' => time(),
    ];

    return true;
}

function ipmdb_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string)$params['path'],
            'domain' => (string)$params['domain'],
            'secure' => (bool)$params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}
