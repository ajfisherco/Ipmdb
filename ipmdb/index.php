<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
ipmdb_start_session();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>IPMdb | Ideas 2 Assets</title>

<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;min-height:100svh}
body{
  background:
    radial-gradient(circle at top left,rgba(96,165,250,.28),transparent 32rem),
    radial-gradient(circle at bottom right,rgba(134,239,172,.20),transparent 30rem),
    linear-gradient(135deg,#020617,#07111f 52%,#020617);
  color:#e5f2ff;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
  overflow-x:hidden;
}
a{color:inherit;text-decoration:none}
.page{
  min-height:100svh;
  width:min(980px,94vw);
  margin:0 auto;
  display:grid;
  grid-template-rows:auto 1fr auto;
  padding:18px 0;
}
.top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.brand strong{
  display:block;
  color:#60a5fa;
  font-size:clamp(34px,8vw,70px);
  line-height:.86;
  letter-spacing:-.07em;
}
.brand span{
  display:block;
  color:#94a3b8;
  font-size:12px;
  letter-spacing:.22em;
  text-transform:uppercase;
  margin-top:4px;
}
.nav{
  display:flex;
  flex-wrap:wrap;
  justify-content:flex-end;
  gap:8px;
}
.btn,button{
  border:1px solid rgba(148,163,184,.30);
  background:rgba(96,165,250,.14);
  color:#e5f2ff;
  border-radius:999px;
  padding:10px 13px;
  font-weight:950;
  font-size:13px;
  min-height:40px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
}
button.primary{
  background:linear-gradient(135deg,#60a5fa,#86efac);
  border:0;
  color:#020617;
  font-size:18px;
  padding:15px 20px;
}
.stage{
  display:grid;
  align-content:center;
  padding:12px 0;
}
.card{
  border:1px solid rgba(148,163,184,.30);
  background:rgba(15,23,42,.88);
  border-radius:28px;
  padding:clamp(18px,4vw,32px);
  box-shadow:0 24px 80px rgba(0,0,0,.32);
}
.logo{
  max-height:104px;
  margin:0 auto 8px;
  display:block;
}
h1{
  margin:0;
  text-align:center;
  color:#e5f2ff;
  font-size:clamp(42px,10vw,92px);
  line-height:.82;
  letter-spacing:-.08em;
}
.sub{
  text-align:center;
  color:#86efac;
  font-size:13px;
  letter-spacing:.24em;
  text-transform:uppercase;
  margin:12px 0 18px;
}
form{display:grid;gap:12px}
.form-trap{position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}
label{
  font-size:12px;
  letter-spacing:.17em;
  text-transform:uppercase;
  color:#94a3b8;
  font-weight:950;
}
input,textarea{
  width:100%;
  border:1px solid rgba(148,163,184,.34);
  background:rgba(2,6,23,.76);
  color:#e5f2ff;
  border-radius:18px;
  padding:14px 16px;
  font-size:18px;
  font-weight:800;
  outline:none;
}
input::placeholder,textarea::placeholder{color:#c7d2fe;opacity:1}
input:focus,textarea:focus,button:focus,a:focus{
  border-color:rgba(96,165,250,.90);
  box-shadow:0 0 0 4px rgba(96,165,250,.18);
  outline:none;
}
textarea{
  min-height:120px;
  max-height:42svh;
  resize:vertical;
  line-height:1.44;
}
textarea.expanded{min-height:260px}
.row{display:grid;gap:7px}
.gauge-top{
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:center;
  color:#94a3b8;
  font-size:13px;
}
.gauge{
  height:9px;
  border-radius:999px;
  overflow:hidden;
  background:rgba(148,163,184,.20);
}
.bar{
  height:100%;
  width:0;
  background:linear-gradient(90deg,#60a5fa,#86efac);
  transition:width .12s linear;
}
.bar.warn{background:linear-gradient(90deg,#facc15,#fb923c)}
.bar.danger{background:linear-gradient(90deg,#fb7185,#ef4444)}
.actions{
  display:flex;
  gap:9px;
  flex-wrap:wrap;
  align-items:center;
  justify-content:center;
  margin-top:4px;
}
.mark{
  margin:12px 0 0;
  font-weight:1000;
  letter-spacing:.08em;
  text-align:center;
  color:#dbeafe;
}
.foot{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  color:#94a3b8;
  font-size:13px;
  padding-top:12px;
}
.foot strong{color:#e5f2ff}
.fullscreen-editor{
  position:fixed;
  inset:0;
  z-index:9999;
  background:#020617;
  color:#e5f2ff;
  padding:14px;
  display:none;
  box-sizing:border-box;
}
.fullscreen-editor.active{
  display:flex;
  flex-direction:column;
  gap:12px;
}
.fullscreen-top{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:center;
}
.fullscreen-title{
  font-size:clamp(22px,5vw,38px);
  font-weight:1000;
}
.fullscreen-editor textarea{
  flex:1;
  width:100%;
  box-sizing:border-box;
  border:2px solid rgba(148,163,184,.48);
  border-radius:20px;
  background:rgba(15,23,42,.90);
  color:#e5f2ff;
  padding:18px;
  font-size:21px;
  font-weight:800;
  line-height:1.46;
  resize:none;
  outline:none;
}
@media(max-width:720px){
  .page{padding:14px 0}
  .top{align-items:flex-start;flex-direction:column}
  .nav{justify-content:flex-start}
  .btn,button{font-size:12px;padding:9px 11px}
  .actions .btn,.actions button{width:100%}
  h1{text-align:left}
  .sub{text-align:left}
  textarea.expanded{min-height:300px}
  .foot{flex-direction:column;align-items:flex-start}
}
</style>
</head>

<body>

<main class="page">

<header class="top">
  <a class="brand" href="/ipmdb/" aria-label="IPMdb home">
    <strong>IPMdb</strong>
    <span>Ideas 2 Assets</span>
  </a>

  <nav class="nav" aria-label="IPMdb navigation">
    <a class="btn" href="/ipmdb/search.php">Search</a>
    <a class="btn" href="/ipmdb/ledger.php">Ledger</a>
    <a class="btn" href="/ipmdb/viewer.php">Viewer</a>
    <a class="btn" href="/ipmdb/admin.php">Admin</a>
  </nav>
</header>

<section class="stage">
  <div class="card">

    <h1>Lock Idea</h1>
    <div class="sub">I2A · Ideas 2 Assets</div>

    <form method="post" action="/ipmdb/lock.php" id="lockForm">
      <?= ipmdb_csrf_field() ?>

      <div class="form-trap" aria-hidden="true">
        <label for="website">Website</label>
        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <div class="row">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" placeholder="YOUR EMAIL" autocomplete="email" inputmode="email" required tabindex="1">
      </div>

      <div class="row">
        <label for="title">Title</label>
        <input id="title" name="title" type="text" placeholder="IDEA (30 CHARS MAX)" maxlength="30" autocomplete="off" required tabindex="2">
      </div>

      <div class="row">
        <label for="idea">Idea</label>
        <textarea id="idea" name="idea" maxlength="5000" placeholder="DESCRIBE YOUR IDEA" required tabindex="3"></textarea>

        <div class="gauge-top">
          <span id="count">0 / 5000</span>
          <button class="btn" type="button" id="expandBtn" tabindex="4">⛶ Expand</button>
        </div>

        <div class="gauge"><div class="bar" id="bar"></div></div>
      </div>

      <input type="hidden" name="category" value="Uncategorized">

      <div class="actions">
        <button class="primary" type="submit" id="lockButton" tabindex="5">LOCK IDEA</button>
        <a class="btn" href="/ipmdb/ecosystem.php">SYSTEM MAP</a>
        <a class="btn" href="/ipmdb/ledger.php">ASSET LEDGER</a>
        <a class="btn" href="/ipmdb/search.php">SEARCH</a>
        <a class="btn" href="/ipmdb/dad/">DAD</a>
      </div>

    </form>

    <p class="mark">I2A · IDEAS 2 ASSETS</p>

  </div>
</section>

<footer class="foot">
  <span><strong>Ideas 2 Assets</strong></span>
  <span>Truth over convenience.</span>
</footer>

</main>

<div id="fullscreenEditor" class="fullscreen-editor">
  <div class="fullscreen-top">
    <div>
      <div class="fullscreen-title">IPMdb Idea Editor</div>
      <div id="fullCounter" class="gauge-top">0 / 5000</div>
    </div>

    <button class="btn" type="button" id="doneIdea">Done</button>
  </div>

  <textarea id="fullIdeaText" maxlength="5000" placeholder="EXPAND YOUR THINKING"></textarea>
</div>

<script>
const email=document.getElementById('email');
const title=document.getElementById('title');
const idea=document.getElementById('idea');
const count=document.getElementById('count');
const bar=document.getElementById('bar');
const expandBtn=document.getElementById('expandBtn');
const lockButton=document.getElementById('lockButton');
const lockForm=document.getElementById('lockForm');
const fullscreenEditor=document.getElementById('fullscreenEditor');
const fullIdeaText=document.getElementById('fullIdeaText');
const fullCounter=document.getElementById('fullCounter');

function updateGauge(){
  if(!idea||!count||!bar)return;

  const max=Number(idea.getAttribute('maxlength')||5000);
  const len=idea.value.length;
  const pct=Math.min(100,(len/max)*100);

  count.textContent=len+' / '+max;
  if(fullCounter)fullCounter.textContent=len+' / '+max;

  bar.style.width=pct+'%';
  bar.classList.remove('warn','danger');

  if(len>=4750){
    bar.classList.add('danger');
  }else if(len>=4000){
    bar.classList.add('warn');
  }
}

if(email&&title){
  email.addEventListener('keydown',function(ev){
    if(ev.key==='Enter'){
      ev.preventDefault();
      title.focus();
    }
  });
}

if(title&&idea){
  title.addEventListener('keydown',function(ev){
    if(ev.key==='Enter'){
      ev.preventDefault();
      idea.focus();
    }

    if(ev.key==='Tab'&&!ev.shiftKey){
      ev.preventDefault();
      idea.focus();
    }
  });
}

if(idea&&lockButton){
  idea.addEventListener('input',updateGauge);

  idea.addEventListener('keydown',function(ev){
    if(ev.key==='Tab'&&!ev.shiftKey){
      ev.preventDefault();
      lockButton.focus();
    }

    if(ev.key==='Enter'&&(ev.metaKey||ev.ctrlKey)){
      ev.preventDefault();
      lockForm.requestSubmit();
    }
  });

  updateGauge();
}

if(expandBtn&&idea&&fullscreenEditor&&fullIdeaText){
  expandBtn.addEventListener('click',function(){
    fullIdeaText.value=idea.value;
    fullscreenEditor.classList.add('active');
    fullIdeaText.focus();
    updateGauge();
  });
}

if(fullIdeaText&&idea){
  fullIdeaText.addEventListener('input',function(){
    idea.value=fullIdeaText.value;
    updateGauge();
  });
}

const doneIdea=document.getElementById('doneIdea');

if(doneIdea&&fullscreenEditor&&fullIdeaText&&idea){
  doneIdea.addEventListener('click',function(){
    idea.value=fullIdeaText.value;
    fullscreenEditor.classList.remove('active');
    updateGauge();
    idea.focus();
  });
}

if(lockButton&&lockForm){
  lockButton.addEventListener('keydown',function(ev){
    if(ev.key==='Enter'||ev.key===' '){
      ev.preventDefault();
      lockForm.requestSubmit();
    }
  });
}
</script>

</body>
</html>
