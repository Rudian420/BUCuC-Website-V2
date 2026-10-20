<?php
/**
 * Log out. Only a POST with the page's CSRF token logs out, so a link or image on another site
 * can't sign an admin out. Opening this page directly shows a "Log out" button instead.
 */
require_once '../backend/includes/auth.php';
auth_start_session();

if (empty($_SESSION['admin'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    log_activity('logout', 'Signed out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax']);
    }
    session_destroy();
    header('Location: index.php');
    exit();
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Log out - BUCuC</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: rgb(var(--c-bg, 6 4 15)); color: rgb(var(--c-text, 243 240 255)); font: 16px/1.5 system-ui, sans-serif; padding: 24px 16px; box-sizing: border-box; }
        .card { width: 100%; max-width: 360px; text-align: center; background: rgb(var(--c-surface, 14 9 36)); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / .18); border-radius: 18px; padding: 28px 24px; box-sizing: border-box; }
        button { width: 100%; padding: 11px; border: 0; border-radius: 999px; background: rgb(var(--c-accent2, 92 255 176)); color: rgb(var(--c-bg, 6 4 15)); font-weight: 700; font-size: 15px; cursor: pointer; }
        a { color: rgb(var(--c-muted, 170 163 210)); display: inline-block; margin-top: 14px; }
    </style>
</head>

<body>
    <div class="card">
        <h1 style="font-size:22px;margin:0 0 16px">Log out?</h1>
        <form method="POST" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit">Log out</button>
        </form>
        <a href="<?= htmlspecialchars(auth_dashboard_page()) ?>">Back to the dashboard</a>
    </div>
</body>

</html>
