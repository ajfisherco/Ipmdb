<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function h(?string $value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';

if (ipmdb_logged_in()) {
  header('Location: /ipmdb/admin.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  ipmdb_require_csrf();

  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  if (ipmdb_login($email, $password)) {
    header('Location: /ipmdb/admin.php');
    exit;
  }

  $error = ipmdb_login_is_rate_limited()
    ? 'Too many attempts. Try again in 15 minutes.'
    : 'Login failed.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login | IPMdb</title>
<style>
body{margin:0;min-height:100svh;background:#020617;color:#e5f2ff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}
main{width:min(680px,94vw);min-height:100svh;margin:0 auto;padding:28px 0 96px;display:flex;align-items:center;justify-content:center}
.card{width:100%;border:1px solid rgba(148,163,184,.25);border-radius:30px;background:rgba(15,23,42,.78);box-shadow:0 24px 90px rgba(0,0,0,.42);overflow:hidden}
.hero{padding:clamp(28px,6vw,62px);text-align:center;border-bottom:1px solid rgba(148,163,184,.25)}
.brand{font-size:clamp(42px,9vw,92px);font-weight:1000;letter-spacing:-.06em;line-height:.9}
.brand span{color:#86efac}
.sub{margin-top:16px;color:#9fb4ca;font-size:clamp(18px,3vw,26px);font-weight:800}
form{padding:clamp(20px,5vw,44px)}
label{display:block;color:#9fb4ca;font-size:13px;font-weight:1000;letter-spacing:.13em;text-transform:uppercase;margin:0 0 8px}
input{width:100%;box-sizing:border-box;border:1px solid rgba(148,163,184,.25);border-radius:18px;background:rgba(2,6,23,.56);color:#e5f2ff;padding:18px 20px;font-size:20px;font-weight:800;outline:none;margin-bottom:18px}
button{width:100%;border:0;cursor:pointer;border-radius:999px;padding:16px 18px;background:#2563eb;color:white;font-size:16px;font-weight:1000;letter-spacing:.04em;text-transform:uppercase}
.error{margin-bottom:18px;border-radius:18px;padding:16px 18px;color:#fecaca;background:rgba(127,29,29,.24);border:1px solid rgba(254,202,202,.30);font-size:18px;font-weight:900}
footer{position:fixed;left:0;right:0;bottom:0;padding:14px 22px;background:rgba(2,6,23,.88);border-top:1px solid rgba(148,163,184,.24);display:flex;justify-content:space-between;color:#9fb4ca;font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
</style>
</head>
<body>
<main>
  <section class="card">
    <div class="hero">
      <div class="brand">IPM<span>db</span></div>
      <div class="sub">Admin access</div>
    </div>

    <form method="post" action="/ipmdb/login.php">
      <?= ipmdb_csrf_field() ?>
      <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
      <?php endif; ?>

      <label for="email">Email</label>
      <input id="email" name="email" type="email" autocomplete="username" required>

      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>

      <button type="submit">Login</button>
    </form>
  </section>
</main>

<footer>
  <span>Ideas 2 Assets</span>
  <span>IPMdb.ai</span>
</footer>
</body>
</html>
