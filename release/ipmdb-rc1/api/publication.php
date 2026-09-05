<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/language.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    ipmdb_json(['ok' => false, 'error' => 'GET required.'], 405);
}

$assetId = ipmdb_clean((string)($_GET['asset_id'] ?? ''), 32);
if ($assetId === '') {
    ipmdb_json(['ok' => false, 'error' => 'Asset ID required.'], 400);
}

$pdo = ipmdb_pdo();
$doerEmail = isset($_SESSION['doer_email']) ? (string)$_SESSION['doer_email'] : null;
$preference = ipmdb_doer_language(
    $pdo,
    $doerEmail,
    (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')
);
$publication = ipmdb_publication_for($pdo, $assetId, $preference);

header('Vary: Accept-Language, Cookie');

if (!$publication) {
    ipmdb_json(['ok' => false, 'error' => 'No published version found.'], 404);
}

ipmdb_json([
    'ok' => true,
    'requested_language' => $preference['language'],
    'language_source' => $preference['source'],
    'served_language' => $publication['language_tag'],
    'fallback_used' => $publication['language_tag'] !== $preference['language'],
    'publication' => [
        'id' => (int)$publication['id'],
        'asset_id' => $publication['asset_id'],
        'version' => $publication['version'],
        'source_publication_id' => $publication['source_publication_id'] === null
            ? null
            : (int)$publication['source_publication_id'],
        'source_language' => $publication['source_language_tag'],
        'language' => $publication['language_tag'],
        'title' => $publication['title'],
        'body' => $publication['body'],
        'method' => $publication['translation_method'],
        'review_status' => $publication['review_status'],
        'published_at' => $publication['published_at'],
    ],
]);
