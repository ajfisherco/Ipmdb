<?php
declare(strict_types=1);

/**
 * Send the submitter's receipt and an optional operational copy.
 *
 * The operational copy is deliberately sent separately so the submitter's
 * address is never exposed through CC/BCC handling and delivery can be
 * audited independently.
 */
function ipmdb_send_acknowledgement(array $asset): bool
{
    $submitter = trim((string)($asset['email'] ?? ''));

    if (!filter_var($submitter, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $configPath = is_file(__DIR__ . '/config.local.php')
        ? __DIR__ . '/config.local.php'
        : __DIR__ . '/config.php';
    $config = require $configPath;
    $mailConfig = is_array($config['mail'] ?? null) ? $config['mail'] : [];
    $admin = trim((string)($mailConfig['to'] ?? ''));
    $from = trim((string)($mailConfig['from'] ?? 'no-reply@ipmdb.ai'));
    $replyTo = trim((string)($mailConfig['reply_to'] ?? $from));

    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'no-reply@ipmdb.ai';
    }

    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $from;
    }
    $assetId = trim((string)($asset['asset_id'] ?? ''));
    $title = trim((string)($asset['title'] ?? ''));
    $idea = (string)($asset['idea'] ?? '');
    $createdAt = trim((string)($asset['created_at'] ?? date('Y-m-d H:i:s')));

    $requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'ipmdb.ai')));
    $requestHost = (string)preg_replace('/:\d+$/', '', $requestHost);
    $allowedHosts = [
        'ipmdb.ai',
        'www.ipmdb.ai',
        'ajfisherco.com',
        'www.ajfisherco.com',
    ];
    $host = in_array($requestHost, $allowedHosts, true) ? $requestHost : 'ipmdb.ai';
    $assetUrl = 'https://' . $host . '/ipmdb/asset.php?id=' . rawurlencode($assetId);

    $safeAssetId = htmlspecialchars($assetId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeIdea = nl2br(htmlspecialchars($idea, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $safeCreatedAt = htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeSubmitter = htmlspecialchars($submitter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeAssetUrl = htmlspecialchars($assetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $message = '<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#020617;color:#f8fafc;font-family:Arial,sans-serif;">
  <div style="max-width:680px;margin:auto;background:#0f172a;padding:28px;border-radius:18px;">
    <h1>IPMdb Asset Locked</h1>
    <p>Your idea has entered the IPMdb ledger.</p>

    <h2 style="color:#60a5fa;">' . $safeAssetId . '</h2>

    <p><strong>Title:</strong> ' . $safeTitle . '</p>
    <p><strong>Timestamp:</strong> ' . $safeCreatedAt . '</p>
    <p><strong>Submitted by:</strong> ' . $safeSubmitter . '</p>

    <p><strong>Description:</strong></p>
    <div style="background:#020617;padding:16px;border-radius:12px;">' . $safeIdea . '</div>

    <p style="margin-top:24px;">
      <a href="' . $safeAssetUrl . '" style="background:#3f8cff;color:#fff;padding:14px 20px;border-radius:999px;text-decoration:none;font-weight:bold;">View Asset</a>
    </p>

    <p style="color:#94a3b8;font-size:13px;margin-top:28px;">IPMdb.ai · Ideas 2 Assets</p>
  </div>
</body>
</html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: IPMdb <' . $from . '>',
        'Reply-To: ' . $replyTo,
        'X-IPMdb-Asset-ID: ' . str_replace(["\r", "\n"], '', $assetId),
    ];

    $headerText = implode("\r\n", $headers);
    $subject = 'IPMdb Asset Locked: ' . str_replace(["\r", "\n"], '', $assetId);
    $submitterSent = @mail($submitter, $subject, $message, $headerText);

    $adminSent = true;
    if (filter_var($admin, FILTER_VALIDATE_EMAIL) && strcasecmp($submitter, $admin) !== 0) {
        $adminSubject = 'IPMdb Lock Notice: ' . str_replace(["\r", "\n"], '', $assetId);
        $adminSent = @mail($admin, $adminSubject, $message, $headerText);
    }

    return $submitterSent && $adminSent;
}
