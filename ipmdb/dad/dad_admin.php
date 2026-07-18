<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
ipmdb_require_login();

/*
|--------------------------------------------------------------------------
| /httpdocs/dad/dad_admin.php
|--------------------------------------------------------------------------
| DAD Admin Launch Panel
| Dollar a Day
|--------------------------------------------------------------------------
*/

$schemaFile = __DIR__ . '/includes/dad_schema.php';

$schema = [];

if (is_file($schemaFile)) {
    require_once $schemaFile;

    if (function_exists('dad_schema')) {
        $schema = dad_schema();
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$configPath = is_file(dirname(__DIR__) . '/config.local.php')
    ? dirname(__DIR__) . '/config.local.php'
    : dirname(__DIR__) . '/config.php';
$config = require $configPath;
$dadConfig = is_array($config['dad'] ?? null) ? $config['dad'] : [];
$dadEmail = trim((string)($dadConfig['public_email'] ?? 'dad@ipmdb.ai')) ?: 'dad@ipmdb.ai';
$squareLink = trim((string)($dadConfig['square_url'] ?? ''));

$checks = [
    'DAD landing page' => '/ipmdb/dad/',
    'Square contribution link' => $squareLink,
    'IPMdb public ledger' => '/ipmdb/',
    'IPMdb viewer' => '/ipmdb/viewer.php',
    'IPMdb ledger' => '/ipmdb/ledger.php',
    'IPMdb search' => '/ipmdb/search.php',
    'Relationship explorer' => '/ipmdb/relationship_explorer.php',
];

$schemaLoaded = $schema !== [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DAD Admin | Dollar a Day</title>

<style>
*{box-sizing:border-box}

body{
    margin:0;
    min-height:100svh;
    font-family:Arial,sans-serif;
    background:#07120a;
    color:#eefbf1;
}

.wrap{
    width:min(1180px,94vw);
    margin:0 auto;
    padding:28px 0 52px;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
    margin-bottom:20px;
}

.brand h1{
    margin:0;
    font-size:clamp(42px,8vw,92px);
    line-height:.86;
    letter-spacing:-.06em;
    color:#86efac;
}

.brand p{
    margin:10px 0 0;
    color:#b7f7ca;
    font-weight:900;
    letter-spacing:.16em;
    text-transform:uppercase;
}

.nav{
    display:flex;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:10px;
}

a,.btn{
    color:inherit;
}

.nav a,
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:42px;
    padding:10px 14px;
    border-radius:999px;
    border:1px solid rgba(134,239,172,.32);
    background:rgba(134,239,172,.12);
    text-decoration:none;
    font-weight:900;
}

.btn.primary{
    background:#86efac;
    color:#07120a;
    border:0;
}

.grid{
    display:grid;
    grid-template-columns:repeat(12,1fr);
    gap:16px;
}

.card{
    grid-column:span 12;
    border:1px solid rgba(134,239,172,.22);
    background:rgba(2,20,9,.72);
    border-radius:24px;
    padding:20px;
    box-shadow:0 22px 70px rgba(0,0,0,.28);
}

.half{grid-column:span 6}
.third{grid-column:span 4}

.kicker{
    color:#86efac;
    text-transform:uppercase;
    letter-spacing:.16em;
    font-size:12px;
    font-weight:900;
    margin-bottom:8px;
}

h2{
    margin:0 0 14px;
    font-size:clamp(24px,4vw,38px);
    letter-spacing:-.04em;
}

.big{
    font-size:clamp(30px,6vw,64px);
    font-weight:1000;
    letter-spacing:-.06em;
    margin:0;
}

.muted{
    color:#b7cbbb;
    line-height:1.5;
}

.status{
    display:inline-flex;
    border-radius:999px;
    padding:8px 11px;
    background:rgba(134,239,172,.15);
    border:1px solid rgba(134,239,172,.32);
    color:#bbf7d0;
    font-weight:900;
}

.warn{
    display:inline-flex;
    border-radius:999px;
    padding:8px 11px;
    background:rgba(250,204,21,.12);
    border:1px solid rgba(250,204,21,.34);
    color:#fde68a;
    font-weight:900;
}

.actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:14px;
}

.table{
    width:100%;
    border-collapse:collapse;
    overflow:hidden;
    border-radius:18px;
}

.table th,
.table td{
    text-align:left;
    padding:12px;
    border-bottom:1px solid rgba(134,239,172,.14);
    vertical-align:top;
}

.table th{
    color:#86efac;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.12em;
}

.code{
    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
    color:#d9f99d;
    word-break:break-all;
}

.copybox{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
    padding:14px;
    border-radius:18px;
    background:rgba(0,0,0,.22);
    border:1px solid rgba(134,239,172,.18);
}

.copybox strong{
    font-size:20px;
}

.footer{
    margin-top:24px;
    display:flex;
    justify-content:space-between;
    gap:14px;
    color:#b7cbbb;
    border-top:1px solid rgba(134,239,172,.18);
    padding-top:18px;
}

@media(max-width:860px){
    .top{display:block}
    .nav{justify-content:flex-start;margin-top:16px}
    .half,.third{grid-column:span 12}
}
</style>
</head>

<body>
<div class="wrap">

<header class="top">
    <div class="brand">
        <h1>DAD</h1>
        <p>Dollar a Day Admin</p>
    </div>

    <nav class="nav">
        <a href="/ipmdb/dad/">DAD Page</a>
        <a href="/ipmdb/ecosystem.php">System Map</a>
        <a href="/ipmdb/">IPMdb</a>
        <a href="/ipmdb/ledger.php">Ledger</a>
        <a href="/ipmdb/search.php">Search</a>
        <a href="/ipmdb/relationship_explorer.php">Explorer</a>
    </nav>
</header>

<section class="grid">

    <article class="card">
        <div class="kicker">Launch Status</div>
        <h2>DAD v1.0 Operations Panel</h2>
        <p class="muted">
            This panel tracks the live DAD launch surface, payment path, public ledger linkage, and current schema status.
        </p>

        <div class="actions">
            <span class="status">Landing page live</span>
            <span class="status">Square active</span>
            <span class="status">IPMdb linked</span>
            <?php if ($schemaLoaded): ?>
                <span class="status">Schema loaded</span>
            <?php else: ?>
                <span class="warn">Schema missing</span>
            <?php endif; ?>
        </div>
    </article>

    <article class="card half">
        <div class="kicker">Contribution Path</div>
        <h2>Payment Links</h2>

        <div class="copybox">
            <strong>Square</strong>
            <span class="code"><?= h($squareLink) ?></span>
        </div>

        <div class="actions">
            <a class="btn primary" href="<?= h($squareLink) ?>">Open Square</a>
            <a class="btn" href="/ipmdb/dad/">Open DAD</a>
        </div>
    </article>

    <article class="card half">
        <div class="kicker">E-transfer</div>
        <h2>DAD Contact</h2>

        <div class="copybox">
            <strong><?= h($dadEmail) ?></strong>
            <button class="btn" type="button" onclick="copyDadEmail()">Copy</button>
        </div>

        <p class="muted">
            Public DAD identity configured for this environment.
        </p>
    </article>

    <article class="card third">
        <div class="kicker">Today</div>
        <p class="big">v1.0</p>
        <p class="muted">Operational launch candidate.</p>
    </article>

    <article class="card third">
        <div class="kicker">Payment</div>
        <p class="big">CAD</p>
        <p class="muted">Square contribution page active.</p>
    </article>

    <article class="card third">
        <div class="kicker">Ledger</div>
        <p class="big">IPMdb</p>
        <p class="muted">Public record system connected.</p>
    </article>

    <article class="card">
        <div class="kicker">Launch Checks</div>
        <h2>Live Path Tests</h2>

        <table class="table">
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Link</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $label => $url): ?>
                <tr>
                    <td><?= h($label) ?></td>
                    <td><a class="code" href="<?= h($url) ?>"><?= h($url) ?></a></td>
                    <td><span class="status">Manual test</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </article>

    <article class="card">
        <div class="kicker">Schema</div>
        <h2>DAD Schema Status</h2>

        <?php if (!$schemaLoaded): ?>
            <p class="muted">
                Schema file was not loaded. Expected:
                <span class="code">/httpdocs/ipmdb/dad/includes/dad_schema.php</span>
            </p>
        <?php else: ?>
            <table class="table">
                <tbody>
                    <tr>
                        <th>Version</th>
                        <td><?= h((string)($schema['version'] ?? 'unknown')) ?></td>
                    </tr>
                    <tr>
                        <th>Table</th>
                        <td><?= h((string)($schema['table'] ?? 'unknown')) ?></td>
                    </tr>
                    <tr>
                        <th>Primary Key</th>
                        <td><?= h((string)($schema['primary_key'] ?? 'unknown')) ?></td>
                    </tr>
                    <tr>
                        <th>Fields</th>
                        <td><?= h((string)count($schema['fields'] ?? [])) ?></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <?php if ($schemaLoaded && !empty($schema['fields']) && is_array($schema['fields'])): ?>
    <article class="card">
        <div class="kicker">Fields</div>
        <h2>Contribution Fields</h2>

        <table class="table">
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Type</th>
                    <th>Default</th>
                    <th>Required</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schema['fields'] as $field => $meta): ?>
                <tr>
                    <td class="code"><?= h((string)$field) ?></td>
                    <td><?= h((string)($meta['type'] ?? '')) ?></td>
                    <td><?= h((string)($meta['default'] ?? '')) ?></td>
                    <td><?= !empty($meta['required']) ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </article>
    <?php endif; ?>

</section>

<footer class="footer">
    <strong>DAD · Dollar a Day</strong>
    <span>Part of the IPMdb.ai ecosystem</span>
</footer>

</div>

<script>
function copyDadEmail(){
    navigator.clipboard.writeText(<?= json_encode($dadEmail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>).then(function(){
        alert('DAD contact copied');
    });
}
</script>

</body>
</html>
