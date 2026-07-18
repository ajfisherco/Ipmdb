<?php
declare(strict_types=1);

$nodes = [
    ['name' => 'Housing', 'purpose' => 'Placement, stability, dignity, and measurable housing outcomes.'],
    ['name' => 'COPO', 'purpose' => 'Court of Public Opinion: claims, evidence, replies, corrections, and outcomes.'],
    ['name' => 'Governance', 'purpose' => 'Consent, accountability, transparent decisions, and continuity.'],
    ['name' => 'Transportation / TDM', 'purpose' => 'Mobility, access, coordination, and traffic-demand management.'],
    ['name' => 'PCWM', 'purpose' => 'Post-consumer waste management and circular resource systems.'],
    ['name' => 'Public Service', 'purpose' => 'Accessible public systems connected to accountable delivery.'],
    ['name' => 'Economic Security', 'purpose' => 'Cooperative value creation, participation, and durable community benefit.'],
];

$methods = ['Make', 'Measure', 'Map', 'Model', 'Memorize', 'Merge', 'Mature'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="The IPMdb.ai ecosystem: DAD, DADS, Sandola, seven action nodes, the Seven Ms, The Mill, GPT-5.6 relationship intelligence, and public provenance.">
<title>IPMdb.ai System Map</title>
<style>
*{box-sizing:border-box}
:root{--bg:#020617;--line:rgba(148,163,184,.28);--ink:#eef6ff;--muted:#a9bdd1;--blue:#60a5fa;--green:#4ade80;--gold:#fbbf24;--orange:#fb923c}
html,body{margin:0;min-height:100%;background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
body{background:radial-gradient(circle at 16% 8%,rgba(37,99,235,.34),transparent 30rem),radial-gradient(circle at 85% 12%,rgba(34,197,94,.20),transparent 26rem),linear-gradient(145deg,#020617,#071526 54%,#03120b)}
a{color:inherit;text-decoration:none}
.wrap{width:min(1180px,94vw);margin:auto;padding:24px 0 58px}
.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}
.brand{display:inline-flex;align-items:center;gap:12px;font-size:clamp(25px,4vw,42px);font-weight:1000;letter-spacing:-.055em;color:var(--blue)}
.brand img{width:72px;height:72px;border-radius:50%;object-fit:cover;box-shadow:0 0 30px rgba(59,130,246,.30)}
.nav{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:9px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:11px 15px;border:1px solid var(--line);border-radius:999px;background:rgba(15,23,42,.72);font-weight:900}
.btn.dad{background:var(--green);color:#052e16;border:0}
.hero,.card{border:1px solid var(--line);border-radius:28px;background:rgba(15,23,42,.82);box-shadow:0 24px 80px rgba(0,0,0,.28)}
.hero{padding:clamp(24px,5vw,52px);margin-bottom:20px}
.eyebrow,.kicker{text-transform:uppercase;letter-spacing:.18em;font-weight:1000;font-size:12px;color:var(--green)}
h1{font-size:clamp(46px,9vw,96px);line-height:.88;letter-spacing:-.075em;margin:12px 0 20px}
.lede{max-width:850px;color:#d8e8f8;font-size:clamp(19px,3vw,28px);line-height:1.35;margin:0}
.flow{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:20px 0}
.card{padding:20px}
.card h2{font-size:clamp(24px,4vw,38px);line-height:1;margin:8px 0 12px;letter-spacing:-.04em}
.card p{color:var(--muted);line-height:1.5;margin:0}
.official-logo{display:block;width:100%;height:190px;object-fit:contain;border-radius:18px;background:#000;margin:0 0 18px}
.platform{border-color:rgba(96,165,250,.55)}
.dad-card{border-color:rgba(74,222,128,.58);background:linear-gradient(145deg,rgba(20,83,45,.84),rgba(15,23,42,.9))}
.sandola{border-color:rgba(251,191,36,.58);background:linear-gradient(145deg,rgba(92,55,8,.55),rgba(15,23,42,.9))}
.priority{display:inline-flex;margin-top:15px;padding:7px 10px;border-radius:999px;background:var(--green);color:#052e16;font-size:12px;font-weight:1000;letter-spacing:.08em}
.section{margin-top:32px}
.section h2{font-size:clamp(32px,6vw,58px);margin:0 0 8px;letter-spacing:-.055em}
.section>p{color:var(--muted);font-size:18px;margin:0 0 18px}
.nodes{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.node{min-height:170px}
.node strong{display:block;font-size:24px;margin-bottom:10px;color:#fff}
.node:nth-child(2){border-color:rgba(251,146,60,.60)}
.methods{display:grid;grid-template-columns:repeat(7,1fr);gap:9px;margin-top:16px}
.method{padding:16px 8px;border:1px solid var(--line);border-radius:16px;text-align:center;background:rgba(96,165,250,.10);font-weight:1000}
.proof{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:20px}
.footer{margin-top:32px;border-top:1px solid var(--line);padding-top:18px;color:var(--muted);display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
@media(max-width:900px){.flow{grid-template-columns:1fr 1fr}.nodes{grid-template-columns:1fr 1fr}.methods{grid-template-columns:repeat(4,1fr)}}
@media(max-width:620px){.top{align-items:flex-start;display:block}.nav{justify-content:flex-start;margin-top:14px}.flow,.nodes,.proof{grid-template-columns:1fr}.methods{grid-template-columns:1fr 1fr}.node{min-height:auto}}
</style>
</head>
<body>
<main class="wrap">
  <header class="top">
    <a class="brand" href="/ipmdb/"><img src="/ipmdb/assets/brand/ipmdb-i2a-official.jpeg" alt="Official IPMdb.ai I2A logo"><span>IPMdb.ai</span></a>
    <nav class="nav" aria-label="Primary navigation">
      <a class="btn" href="/ipmdb/">Lock Idea</a>
      <a class="btn" href="/ipmdb/relationship_explorer.php">Graph</a>
      <a class="btn" href="/ipmdb/ledger.php">Ledger</a>
      <a class="btn dad" href="/ipmdb/dad/">DAD</a>
    </nav>
  </header>

  <section class="hero">
    <div class="eyebrow">Ideas → Align → Assets → Action</div>
    <h1>One living system.</h1>
    <p class="lede">IPMdb.ai records the idea. The relationship engine aligns it. The Mill connects it to action. DAD is the Priority 1 implementation. Sandola preserves contribution and evidence. Public provenance keeps the trail verifiable.</p>
  </section>

  <section class="flow" aria-label="Core ecosystem">
    <article class="card platform"><img class="official-logo" src="/ipmdb/assets/brand/ipmdb-i2a-official.jpeg" alt="Official IPMdb.ai I2A logo"><div class="kicker">Platform</div><h2>IPMdb.ai · I2A</h2><p>Turns ideas into stable, versioned, attributable assets with relationships and public provenance.</p></article>
    <article class="card platform"><div class="kicker">Operating system</div><h2>Relationship Explorer</h2><p>Maps dependencies, evidence, implementation, decisions, funding, and outcomes across the graph.</p></article>
    <article class="card"><div class="kicker">Integrator</div><h2>The Mill</h2><p>Brings ideas, people, nodes, evidence, resources, and implementation pathways into one working flow.</p></article>
    <article class="card dad-card"><img class="official-logo" src="/ipmdb/assets/brand/dad-official.jpeg" alt="Official DAD Dollar A Day logo"><div class="kicker">Implementation</div><h2>DAD · Dollar a Day</h2><p>$1/day. One goal. End homelessness. Every decision, contribution, deployment, and outcome can be connected to the IPMdb ledger.</p><span class="priority">PRIORITY 1</span></article>
    <article class="card dad-card"><div class="kicker">Stewardship</div><h2>DADS</h2><p>Dollar A Day Society provides governance, administration, financial oversight, and public accountability.</p></article>
    <article class="card sandola"><img class="official-logo" src="/ipmdb/assets/brand/sandola-official.png" alt="Official Sandola logo"><div class="kicker">Ledger / archive</div><h2>Sandola</h2><p>Records contribution, evidence, implementation, attribution, and transparent reserves. Sandola is not a speculative currency.</p></article>
  </section>

  <section class="section">
    <h2>Seven action nodes</h2>
    <p>Every asset can belong to one or more public-interest domains while remaining connected to the whole graph.</p>
    <div class="nodes">
      <?php foreach ($nodes as $node): ?>
        <a class="card node" href="/ipmdb/search.php?q=<?= rawurlencode($node['name']) ?>">
          <strong><?= htmlspecialchars($node['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
          <p><?= htmlspecialchars($node['purpose'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="section card">
    <div class="kicker">Operating loop</div>
    <h2>The Seven Ms</h2>
    <p>Work advances through a repeatable loop that preserves what was learned and strengthens what comes next.</p>
    <div class="methods">
      <?php foreach ($methods as $method): ?><div class="method"><?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  </section>

  <section class="proof">
    <article class="card"><div class="kicker">Relationship intelligence</div><h2>GPT‑5.6 + human approval</h2><p>GPT‑5.6 proposes bounded, typed relationships across the ecosystem. A human approves every write.</p></article>
    <article class="card"><div class="kicker">Verification</div><h2>Public provenance</h2><p>SHA‑256 receipts preserve the content, versions, and surrounding graph context for independent verification.</p></article>
  </section>

  <footer class="footer"><strong>IPMdb.ai · Ideas 2 Assets</strong><span>Public-domain infrastructure for traceable action.</span></footer>
</main>
</body>
</html>
