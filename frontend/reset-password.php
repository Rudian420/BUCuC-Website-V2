<?php
require_once '../backend/includes/auth.php';
require_once '../backend/includes/auth_mail.php';
auth_start_session();
site_send_never_cache_headers();
site_send_noindex_headers();

$conn = db();

$token = $_POST['token'] ?? ($_GET['token'] ?? '');
$reset = find_reset_token($conn, $token);
$error = '';

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!csrf_check()) {
        $error = 'The form expired. Please try again.';
    } elseif (strlen($password) < AUTH_MIN_PASSWORD) {
        $error = 'The password must be at least ' . AUTH_MIN_PASSWORD . ' characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $conn->beginTransaction();
        $conn->prepare("UPDATE adminpanel SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $reset['id']]);
        // Use this token and any other open ones for the account
        $conn->prepare("UPDATE admin_password_resets SET used_at = NOW() WHERE admin_id = ? AND used_at IS NULL")->execute([$reset['id']]);
        // A fresh password clears any login lock on the account
        $conn->prepare("DELETE FROM admin_login_attempts WHERE email = ? AND success = 0")->execute([strtolower($reset['email'])]);
        $conn->commit();

        log_activity('password_reset', 'Password changed with an email reset link', $reset['id'], $reset['username'], null, null, $reset['role']);

        $when = date('j M Y, g:i a');
        $html = auth_mail_layout('Your admin password was changed',
            '<p style="margin:0 0 14px;line-height:1.6">Hi ' . htmlspecialchars($reset['username']) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6">The password for your BUCuC admin account was changed on ' . $when . ' using a reset link.</p>'
            . '<p style="margin:0;line-height:1.6;color:rgb(var(--c-danger-b0285c, 176 40 92))">If this wasn\'t you, contact a senior admin straight away.</p>');
        $text = "Hi {$reset['username']},\n\nThe password for your BUCuC admin account was changed on $when using a reset link.\nIf this wasn't you, contact a senior admin straight away.";
        send_auth_email($reset['email'], $reset['username'], 'Your BUCuC admin password was changed', $html, $text);

        auth_clear_admin_session();
        $_SESSION['auth_flash'] = 'Your password was changed. Log in with the new password.';
        header('Location: admin-login.php');
        exit();
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="referrer" content="no-referrer" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Reset Password | BUCuC</title>
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
            <div class="logo-icon"><i class="fas fa-lock"></i></div>
            <h1 class="login-title">New Password</h1>
            <?php if ($reset): ?>
                <p class="login-subtitle">Choose a new password for <strong><?= htmlspecialchars($reset['email']) ?></strong>.</p>
            <?php endif; ?>
        </div>

        <?php if (!$reset): ?>
            <div class="error-message" style="display: block; margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <span>This reset link is invalid, already used, or expired. Request a new one.</span>
            </div>
            <div class="back-link" style="margin-bottom: 10px;">
                <a href="forgot-password.php"><i class="fas fa-key"></i> Request a new link</a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error-message" style="display: block; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="reset-password.php" method="POST" id="resetForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" class="form-control" name="password" placeholder="New password (min <?= AUTH_MIN_PASSWORD ?> characters)" minlength="<?= AUTH_MIN_PASSWORD ?>" autocomplete="new-password" required />
                </div>
                <div class="form-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" class="form-control" name="confirm_password" placeholder="Repeat new password" minlength="<?= AUTH_MIN_PASSWORD ?>" autocomplete="new-password" required />
                </div>
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-check me-2"></i>
                    Save new password
                </button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="admin-login.php"><i class="fas fa-arrow-left"></i> Back to login</a>
        </div>
    </div>
</body>

</html>
