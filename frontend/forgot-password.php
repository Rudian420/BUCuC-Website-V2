<?php
require_once '../backend/includes/auth.php';
require_once '../backend/includes/auth_mail.php';
auth_start_session();
site_send_never_cache_headers();

$conn = db();

$sent = false;
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!csrf_check()) {
        $error = 'The form expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, email, role, status FROM adminpanel WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        $role = $admin ? role_normalize($admin['role']) : null;
        // No reset links for inactive or role-less accounts
        $allowed = $admin && $admin['status'] === 'active' && $role !== null;

        if ($allowed) {
            [$byAdmin, $byIp] = reset_requests_recent($conn, $admin['id']);
            if ($byAdmin < 3 && $byIp < 10) {
                $token = create_reset_token($conn, $admin['id']);
                $link = rtrim(APP_URL, '/') . '/frontend/reset-password.php?token=' . $token;
                $name = htmlspecialchars($admin['username']);
                $html = auth_mail_layout('Reset your admin password',
                    '<p style="margin:0 0 14px;line-height:1.6">Hi ' . $name . ',</p>'
                    . '<p style="margin:0 0 20px;line-height:1.6">Someone (hopefully you) asked to reset the password for your BUCuC admin account. The link works once and expires in ' . AUTH_RESET_MINUTES . ' minutes.</p>'
                    . '<p style="margin:0 0 22px"><a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;background:rgb(var(--c-paperAccent, 91 52 230));color:rgb(var(--c-fixed-ffffff, 255 255 255));text-decoration:none;font-weight:bold">Choose a new password</a></p>'
                    . '<p style="margin:0 0 8px;font-size:13px;color:rgb(var(--c-muted-6b6588, 107 101 136));line-height:1.6">If the button does not work, paste this link into your browser:<br><span style="word-break:break-all">' . htmlspecialchars($link) . '</span></p>'
                    . '<p style="margin:16px 0 0;font-size:13px;color:rgb(var(--c-muted-6b6588, 107 101 136));line-height:1.6">Didn\'t ask for this? Ignore this email; your password stays the same.</p>');
                $text = "Hi {$admin['username']},\n\nReset your BUCuC admin password (link valid for " . AUTH_RESET_MINUTES . " minutes, single use):\n$link\n\nDidn't ask for this? Ignore this email; your password stays the same.";
                $ok = send_auth_email($admin['email'], $admin['username'], 'Reset your BUCuC admin password', $html, $text);
                log_activity('password_reset_requested', $ok ? 'Reset email sent' : 'Reset email FAILED to send', $admin['id'], $admin['username'], null, null, $role);
            } else {
                log_activity('password_reset_requested', 'Blocked: too many requests in the last hour', $admin['id'], $admin['username'], null, null, $role);
            }
        }
        // Same answer whether or not the address exists, so the form can't be used to discover admin emails.
        $sent = true;
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Forgot Password | BUCuC</title>
    <link href="css/bootstrap.min.css" rel="stylesheet" />
    <link href="css/templatemo-festava-live.css" rel="stylesheet" />
    <link rel="stylesheet" href="css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/admin.css">
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin au-login">
    <script src="js/aurora-sky.js"></script>

    <div class="login-container">
        <div class="logo-section">
            <div class="logo-icon"><i class="fas fa-key"></i></div>
            <h1 class="login-title">Forgot Password</h1>
            <p class="login-subtitle">Enter your admin email and we'll send you a reset link.</p>
        </div>

        <?php if ($sent): ?>
            <div class="success-message" style="display: block; margin-bottom: 20px;">
                <i class="fas fa-envelope-open-text me-2"></i>
                <span>If <strong><?= htmlspecialchars($email) ?></strong> belongs to an active admin account, a reset link is on its way from club.bucuc@g.bracu.ac.bd. It expires in <?= AUTH_RESET_MINUTES ?> minutes. Check your spam folder too.</span>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error-message" style="display: block; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="" method="POST" id="forgotForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="form-group">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" class="form-control" name="email" placeholder="Admin Email" value="<?= htmlspecialchars($email) ?>" autocomplete="username" required />
                </div>
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-paper-plane me-2"></i>
                    Send reset link
                </button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="admin-login.php"><i class="fas fa-arrow-left"></i> Back to login</a>
        </div>
    </div>
</body>

</html>
