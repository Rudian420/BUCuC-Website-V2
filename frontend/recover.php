<?php
/**
 * One-time Super Admin recovery (see instruction.md, "Super Admin recovery").
 * Works ONLY while backend/config/recovery.token exists (upload it with File Manager; it is
 * git-ignored and blocked from browsers). Without the file this page is a plain 404.
 * On success: the given e-mail becomes the active Super Admin with the new password, any other
 * Super Admin account loses the role, the token file is deleted, and the action is logged.
 */
require_once '../backend/includes/auth.php';
site_send_never_cache_headers();

const RECOVERY_TOKEN_FILE = __DIR__ . '/../backend/config/recovery.token';
const RECOVERY_KEY = 'recovery-page';   // throttle bucket in admin_login_attempts

$token = is_file(RECOVERY_TOKEN_FILE) ? trim((string) file_get_contents(RECOVERY_TOKEN_FILE)) : '';
if (strlen($token) < 32) {
    site_not_found();   // looks exactly like any missing page
}

auth_start_session();
$conn = db();
$error = '';
$done = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    if (!csrf_check()) {
        $error = 'The form expired. Reload the page and try again.';
    } elseif ($wait = login_lock_minutes($conn, RECOVERY_KEY)) {
        $error = "Too many wrong tries. Wait $wait minute" . ($wait === 1 ? '' : 's') . '.';
    } elseif (!hash_equals($token, trim((string) ($_POST['token'] ?? '')))) {
        record_login_attempt($conn, RECOVERY_KEY, false);
        $error = 'That recovery token is not right.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid e-mail address.';
    } elseif (strlen($password) < AUTH_MIN_PASSWORD) {
        $error = 'The password must be at least ' . AUTH_MIN_PASSWORD . ' characters.';
    } elseif ($password !== ($_POST['confirm_password'] ?? '')) {
        $error = 'The two passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conn->beginTransaction();
        $find = $conn->prepare("SELECT id FROM adminpanel WHERE email = ?");
        $find->execute([$email]);
        $id = $find->fetchColumn();
        // one account per role: anyone else holding Super Admin loses it
        $conn->prepare("UPDATE adminpanel SET role = NULL WHERE role IN ('super_admin', 'main_admin') AND email <> ?")->execute([$email]);
        if ($id) {
            $conn->prepare("UPDATE adminpanel SET role = 'super_admin', status = 'active', password = ? WHERE id = ?")->execute([$hash, $id]);
        } else {
            $name = 'Super Admin';
            $taken = $conn->prepare("SELECT COUNT(*) FROM adminpanel WHERE username = ?");
            $taken->execute([$name]);
            if ($taken->fetchColumn()) $name .= ' ' . date('Ymd');
            $conn->prepare("INSERT INTO adminpanel (username, email, password, role, status) VALUES (?, ?, ?, 'super_admin', 'active')")->execute([$name, $email, $hash]);
            $id = (int) $conn->lastInsertId();
        }
        $conn->prepare("DELETE FROM admin_login_attempts WHERE email IN (?, ?)")->execute([$email, RECOVERY_KEY]);
        $conn->commit();
        log_activity('super_recovered', 'Super Admin account recovered with the recovery token', $id, null, $email, 'super_admin', 'super_admin');
        $removed = @unlink(RECOVERY_TOKEN_FILE);
        $done = $removed
            ? 'Done. The token file has been deleted, so this page is switched off again. Log in with the new password.'
            : 'Done, but the token file could NOT be deleted. Delete backend/config/recovery.token in File Manager now, then log in.';
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Account Recovery | BUCuC Admin</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: rgb(var(--c-bg, 6 4 15)); color: rgb(var(--c-text, 243 240 255)); font: 15px/1.5 system-ui, sans-serif; padding: 24px 16px; box-sizing: border-box; }
        .card { width: 100%; max-width: 440px; background: rgb(var(--c-surface, 14 9 36)); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / .18); border-radius: 18px; padding: 28px 24px; box-sizing: border-box; }
        h1 { font-size: 22px; margin: 0 0 6px; }
        p { color: rgb(var(--c-muted, 170 163 210)); margin: 0 0 16px; }
        label { display: block; font-size: 13px; color: rgb(var(--c-muted, 170 163 210)); margin: 12px 0 4px; }
        input { width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / .3); background: rgb(var(--c-surface-09061c, 9 6 28)); color: rgb(var(--c-text, 243 240 255)); font: inherit; }
        button { margin-top: 18px; width: 100%; padding: 11px; border: 0; border-radius: 999px; background: rgb(var(--c-accent2, 92 255 176)); color: rgb(var(--c-bg, 6 4 15)); font-weight: 700; font-size: 15px; cursor: pointer; }
        .err { background: rgb(var(--c-danger, 255 147 180) / .12); border: 1px solid rgb(var(--c-danger, 255 147 180) / .6); color: rgb(var(--c-danger, 255 147 180)); padding: 10px 12px; border-radius: 10px; margin-bottom: 8px; }
        .ok { background: rgb(var(--c-accent2, 92 255 176) / .1); border: 1px solid rgb(var(--c-accent2, 92 255 176) / .6); color: rgb(var(--c-accent2, 92 255 176)); padding: 10px 12px; border-radius: 10px; }
        a { color: rgb(var(--c-accent2, 92 255 176)); }
    </style>
</head>

<body>
    <div class="card">
        <h1>Super Admin recovery</h1>
        <?php if ($done): ?>
            <div class="ok"><?= htmlspecialchars($done) ?></div>
            <p style="margin-top:14px"><a href="admin-login.php">Go to the admin login</a></p>
        <?php else: ?>
            <p>Paste the token from <code>backend/config/recovery.token</code> and choose the Super Admin's e-mail and a new password. The page switches itself off afterwards.</p>
            <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST" action="recover.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <label for="token">Recovery token</label>
                <input id="token" name="token" required>
                <label for="email">Super Admin e-mail</label>
                <input id="email" type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <label for="password">New password (at least <?= AUTH_MIN_PASSWORD ?> characters)</label>
                <input id="password" type="password" name="password" required minlength="<?= AUTH_MIN_PASSWORD ?>" autocomplete="new-password">
                <label for="confirm_password">Repeat the new password</label>
                <input id="confirm_password" type="password" name="confirm_password" required minlength="<?= AUTH_MIN_PASSWORD ?>" autocomplete="new-password">
                <button type="submit">Recover Super Admin</button>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>
