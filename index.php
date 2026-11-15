<?php
// Ticket Tracker - minimal: ticket numbers only, Chats | Emails
// Storage: SQLite file tickets.db in this same folder (auto-created).
// Auth: dashboard is public read-only; Add/Save/Delete require login (see login.php).
// Deploy: upload this folder's contents as-is (domain root OR subfolder, e.g.
// public_html/ or public_html/tickets/). No path config needed — everything uses __DIR__
// and relative links. Direct access to tickets.db / auth_config.php is blocked by
// .htaccess (Apache/LiteSpeed) and web.config (IIS).
if (function_exists('ini_set')) {
    @ini_set('display_errors', '0'); // shared hosting: never leak paths/SQL in HTML
}
error_reporting(E_ALL & ~E_DEPRECATED);
if (function_exists('header')) {
    header('X-Content-Type-Options: nosniff');
}
session_start();
require_once __DIR__ . '/auth_config.php';

// CSRF token (per session, validated on every POST)
if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf'];
function csrf_field() {
    global $CSRF;
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($CSRF ?? '', ENT_QUOTES, 'UTF-8') . '">';
}
function csrf_ok() {
    $sent = $_POST['csrf'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    return is_string($sent) && is_string($have) && $have !== '' && hash_equals($have, $sent);
}

$isLoggedIn = !empty($_SESSION['user']);

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

$dbFile = __DIR__ . '/tickets.db';

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    global $dbFile;
    if (!in_array('sqlite', PDO::getAvailableDrivers())) {
        die('PDO SQLite driver is missing. Enable extension=pdo_sqlite in php.ini.');
    }
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id TEXT PRIMARY KEY,
        type TEXT NOT NULL DEFAULT 'chat',
        subject TEXT NOT NULL DEFAULT '',
        requester TEXT NOT NULL DEFAULT '',
        contact TEXT DEFAULT '',
        priority TEXT DEFAULT 'Normal',
        status TEXT DEFAULT 'New',
        description TEXT DEFAULT '',
        created_at TEXT NOT NULL
    )");
    return $pdo;
}

function loadTickets() {
    return db()->query("SELECT id, type FROM tickets ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}
function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function nextId($type) {
    $prefix = $type === 'email' ? 'E' : 'C';
    $max = 1000;
    foreach (db()->query("SELECT id FROM tickets")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (preg_match('/-(\d+)$/', $id, $m) && (int)$m[1] > $max) $max = (int)$m[1];
    }
    return $prefix . '-' . ($max + 1);
}

$error = '';

// ---------- actions (POST, login required) ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$isLoggedIn) {
        header('Location: login.php');
        exit;
    }
    if (!csrf_ok()) {
        header('Location: index.php?err=' . urlencode('Session expired. Please try again.'));
        exit;
    }
    $pdo = db();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $type = ($_POST['type'] ?? 'chat') === 'email' ? 'email' : 'chat';
        $manual = trim($_POST['id'] ?? '');
        $newId = $manual !== '' ? $manual : nextId($type);
        $chk = $pdo->prepare("SELECT 1 FROM tickets WHERE id = ? COLLATE NOCASE");
        $chk->execute([$newId]);
        if ($chk->fetch()) {
            header('Location: index.php?err=' . urlencode('Ticket ' . $newId . ' already exists.'));
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO tickets (id,type,subject,requester,status,created_at) VALUES (?,?,?,?,?,?)");
        try {
            $stmt->execute([$newId, $type, '', '', 'New', date('Y-m-d H:i:s')]);
        } catch (PDOException $ex) {
            header('Location: index.php?err=' . urlencode('Could not add ticket (duplicate number?).'));
            exit;
        }
    } elseif ($action === 'update') {
        $old = trim($_POST['id'] ?? '');
        $new = trim($_POST['new_id'] ?? '');
        if ($old !== '' && $new !== '' && $old !== $new) {
            $exists = $pdo->prepare("SELECT 1 FROM tickets WHERE id = ? COLLATE NOCASE AND id != ?");
            $exists->execute([$new, $old]);
            if ($exists->fetch()) {
                header('Location: index.php?err=' . urlencode('Ticket ' . $new . ' already exists.'));
                exit;
            }
            $stmt = $pdo->prepare("UPDATE tickets SET id = ? WHERE id = ?");
            $stmt->execute([$new, $old]);
        }
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$_POST['id'] ?? '']);
    }
    if ($error === '') {
        header('Location: index.php');
        exit;
    }
}

if (isset($_GET['err'])) $error = $_GET['err'];

$tickets = loadTickets();
$chats  = array_values(array_filter($tickets, fn($t) => ($t['type'] ?? 'chat') === 'chat'));
$emails = array_values(array_filter($tickets, fn($t) => ($t['type'] ?? '') === 'email'));

function ticketRow($t, $editable) {
    $id = $t['id'];
    ob_start(); ?>
    <tr>
        <?php if ($editable): ?>
        <td>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <input name="new_id" required maxlength="20" value="<?= e($id) ?>" size="10">
                <button type="submit">Save</button>
            </form>
        </td>
        <td>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete ticket <?= e($id) ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <button type="submit">Delete</button>
            </form>
        </td>
        <?php else: ?>
        <td><?= e($id) ?></td>
        <?php endif; ?>
    </tr>
    <?php return ob_get_clean();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tickets</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
.cols { display: flex; gap: 2rem; flex-wrap: wrap; }
.col { flex: 1; min-width: 280px; }
table { width: 100%; border-collapse: collapse; margin-top: .5rem; }
th, td { border: 1px solid #ccc; padding: .5rem; text-align: left; }
th { background: #f0f0f0; }
input, button { padding: .4rem .6rem; }
.error { color: #b00; }
.authbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; }
.authbar .who { color: #555; font-size: .9rem; }
.readonly { color: #555; font-size: .9rem; background: #f7f7f7; border: 1px solid #ddd; padding: .4rem .6rem; }
</style>
</head>
<body>
<div class="authbar">
    <h1 style="margin:0">Tickets</h1>
    <?php if ($isLoggedIn): ?>
        <span class="who">Signed in as <?= e($_SESSION['user']) ?> &middot; <a href="index.php?logout=1">Log out</a></span>
    <?php else: ?>
        <span><a href="login.php">Log in to edit</a></span>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if (!$isLoggedIn): ?><p class="readonly">Read-only view — log in to add, rename, or delete tickets.</p><?php endif; ?>

<div class="cols">
    <div class="col">
        <h2>Chats (<?= count($chats) ?>)</h2>
        <?php if ($isLoggedIn): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type" value="chat">
            <input name="id" maxlength="20" placeholder="Paste ticket no. (blank = auto)" size="28">
            <button type="submit">Add</button>
        </form>
        <?php endif; ?>
        <table>
            <tr><th>Ticket No.</th><?php if ($isLoggedIn): ?><th></th><?php endif; ?></tr>
            <?php if (empty($chats)): ?>
                <tr><td colspan="<?= $isLoggedIn ? 2 : 1 ?>">None.</td></tr>
            <?php else: ?>
                <?php foreach ($chats as $t) echo ticketRow($t, $isLoggedIn); ?>
            <?php endif; ?>
        </table>
    </div>
    <div class="col">
        <h2>Emails (<?= count($emails) ?>)</h2>
        <?php if ($isLoggedIn): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type" value="email">
            <input name="id" maxlength="20" placeholder="Paste ticket no. (blank = auto)" size="28">
            <button type="submit">Add</button>
        </form>
        <?php endif; ?>
        <table>
            <tr><th>Ticket No.</th><?php if ($isLoggedIn): ?><th></th><?php endif; ?></tr>
            <?php if (empty($emails)): ?>
                <tr><td colspan="<?= $isLoggedIn ? 2 : 1 ?>">None.</td></tr>
            <?php else: ?>
                <?php foreach ($emails as $t) echo ticketRow($t, $isLoggedIn); ?>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>
