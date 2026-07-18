<?php
declare(strict_types=1);

function h(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$assetId = trim((string)($_GET['asset_id'] ?? $_GET['asset'] ?? $_GET['id'] ?? ''));$mail = trim((string)($_GET['mail'] ?? ''));

if ($assetId === '') {
  $assetId = 'PENDING';
}

$mailMessage = 'Acknowledgement email status unknown.';

if ($mail === 'sent') {
  $mailMessage = 'Acknowledgement email sent.';
}

if ($mail === 'failed') {
  $mailMessage = 'Idea locked. Acknowledgement email needs review.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Idea Locked | IPMdb</title>
  <style>
    body {
      margin: 0;
      min-height: 100svh;
      background:
        radial-gradient(circle at top left, rgba(96,165,250,.28), transparent 38%),
        radial-gradient(circle at bottom right, rgba(134,239,172,.20), transparent 34%),
        #020617;
      color: #e5f2ff;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
    }

    main {
      width: min(900px, 94vw);
      min-height: 100svh;
      margin: 0 auto;
      padding: 28px 0 96px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .card {
      width: 100%;
      border: 1px solid rgba(148, 163, 184, .25);
      border-radius: 30px;
      background: rgba(15, 23, 42, .78);
      box-shadow: 0 24px 90px rgba(0,0,0,.42);
      overflow: hidden;
    }

    .hero {
      padding: clamp(28px, 6vw, 62px);
      text-align: center;
      border-bottom: 1px solid rgba(148, 163, 184, .25);
    }

    .brand {
      font-size: clamp(38px, 8vw, 84px);
      font-weight: 1000;
      letter-spacing: -.06em;
      line-height: .9;
      margin-bottom: 18px;
    }

    .brand span {
      color: #86efac;
    }

    h1 {
      margin: 0;
      font-size: clamp(42px, 10vw, 104px);
      line-height: .9;
      letter-spacing: -.06em;
    }

    .asset-id {
      margin-top: 22px;
      color: #86efac;
      font-size: clamp(22px, 5vw, 44px);
      font-weight: 1000;
      letter-spacing: .02em;
      word-break: break-word;
    }

    .message {
      margin-top: 16px;
      color: #9fb4ca;
      font-size: clamp(18px, 3vw, 26px);
      font-weight: 800;
      line-height: 1.35;
    }

.actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));      gap: 12px;
      padding: clamp(18px, 4vw, 34px);
      background: rgba(2, 6, 23, .56);
    }

    a {
      color: white;
      text-decoration: none;
    }

    .btn {
      display: block;
      text-align: center;
      border-radius: 999px;
      padding: 16px 18px;
      background: #2563eb;
      font-size: 16px;
      font-weight: 1000;
      letter-spacing: .04em;
      text-transform: uppercase;
    }

    .btn.green {
      background: #15803d;
    }

    .btn.alt {
      background: rgba(255,255,255,.10);
      border: 1px solid rgba(255,255,255,.20);
    }

    footer {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      padding: 14px 22px;
      background: rgba(2, 6, 23, .88);
      border-top: 1px solid rgba(148, 163, 184, .24);
      display: flex;
      justify-content: space-between;
      color: #9fb4ca;
      font-size: 13px;
      font-weight: 900;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    @media (max-width: 760px) {
      .actions {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main>
    <section class="card">
      <div class="hero">
        <div class="brand">IPM<span>db</span></div>
        <h1>Idea Locked</h1>

        <div class="asset-id">
          <?= h($assetId) ?>
        </div>

        <div class="message">
          <?= h($mailMessage) ?>
        </div>
      </div>

<div class="actions">

  <a class="btn green" href="/ipmdb/asset.php?asset_id=<?= urlencode($assetId) ?>">
    Continue
  </a>

  <a class="btn" href="/ipmdb/provenance.php?asset_id=<?= urlencode($assetId) ?>">
    Provenance Receipt
  </a>

  <a class="btn" href="/ipmdb/ledger.php">
    Asset Ledger
  </a>

  <a class="btn" href="/ipmdb/relationship_explorer.php?asset_id=<?= urlencode($assetId) ?>">
    Relationship Graph
  </a>

  <a class="btn alt" href="/ipmdb/">
    Lock Another
  </a>

</div>
<footer>
  <span>IPMdb.ai</span>
  <span>Ideas 2 Assets</span>
</footer>
	</body>
</html>
