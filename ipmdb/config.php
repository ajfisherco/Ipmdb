<?php
declare(strict_types=1);

/*
 * Public configuration template.
 *
 * Production credentials belong in config.local.php or environment variables.
 * config.local.php is intentionally excluded from version control.
 */

return [
    'app' => [
        'environment' => getenv('IPMDB_ENV') ?: 'production',
        'base_path' => getenv('IPMDB_BASE_PATH') ?: '/ipmdb',
        'public_email' => filter_var(
            getenv('IPMDB_PUBLIC_EMAIL') ?: 'false',
            FILTER_VALIDATE_BOOL
        ),
    ],
    'db' => [
        'dsn' => getenv('IPMDB_DB_DSN') ?: '',
        'user' => getenv('IPMDB_DB_USER') ?: '',
        'pass' => getenv('IPMDB_DB_PASS') ?: '',
    ],
    'auth' => [
        'email' => getenv('IPMDB_ADMIN_EMAIL') ?: '',
        'password_hash' => getenv('IPMDB_ADMIN_PASSWORD_HASH') ?: '',
        'session_minutes' => max(15, (int)(getenv('IPMDB_SESSION_MINUTES') ?: 60)),
    ],
    'openai' => [
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model' => getenv('IPMDB_OPENAI_MODEL') ?: 'gpt-5.6',
        'timeout_seconds' => max(10, (int)(getenv('IPMDB_OPENAI_TIMEOUT') ?: 45)),
    ],
    'mail' => [
        'from' => getenv('IPMDB_MAIL_FROM') ?: 'no-reply@ipmdb.ai',
        'reply_to' => getenv('IPMDB_MAIL_REPLY_TO') ?: '',
        'to' => getenv('IPMDB_MAIL_TO') ?: '',
    ],
];
