<?php
/**
 * Create (or reset the password of) an admin account from the command line.
 *
 *   php backend/scripts/create_admin.php "<username>" <email> <password> [role]
 *
 * role: super_admin (default) | panel_admin | hr_admin | miap_admin | secretary_admin
 * Each role holds one account. If the email already exists, its password and role are updated.
 * If another account holds the role, that account loses the role (it can't log in until the
 * Super Admin assigns it a new one). Refuses to run from a web browser.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/auth.php';

[$script, $username, $email, $password, $role] = array_pad($argv, 5, null);
$role = $role ?: 'super_admin';
$roles = implode('|', array_keys(ROLES));

if (!$username || !$email || !$password) {
    exit("Usage: php backend/scripts/create_admin.php \"<username>\" <email> <password> [$roles]\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Invalid email address.\n");
}
if (strlen($password) < AUTH_MIN_PASSWORD) {
    exit("Password must be at least " . AUTH_MIN_PASSWORD . " characters.\n");
}
if (!isset(ROLES[$role])) {
    exit("Role must be one of: $roles\n");
}

$pdo = db();
$hash = password_hash($password, PASSWORD_DEFAULT);
$email = strtolower($email);

$pdo->beginTransaction();
$existing = $pdo->prepare("SELECT id FROM adminpanel WHERE email = ?");
$existing->execute([$email]);
$row = $existing->fetch();

$holder = $pdo->prepare("SELECT id, email FROM adminpanel WHERE role = ? AND id <> ?");
$holder->execute([$role, $row['id'] ?? 0]);
foreach ($holder->fetchAll() as $h) {
    $pdo->prepare("UPDATE adminpanel SET role = NULL WHERE id = ?")->execute([$h['id']]);
    echo "Note: {$h['email']} no longer has the role $role.\n";
}

if ($row) {
    $pdo->prepare("UPDATE adminpanel SET password = ?, role = ?, status = 'active' WHERE id = ?")
        ->execute([$hash, $role, $row['id']]);
    echo "Updated admin #{$row['id']} ({$email}) — role: {$role}\n";
} else {
    $pdo->prepare("INSERT INTO adminpanel (username, email, password, role, status) VALUES (?, ?, ?, ?, 'active')")
        ->execute([$username, $email, $hash, $role]);
    echo "Created admin #" . $pdo->lastInsertId() . " ({$email}) — role: {$role}\n";
}
$pdo->commit();
