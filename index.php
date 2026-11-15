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
date_default_timezone_set('Asia/Manila'); // display + new timestamps in Philippine time

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
    // Migration for DBs created before created_at existed
    $cols = $pdo->query("PRAGMA table_info(tickets)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('created_at', $cols, true)) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN created_at TEXT NOT NULL DEFAULT ''");
    }
    return $pdo;
}

function loadTickets() {
    return db()->query("SELECT id, type, created_at FROM tickets ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
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
            $stmt->execute([$newId, $type, '', '', 'New', gmdate('Y-m-d H:i:s')]);
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
    $raw = $t['created_at'] ?? '';
    if ($raw !== '') {
        try {
            // Stored values are UTC; display in Philippine time.
            $dt = new DateTime($raw, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
            $ts = $dt->format('M j, g:i A');
            $full = $dt->format('Y-m-d H:i:s') . ' PHT';
        } catch (Exception $ex) {
            $ts = $raw;
            $full = $raw;
        }
    } else {
        $ts = '—';
        $full = '';
    }
    ob_start(); ?>
    <tr>
        <?php if ($editable): ?>
        <td>
            <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <input name="new_id" required maxlength="20" value="<?= e($id) ?>" size="8" class="form-control form-control-sm ticket-id-input">
                <button type="submit" class="btn btn-sm btn-save">Save</button>
                <span class="ts badge ts-badge" title="<?= e($full) ?>"><?= e($ts) ?></span>
            </form>
        </td>
        <td class="text-end">
            <form method="post" style="display:inline" onsubmit="return confirm('Delete ticket <?= e($id) ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= e($id) ?>">
                <button type="submit" class="btn btn-sm btn-delete">Delete</button>
            </form>
        </td>
        <?php else: ?>
        <td><span class="fw-semibold"><?= e($id) ?></span> <span class="ts badge ts-badge"><?= e($ts) ?></span></td>
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
    background-image: linear-gradient(180deg, #FFD07B33 0%, #ffffff00 260px);
}
.topbar {
    background: linear-gradient(100deg, var(--blue-dark), var(--blue));
    border-bottom: 4px solid var(--gold);
    border-radius: .75rem;
    color: #fff;
}
.topbar a { color: var(--gold-light); }
.topbar .who { color: #eaf3fe; font-size: .9rem; }
.card-tickets { border: 1px solid #ead9ae; border-radius: .75rem; overflow: hidden; box-shadow: 0 2px 10px #b1740f22; }
.card-tickets .card-header { color: #fff; font-weight: 600; }
.card-chats .card-header { background: linear-gradient(100deg, var(--blue-dark), var(--blue)); }
.card-emails .card-header { background: linear-gradient(100deg, var(--gold-dark), var(--gold)); color: #231a02; }
.table thead th { background: #f6ecd2; color: #5c430a; border-bottom: 2px solid var(--gold); font-size: .85rem; text-transform: uppercase; letter-spacing: .04em; }
.btn-add { background: var(--gold); border-color: var(--gold-dark); color: #231a02; font-weight: 600; }
.btn-add:hover { background: var(--gold-dark); border-color: var(--gold-dark); color: #fff; }
.btn-save { background: var(--blue-dark); border-color: var(--blue-dark); color: #fff; }
.btn-save:hover { background: var(--blue); border-color: var(--blue); color: #fff; }
.btn-delete { background: #fff; border: 1px solid #d9a40f; color: var(--gold-dark); }
.btn-delete:hover { background: var(--gold-dark); border-color: var(--gold-dark); color: #fff; }
.ticket-id-input { max-width: 130px; }
.ts-badge { background: var(--gold-light); color: #5c430a; font-weight: 500; font-size: .78rem; white-space: nowrap; }
.readonly { color: #5c430a; font-size: .9rem; background: #fff3d4; border: 1px solid var(--gold); border-radius: .5rem; padding: .5rem .75rem; }
.error { color: #b00; }
</style>
</head>
<body>
<div class="container py-4" style="max-width: 960px">
<div class="topbar d-flex justify-content-between align-items-center gap-3 px-3 py-3 mb-3">
    <h1 class="h3 mb-0">Tickets</h1>
    <?php if ($isLoggedIn): ?>
        <span class="who">Signed in as <?= e($_SESSION['user']) ?> &middot; <a href="index.php?logout=1">Log out</a></span>
    <?php else: ?>
        <span><a href="login.php">Log in to edit</a></span>
    <?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (!$isLoggedIn): ?><p class="readonly">Read-only view — log in to add, rename, or delete tickets.</p><?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
      <div class="card card-tickets card-chats h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Chats (<?= count($chats) ?>)</span>
        </div>
        <div class="card-body">
        <?php if ($isLoggedIn): ?>
        <form method="post" class="d-flex gap-2 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type" value="chat">
            <input name="id" maxlength="20" placeholder="Paste ticket no. (blank = auto)" class="form-control form-control-sm">
            <button type="submit" class="btn btn-sm btn-add">Add</button>
        </form>
        <?php endif; ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Ticket No.</th><?php if ($isLoggedIn): ?><th class="text-end"></th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (empty($chats)): ?>
                <tr><td colspan="<?= $isLoggedIn ? 2 : 1 ?>">None.</td></tr>
            <?php else: ?>
                <?php foreach ($chats as $t) echo ticketRow($t, $isLoggedIn); ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card card-tickets card-emails h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Emails (<?= count($emails) ?>)</span>
        </div>
        <div class="card-body">
        <?php if ($isLoggedIn): ?>
        <form method="post" class="d-flex gap-2 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type" value="email">
            <input name="id" maxlength="20" placeholder="Paste ticket no. (blank = auto)" class="form-control form-control-sm">
            <button type="submit" class="btn btn-sm btn-add">Add</button>
        </form>
        <?php endif; ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Ticket No.</th><?php if ($isLoggedIn): ?><th class="text-end"></th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (empty($emails)): ?>
                <tr><td colspan="<?= $isLoggedIn ? 2 : 1 ?>">None.</td></tr>
            <?php else: ?>
                <?php foreach ($emails as $t) echo ticketRow($t, $isLoggedIn); ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
      </div>
    </div>
</div>
</div>
</body>
</html>
