<?php
require_once '../backend/includes/auth.php';
auth_start_session();
site_send_never_cache_headers();
site_send_noindex_headers();

// Already signed in → straight to the dashboard
if (!empty($_SESSION['admin']) && !empty($_SESSION['admin_id'])) {
  header('Location: ' . auth_dashboard_page());
  exit();
}

$conn = db();

$success = "";
$error = "";
$notice = $_SESSION['auth_flash'] ?? '';
unset($_SESSION['auth_flash']);
$adminEmail = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $adminEmail = strtolower(trim($_POST["adminEmail"] ?? ''));
  $adminPassword = $_POST["adminPassword"] ?? '';

  if (!csrf_check()) {
    $error = "The login form expired. Please try again.";
  } elseif ($adminEmail === '' || $adminPassword === '') {
    $error = "Enter your email and password.";
  } elseif ($wait = login_lock_minutes($conn, $adminEmail)) {
    $error = "Too many failed attempts. Try again in $wait minute" . ($wait === 1 ? '' : 's') . ", or reset your password.";
  } else {
    $stmt = $conn->prepare("SELECT * FROM adminpanel WHERE email = ?");
    $stmt->execute([$adminEmail]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $passwordOk = $admin && password_verify($adminPassword, $admin['password']);

    $role = $admin ? role_normalize($admin['role']) : null;
    $lockedOut = $passwordOk && $admin['status'] === 'active' && $role === null;

    if ($passwordOk && $admin['status'] === 'active' && !$lockedOut) {
      record_login_attempt($conn, $adminEmail, true);
      if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
        $conn->prepare("UPDATE adminpanel SET password = ? WHERE id = ?")->execute([password_hash($adminPassword, PASSWORD_DEFAULT), $admin['id']]);
      }
      auth_login($admin);
      log_activity('login', 'Signed in');
      header('Location: ' . auth_dashboard_page());
      exit();
    }

    if ($lockedOut) {
      // Right password, but no role yet: not a failed attempt
      log_activity('login_blocked', 'No admin role assigned', $admin['id'], $admin['username'], null, null, $role);
      $error = "Your account has no admin role yet. Ask a senior admin to assign one.";
    } else {
      record_login_attempt($conn, $adminEmail, false);
      if ($admin) {
        log_activity('login_failed', $passwordOk ? 'Inactive account' : 'Wrong password', $admin['id'], $admin['username'], null, null, $role);
      }
      $error = $passwordOk
        ? "This admin account is inactive. Ask a senior admin to reactivate it."
        : "Invalid email or password.";
    }
  }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Admin Login | BUCuC</title>

  <!-- Bootstrap CSS -->
  <link href="css/bootstrap.min.css" rel="stylesheet" />
  <link href="css/bootstrap-icons.css" rel="stylesheet" />
  <link href="css/templatemo-festava-live.css" rel="stylesheet" />

  <!-- Font Awesome -->
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
  <!-- Floating Particles -->
  <div class="particles" id="particles"></div>

  <div class="login-container">
    <div class="logo-section">
      <div class="logo-icon">
        <i class="fas fa-user-shield"></i>
      </div>
      <h1 class="login-title">Admin Login</h1>
      <p class="login-subtitle">
        Enter your credentials to access the dashboard
      </p>
    </div>

    <?php if (!empty($error)): ?>
      <div class="error-message" id="errorMessage" style="display: block; margin-bottom: 20px;">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <span id="errorText"><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <?php if (!empty($notice)): ?>
      <div class="success-message" style="display: block; margin-bottom: 20px;">
        <i class="fas fa-info-circle me-2"></i>
        <span><?= htmlspecialchars($notice) ?></span>
      </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
      <div class="success-message" id="successMessage" style="display: block; margin-bottom: 20px;">
        <i class="fas fa-check-circle me-2"></i>
        <span id="successText"><?= htmlspecialchars($success) ?></span>
      </div>
    <?php endif; ?>

    <form action="" method="POST" id="adminLoginForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <div class="form-group">
        <i class="fas fa-envelope input-icon"></i>
        <input type="email" class="form-control" id="adminEmail" name="adminEmail" placeholder="Admin Email" value="<?= htmlspecialchars($adminEmail) ?>" autocomplete="username" required />
      </div>

      <div class="form-group password-input-group">
        <i class="fas fa-lock input-icon"></i>
        <input type="password" class="form-control" id="adminPassword" name="adminPassword" placeholder="Admin Password"
          autocomplete="current-password" required />
        <i class="fas fa-eye password-toggle" id="togglePassword"></i>
      </div>

      <button type="submit" class="btn btn-login">
        <i class="fas fa-sign-in-alt me-2"></i>
        Login to Dashboard
      </button>
    </form>

    <div class="back-link" style="margin-bottom: 10px;">
      <a href="forgot-password.php">
        <i class="fas fa-key"></i>
        Forgot password?
      </a>
    </div>

    <div class="back-link">
      <a href="index.php">
        <i class="fas fa-arrow-left"></i>
        Back to Main Site
      </a>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="js/bootstrap.min.js"></script>
  <script src="js/admin-login.js"></script>
</body>

</html>