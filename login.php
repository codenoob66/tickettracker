<?php
// Login page — only the editor needs this. Dashboard (index.php) stays public read-only.
// Deploy note: upload as-is into the same folder as index.php (root or subfolder works).
if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
}
error_reporting(E_ALL & ~E_DEPRECATED);
if (function_exists('header')) {
    header('X-Content-Type-Options: nosniff');
}
session_start();
require_once __DIR__ . '/auth_config.php';

if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sent = $_POST['csrf'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($have) || $have === '' || !hash_equals($have, $sent)) {
        $error = 'Session expired. Please try again.';
    } else {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($AUTH_HASH === '' || $AUTH_HASH === null) {
        $error = 'Login is not set up yet. Open setup_password.php once to set it.';
    } elseif (hash_equals($AUTH_USER, $user) && password_verify($pass, $AUTH_HASH)) {
        session_regenerate_id(true);
        $_SESSION['user'] = $AUTH_USER;
        header('Location: index.php');
        exit;
    } else {
        // Small brute-force slowdown (shared hosting has no WAF control)
        usleep(500000);
        $error = 'Invalid username or password.';
    }
    }
}
function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Tickets</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
    --gold-dark: #B1740F;
    --gold-light: #FFD07B;
    --gold: #FDB833;
    --blue-dark: #296EB4;
    --blue: #1789FC;
}
body {
    font-family: system-ui, sans-serif;
    background: #fffaf0;
    background-image: linear-gradient(180deg, #FFD07B33 0%, #ffffff00 320px);
    min-height: 100vh;
    display: flex;
    align-items: center;
}
.login-card { border: 1px solid #ead9ae; border-radius: .9rem; overflow: hidden; box-shadow: 0 4px 18px #b1740f33; }
.login-card .card-header {
    background: linear-gradient(100deg, var(--blue-dark), var(--blue));
    border-bottom: 4px solid var(--gold);
    color: #fff;
}
.btn-login { background: var(--gold); border-color: var(--gold-dark); color: #231a02; font-weight: 600; }
.btn-login:hover { background: var(--gold-dark); border-color: var(--gold-dark); color: #fff; }
.back-link { color: var(--blue-dark); }
.back-link:hover { color: var(--blue); }
.muted { color: #6b5518; font-size: .9rem; }
</style>
</head>
<body>
<div class="container py-4" style="max-width: 440px">
<div class="card login-card">
  <div class="card-header px-4 py-3">
    <h1 class="h4 mb-0">Login</h1>
  </div>
  <div class="card-body p-4">
<p class="muted">Editor login. The ticket dashboard stays viewable to everyone without logging in.</p>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="d-grid gap-3">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
    <div><label class="form-label">Username<input name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" class="form-control"></label></div>
    <div><label class="form-label">Password<input name="password" type="password" required autocomplete="current-password" class="form-control"></label></div>
    <button type="submit" class="btn btn-login">Log in</button>
</form>
<p class="mt-3 mb-0"><a href="index.php" class="back-link">&larr; Back to tickets (read-only view)</a></p>
  </div>
</div>
</div>
</body>
</html>
