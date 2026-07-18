<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
$localFile = dirname(__DIR__) . '/config.local.php';
$config = is_file($localFile) ? require $localFile : require $configFile;
$dad = is_array($config['dad'] ?? null) ? $config['dad'] : [];
$dadEmail = trim((string)($dad['public_email'] ?? 'dad@ipmdb.ai')) ?: 'dad@ipmdb.ai';
$squareUrl = trim((string)($dad['square_url'] ?? ''));
$assetId = trim((string)($_GET['asset_id'] ?? ''));

function dad_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DAD | Dollar A Day | End Homelessness</title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#04150a;color:#fff}.wrap{max-width:820px;margin:auto;background:linear-gradient(160deg,#0a7a2b,#06451b 64%,#04220e);text-align:center;padding:28px 22px;min-height:100svh}.nav{display:flex;justify-content:center;gap:9px;flex-wrap:wrap}.nav a,.btn{display:inline-flex;align-items:center;justify-content:center;color:inherit;text-decoration:none;font-weight:1000;border-radius:999px}.nav a{padding:10px 14px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.14)}.dad-logo{display:block;width:min(470px,86vw);aspect-ratio:1;object-fit:contain;margin:34px auto 12px;border-radius:50%;background:#000;box-shadow:0 28px 70px rgba(0,0,0,.38)}h1{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}h2{font-size:clamp(30px,7vw,48px);margin:0 0 18px}.mission{font-size:clamp(24px,6vw,38px);margin:18px 0 36px;font-weight:1000;line-height:1.2;color:#fde68a}.btn{background:white;color:#075b22;font-size:clamp(22px,5vw,32px);padding:19px 26px;margin:12px auto;max-width:620px;width:100%;border:0}.secondary{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.30)}.note{font-size:17px;line-height:1.55;margin:22px auto;max-width:650px;color:#dcfce7}.asset{border:1px solid rgba(253,230,138,.45);background:rgba(0,0,0,.18);border-radius:18px;padding:14px;margin:18px auto;max-width:620px;color:#fef3c7}.footer{margin:32px -22px -28px;background:#021006;padding:22px;color:#bbf7d0;font-weight:900}.small{font-size:14px;color:#bbf7d0;margin-top:14px}
</style>
</head>
<body>
<main class="wrap">
  <nav class="nav" aria-label="DAD navigation">
    <a href="/ipmdb/">IPMdb.ai</a>
    <a href="/ipmdb/ecosystem.php">System Map</a>
    <a href="/ipmdb/relationship_explorer.php">Graph</a>
    <a href="/ipmdb/ledger.php">Ledger</a>
  </nav>
  <img class="dad-logo" src="/ipmdb/assets/brand/dad-official.jpeg" alt="Official DAD Dollar A Day logo">
  <h1>DAD</h1>
  <h2>Dollar A Day</h2>
  <p class="mission">$1/DAY = ONE GOAL<br>END HOMELESSNESS</p>
  <p class="note">DAD is IPMdb.ai's Priority 1 implementation. Contributions, decisions, implementation work, and measurable outcomes connect to the public asset graph.</p>
  <?php if ($assetId !== ''): ?><div class="asset">Implementing asset <strong><?= dad_h($assetId) ?></strong></div><?php endif; ?>
  <?php if ($squareUrl !== ''): ?><a class="btn" href="<?= dad_h($squareUrl) ?>" rel="noopener">Contribute</a><?php endif; ?>
  <button class="btn secondary" type="button" id="copyDad">DAD Contact</button>
  <a class="btn secondary" href="/ipmdb/search.php?q=DAD">View DAD Assets</a>
  <p class="small">Public identity: <?= dad_h($dadEmail) ?></p>
  <footer class="footer">DAD · Part of the IPMdb.ai ecosystem · Ideas 2 Assets</footer>
</main>
<script>
document.getElementById('copyDad').addEventListener('click',function(){
  navigator.clipboard.writeText(<?= json_encode($dadEmail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>).then(function(){alert('DAD contact copied');});
});
</script>
</body>
</html>
