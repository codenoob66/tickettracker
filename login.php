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
<style>
body { font-family: system-ui, sans-serif; max-width: 420px; margin: 4rem auto; padding: 0 1rem; }
form { display: grid; gap: .75rem; }
input, button { padding: .5rem .7rem; font-size: 1rem; }
button { cursor: pointer; }
.error { color: #b00; }
.muted { color: #555; font-size: .9rem; }
</style>
</head>
<body>
<h1>Login</h1>
<p class="muted">Editor login. The ticket dashboard stays viewable to everyone without logging in.</p>
<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
    <label>Username<br><input name="username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>"></label>
    <label>Password<br><input name="password" type="password" required autocomplete="current-password"></label>
    <button type="submit">Log in</button>
</form>
<p><a href="index.php">&larr; Back to tickets (read-only view)</a></p>
</body>
</html>
