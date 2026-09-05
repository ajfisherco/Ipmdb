<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/language.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$doerEmail = (string)($_SESSION['doer_email'] ?? '');
if ($doerEmail === '') {
    ipmdb_json(['ok' => false, 'error' => 'Doer sign-in required.'], 401);
}

$pdo = ipmdb_pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $preference = ipmdb_doer_language(
        $pdo,
        $doerEmail,
        (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
    );
    ipmdb_json(['ok' => true, 'preference' => $preference]);
}

if ($method !== 'POST') {
    ipmdb_json(['ok' => false, 'error' => 'GET or POST required.'], 405);
}

$data = ipmdb_request_json();
$language = ipmdb_normalize_language_tag((string)($data['language'] ?? ''));
$fallback = ipmdb_normalize_language_tag((string)($data['fallback'] ?? 'en'));
$autoPublish = array_key_exists('auto_publish', $data) ? (bool)$data['auto_publish'] : true;

if ($language === '' || $fallback === '') {
    ipmdb_json(['ok' => false, 'error' => 'Valid BCP 47 language tags required.'], 400);
}

$pdo->beginTransaction();
$save = $pdo->prepare(
    'INSERT INTO doer_language_preferences
       (doer_email, language_tag, fallback_language_tag, auto_publish)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       language_tag = VALUES(language_tag),
       fallback_language_tag = VALUES(fallback_language_tag),
       auto_publish = VALUES(auto_publish)'
);
$save->execute([$doerEmail, $language, $fallback, $autoPublish ? 1 : 0]);

$ledger = $pdo->prepare(
    'INSERT INTO ledger (asset_id, event_type, event_payload) VALUES (?, ?, ?)'
);
$ledger->execute([
    'DOER-' . substr(hash('sha256', strtolower($doerEmail)), 0, 27),
    'doer.language.changed',
    json_encode([
        'language' => $language,
        'fallback' => $fallback,
        'auto_publish' => $autoPublish,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);
$pdo->commit();

ipmdb_json([
    'ok' => true,
    'preference' => [
        'language' => $language,
        'fallback' => $fallback,
        'auto_publish' => $autoPublish,
        'source' => 'doer',
    ],
]);
