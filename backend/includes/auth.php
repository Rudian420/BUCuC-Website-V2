<?php
/**
 * Admin authentication helpers, shared by every admin page and endpoint.
 *
 *   require_once __DIR__ . '/../../backend/includes/auth.php';   (path relative to the caller)
 *   require_admin();                 // any active admin; also checks CSRF on POST
 *   require_admin(['super' => true]); // Super Admin only
 *   require_admin(['can' => 'view_members']);                    // needs a permission (roles.php)
 *   require_admin(['can' => ['upload_panel', 'upload_events']]); // needs any of these
 *   require_admin(['json' => true]);  // JSON endpoints: 401/403 JSON instead of redirects
 *
 * Provides: secure session cookies, idle timeout, live account check (deactivated, deleted or
 * role-less admins are signed out on their next request), CSRF tokens (auto-added to every POST
 * form), the lockdown notice on every admin page (a lockdown only closes the public site),
 * login throttling, password-reset tokens and the activity log.
 */

require_once __DIR__ . '/../Database/db.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/palette.php';

// Every HTML page gets the saved site palette (Admin → Site Colours), except pages that define
// NO_SITE_PALETTE (the Site Colours page itself always shows the original colours).
if (PHP_SAPI !== 'cli' && !defined('NO_SITE_PALETTE')) {
    ob_start('palette_inject');
}

const AUTH_IDLE_TIMEOUT    = 30 * 60;   // seconds of inactivity before an admin is signed out
const AUTH_MAX_SESSION     = 12 * 3600; // seconds after login when an admin must log in again, active or not
const AUTH_MIN_PASSWORD    = 8;         // minimum password length for new / changed passwords
const AUTH_MAX_FAILS_EMAIL = 5;         // failed logins per e-mail ...
const AUTH_MAX_FAILS_IP    = 20;        // ... or per IP address ...
const AUTH_LOCK_MINUTES    = 15;        // ... within this window block further attempts
const AUTH_RESET_MINUTES   = 30;        // password-reset links expire after this

/* ------------------------------------------------------------------ session */

function auth_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout applies to admin sessions only (applicants' flash messages are untouched)
    if (!empty($_SESSION['admin'])) {
        $last = $_SESSION['last_activity'] ?? time();
        $_SESSION['login_at'] = $_SESSION['login_at'] ?? time();   // sessions started before this check existed
        if (time() - $last > AUTH_IDLE_TIMEOUT) {
            auth_clear_admin_session();
            $_SESSION['auth_flash'] = 'Your session expired after ' . (AUTH_IDLE_TIMEOUT / 60) . ' minutes of inactivity. Please log in again.';
        } elseif (time() - ($_SESSION['login_at'] ?? time()) > AUTH_MAX_SESSION) {
            auth_clear_admin_session();
            $_SESSION['auth_flash'] = 'For security, you are signed out ' . (AUTH_MAX_SESSION / 3600) . ' hours after logging in. Please log in again.';
        } else {
            $_SESSION['last_activity'] = time();
        }
    }
}

function auth_clear_admin_session()
{
    foreach (['admin', 'admin_id', 'username', 'admin_email', 'admin_role', 'admin_logged_in', 'admin_name', 'last_activity', 'login_at', 'csrf_token', 'pw_fp'] as $key) {
        unset($_SESSION[$key]);
    }
}

function auth_login(array $admin)
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['username'] = $admin['username'];
    $_SESSION['admin_name'] = $admin['username'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_role'] = role_normalize($admin['role']);
    $_SESSION['last_activity'] = time();
    $_SESSION['login_at'] = time();
    $_SESSION['pw_fp'] = auth_password_fingerprint($admin['password']);
    unset($_SESSION['csrf_token']);
}

/** Call after the signed-in admin changes their own password, so this session stays valid. */
function auth_refresh_fingerprint($newPasswordHash)
{
    $_SESSION['pw_fp'] = auth_password_fingerprint($newPasswordHash);
}

/** Changes when the password changes, so other sessions of that admin end on their next check. */
function auth_password_fingerprint($passwordHash)
{
    return substr(hash('sha256', 'bucuc-session|' . $passwordHash), 0, 32);
}

function auth_is_super()
{
    return role_normalize($_SESSION['admin_role'] ?? null) === 'super_admin';
}

/* ------------------------------------------------------------------ access control */

/** Relative URL of a frontend page, from wherever the current script lives. */
function auth_frontend_url($file)
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return (strpos($script, '/backend/') !== false ? '../../frontend/' : '') . $file;
}

function auth_deny($json, $status, $message, $redirect)
{
    if ($json) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        header('Location: ' . $redirect);
    }
    exit();
}

function require_admin(array $opts = [])
{
    $json = !empty($opts['json']);
    auth_start_session();

    if (empty($_SESSION['admin']) || empty($_SESSION['admin_id'])) {
        auth_deny($json, 401, 'Your session has ended. Please log in again.', auth_frontend_url('admin-login.php'));
    }

    // Re-check the account on every request: deactivated, deleted or re-roled admins and
    // sessions opened before a password change lose access immediately.
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT username, email, password, role, status FROM adminpanel WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $row = $stmt->fetch();
    } catch (\Throwable $e) {
        $row = ['status' => 'active', 'password' => null, 'role' => $_SESSION['admin_role'], 'username' => $_SESSION['username'], 'email' => $_SESSION['admin_email'] ?? ''];
    }
    if (!$row || $row['status'] !== 'active') {
        auth_clear_admin_session();
        $_SESSION['auth_flash'] = 'Your admin account is no longer active.';
        auth_deny($json, 401, 'Your admin account is no longer active.', auth_frontend_url('admin-login.php'));
    }
    if ($row['password'] !== null && !hash_equals($_SESSION['pw_fp'] ?? '', auth_password_fingerprint($row['password']))) {
        auth_clear_admin_session();
        $_SESSION['auth_flash'] = 'Your password was changed. Please log in again.';
        auth_deny($json, 401, 'Your password was changed. Please log in again.', auth_frontend_url('admin-login.php'));
    }
    $role = role_normalize($row['role']);
    if ($role === null) {
        auth_clear_admin_session();
        $_SESSION['auth_flash'] = 'Your account has no admin role yet. Ask a senior admin to assign one.';
        auth_deny($json, 401, 'Your account has no admin role.', auth_frontend_url('admin-login.php'));
    }
    $_SESSION['admin_role'] = $role;
    $_SESSION['username'] = $_SESSION['admin_name'] = $row['username'];
    $_SESSION['admin_email'] = $row['email'];

    $GLOBALS['AUTH_PERMISSIONS'] = permissions_for_admin($pdo, $_SESSION['admin_id'], $role);

    if (!empty($opts['super']) && !auth_is_super()) {
        auth_deny($json, 403, "You don't have permission to do this.", auth_frontend_url(auth_dashboard_page()) . '?error=' . urlencode("You don't have permission to open that page."));
    }
    if (!empty($opts['can'])) {
        require_can($opts['can'], $json);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_check()) {
        auth_deny($json, 419, 'This form expired. Reload the page and try again.',
            auth_frontend_url(($opts['csrf_redirect'] ?? null) ?: auth_dashboard_page()) . '?error=' . urlencode('That form had expired. Please try again.'));
    }

    // HTML pages get the token added to their forms; JSON and file downloads don't need it
    if (!$json && ($opts['inject'] ?? true)) {
        csrf_autoinject();
    }
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token()
{
    auth_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Accepts the token from a form field or the X-CSRF-Token header (fetch requests). */
function csrf_check()
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Adds a hidden csrf_token field to every <form method="post">, a <meta name="csrf-token">
 * tag, and the lockdown notice (when one is scheduled), so pages need no per-page edits.
 */
function csrf_autoinject()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES);
    $banner = lockdown_banner_html();
    ob_start(function ($html) use ($token, $banner) {
        if ($banner !== '') {
            $html = preg_replace_callback('/<body\b[^>]*>/i', function ($m) use ($banner) {
                return $m[0] . $banner;
            }, $html, 1);
        }
        $html = preg_replace(
            '/(<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>)/i',
            '$1<input type="hidden" name="csrf_token" value="' . $token . '">',
            $html
        );
        return preg_replace('/<\/head>/i', '    <meta name="csrf-token" content="' . $token . '">' . "\n</head>", $html, 1);
    });
}

/* ------------------------------------------------------------------ tables */

/** Creates the admin/site tables if upgrade.sql has not been run yet (see site.php). */
function auth_ensure_tables(PDO $pdo)
{
    site_ensure_schema($pdo);
}

function auth_ip()
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}

/* ------------------------------------------------------------------ activity log */

/**
 * Records an admin action: who (the signed-in admin unless $adminId/$adminName/$actorRole are
 * given), what ($action code + $details), to whom or what ($target, plus $targetRole when the
 * target is an admin) and when. Entries by or about the Super Admin are hidden from everyone
 * else (activity_log.php). Never throws: logging must not break the action itself.
 */
function log_activity($action, $details = '', $adminId = null, $adminName = null, $target = null, $targetRole = null, $actorRole = null)
{
    try {
        $pdo = db();
        auth_ensure_tables($pdo);
        $pdo->prepare("INSERT INTO admin_activity_log (admin_id, admin_name, actor_role, action, target, target_role, details, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $adminId ?? ($_SESSION['admin_id'] ?? null),
                $adminName ?? ($_SESSION['username'] ?? null),
                role_normalize($actorRole ?? ($adminId === null ? ($_SESSION['admin_role'] ?? null) : null)),
                $action,
                $target === null ? null : mb_substr((string) $target, 0, 255),
                role_normalize($targetRole),
                is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE),
                auth_ip(),
            ]);
    } catch (\Throwable $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ login throttling */

/** Minutes until another attempt is allowed, or 0 if not locked. */
function login_lock_minutes(PDO $pdo, $email)
{
    auth_ensure_tables($pdo);
    $since = date('Y-m-d H:i:s', time() - AUTH_LOCK_MINUTES * 60);

    $byEmail = $pdo->prepare("SELECT COUNT(*), MAX(created_at) FROM admin_login_attempts WHERE email = ? AND success = 0 AND created_at > ?");
    $byEmail->execute([strtolower($email), $since]);
    [$emailFails, $emailLast] = $byEmail->fetch(PDO::FETCH_NUM);

    $byIp = $pdo->prepare("SELECT COUNT(*), MAX(created_at) FROM admin_login_attempts WHERE ip = ? AND success = 0 AND created_at > ?");
    $byIp->execute([auth_ip(), $since]);
    [$ipFails, $ipLast] = $byIp->fetch(PDO::FETCH_NUM);

    $last = null;
    if ($emailFails >= AUTH_MAX_FAILS_EMAIL) $last = $emailLast;
    if ($ipFails >= AUTH_MAX_FAILS_IP) $last = max((string) $last, (string) $ipLast);
    if (!$last) {
        return 0;
    }
    $remaining = strtotime($last) + AUTH_LOCK_MINUTES * 60 - time();
    return $remaining > 0 ? (int) ceil($remaining / 60) : 0;
}

function record_login_attempt(PDO $pdo, $email, $success)
{
    auth_ensure_tables($pdo);
    $pdo->prepare("INSERT INTO admin_login_attempts (email, ip, success) VALUES (?, ?, ?)")
        ->execute([strtolower($email), auth_ip(), $success ? 1 : 0]);
    if ($success) {
        // A successful login clears this e-mail's failure count
        $pdo->prepare("DELETE FROM admin_login_attempts WHERE email = ? AND success = 0")->execute([strtolower($email)]);
    }
    // Housekeeping: keep 30 days
    if (random_int(1, 50) === 1) {
        $pdo->exec("DELETE FROM admin_login_attempts WHERE created_at < (NOW() - INTERVAL 30 DAY)");
    }
}

/* ------------------------------------------------------------------ password reset tokens */

/** Creates a single-use reset token for an admin; returns the raw token (only its hash is stored). */
function create_reset_token(PDO $pdo, $adminId)
{
    auth_ensure_tables($pdo);
    $pdo->prepare("UPDATE admin_password_resets SET used_at = NOW() WHERE admin_id = ? AND used_at IS NULL")->execute([$adminId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO admin_password_resets (admin_id, token_hash, expires_at, ip) VALUES (?, ?, ?, ?)")
        ->execute([$adminId, hash('sha256', $token), date('Y-m-d H:i:s', time() + AUTH_RESET_MINUTES * 60), auth_ip()]);
    return $token;
}

/** Returns the reset row joined with the admin, or null if the token is invalid/expired/used. */
function find_reset_token(PDO $pdo, $token)
{
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    auth_ensure_tables($pdo);
    $stmt = $pdo->prepare("SELECT r.id AS reset_id, a.id, a.username, a.email, a.role, a.status
                           FROM admin_password_resets r JOIN adminpanel a ON a.id = r.admin_id
                           WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()");
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return ($row && $row['status'] === 'active') ? $row : null;
}

/** How many reset e-mails were requested recently for this e-mail / IP (abuse limit). */
function reset_requests_recent(PDO $pdo, $adminId)
{
    auth_ensure_tables($pdo);
    $stmt = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM admin_password_resets WHERE admin_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)),
        (SELECT COUNT(*) FROM admin_password_resets WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR))");
    $stmt->execute([$adminId, auth_ip()]);
    return $stmt->fetch(PDO::FETCH_NUM);
}
