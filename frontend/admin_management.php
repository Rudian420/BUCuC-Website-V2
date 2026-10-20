<?php
require_once '../backend/includes/auth.php';

// Super Admin, Panel Admin and Secretary Admin (HR). What each can do here comes from roles.php:
//   create: roles_creatable_by()   edit: can_edit_role()   remove/deactivate: can_remove_role()
//   permission ticks: can_toggle_permissions_for()
require_admin(['can' => 'admin_management', 'csrf_redirect' => 'admin_management.php']);

$conn = db();
$myId = (int) $_SESSION['admin_id'];
$myRole = $_SESSION['admin_role'];
$iAmSuper = auth_is_super();

$message = $_GET['success'] ?? '';
$error = '';

/** Loads an admin the current user is allowed to see (the Super Admin is invisible to others). */
function visible_admin(PDO $conn, $id, $iAmSuper)
{
    $stmt = $conn->prepare("SELECT * FROM adminpanel WHERE id = ?");
    $stmt->execute([(int) $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['role'] = role_normalize($row['role']);
    if ($row['role'] === 'super_admin' && !$iAmSuper) return null;
    return $row;
}

/** Roles nobody holds yet (one account per role). */
function vacant_roles(PDO $conn, $exceptId = 0)
{
    $stmt = $conn->prepare("SELECT role FROM adminpanel WHERE role IS NOT NULL AND id <> ?");
    $stmt->execute([(int) $exceptId]);
    $taken = array_map('role_normalize', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_diff(array_keys(ROLES), $taken));
}

function admin_target($a)
{
    return $a['username'] . ' <' . $a['email'] . '>';
}

function back_with($msg)
{
    header('Location: admin_management.php?success=' . urlencode($msg));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_admin') {
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';

        if (!in_array($role, roles_creatable_by($myRole), true)) {
            $error = "You can't create that kind of admin.";
        } elseif (!in_array($role, vacant_roles($conn), true)) {
            $error = role_label($role) . ' already exists. Each role can only have one admin. Remove or re-assign that account first.';
        } elseif ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter a username and a valid email.";
        } elseif (strlen($password) < AUTH_MIN_PASSWORD) {
            $error = "Password must be at least " . AUTH_MIN_PASSWORD . " characters long.";
        } elseif ($password !== ($_POST['confirm_password'] ?? '')) {
            $error = "Passwords do not match.";
        } else {
            $check = $conn->prepare("SELECT id FROM adminpanel WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                $error = "That username or e-mail can't be used. Choose a different one.";
            } else {
                $conn->prepare("INSERT INTO adminpanel (username, email, password, role, status) VALUES (?, ?, ?, ?, 'active')")
                    ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                log_activity('admin_added', 'Created as ' . role_label($role), null, null, "$username <$email>", $role);
                back_with("$username was added as " . role_label($role) . '.');
            }
        }
    } elseif ($action === 'update_admin') {
        $target = visible_admin($conn, $_POST['admin_id'] ?? 0, $iAmSuper);
        $isSelf = $target && (int) $target['id'] === $myId;
        if (!$target || (!$isSelf && !can_edit_role($myRole, $target['role'])) || ($isSelf && !$iAmSuper)) {
            $error = "You can't edit that admin.";
        } else {
            $username = trim($_POST['username'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $role = $target['role'];
            $status = $target['status'];
            if (!$isSelf) {
                // Role: keep, or move to a vacant role this admin may create; status: active / inactive
                $newRole = $_POST['role'] ?? ($role ?? '');
                if ($newRole !== ($role ?? '')) {
                    if (!in_array($newRole, roles_creatable_by($myRole), true) || !in_array($newRole, vacant_roles($conn, $target['id']), true)) {
                        $error = "That role isn't available.";
                    } else {
                        $role = $newRole;
                    }
                }
                if (can_remove_role($myRole, $target['role'])) {
                    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                }
            }
            $check = $conn->prepare("SELECT id FROM adminpanel WHERE (username = ? OR email = ?) AND id <> ?");
            $check->execute([$username, $email, $target['id']]);
            if (!$error && ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $error = "Enter a username and a valid email.";
            } elseif (!$error && $check->fetch()) {
                $error = "That username or e-mail can't be used. Choose a different one.";
            } elseif (!$error && $password !== '' && strlen($password) < AUTH_MIN_PASSWORD) {
                $error = "Password must be at least " . AUTH_MIN_PASSWORD . " characters long.";
            }
            if (!$error) {
                $sql = "UPDATE adminpanel SET username = ?, email = ?, role = ?, status = ?" . ($password !== '' ? ", password = ?" : "") . " WHERE id = ?";
                $params = [$username, $email, $role, $status];
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $params[] = $hash;
                }
                $params[] = $target['id'];
                $conn->prepare($sql)->execute($params);
                if ($role !== $target['role']) {
                    // Permission ticks belong to the old role
                    $conn->prepare("DELETE FROM admin_permissions WHERE admin_id = ?")->execute([$target['id']]);
                }
                if ($isSelf && $password !== '') {
                    auth_refresh_fingerprint($hash);
                }
                $changes = [];
                if ($role !== $target['role']) $changes[] = 'role ' . role_label($target['role']) . ' → ' . role_label($role);
                if ($status !== $target['status']) $changes[] = "status $status";
                if ($username !== $target['username'] || $email !== $target['email']) $changes[] = 'name/email edited';
                if ($password !== '') $changes[] = 'password changed';
                log_activity('admin_updated', $changes ? implode(', ', $changes) : 'No changes', null, null, "$username <$email>", $role ?? $target['role']);
                back_with("$username was updated.");
            }
        }
    } elseif ($action === 'delete_admin' || $action === 'toggle_status') {
        $target = visible_admin($conn, $_POST['admin_id'] ?? 0, $iAmSuper);
        if (!$target || (int) $target['id'] === $myId) {
            $error = $target ? "You can't remove or deactivate yourself." : "That admin no longer exists.";
        } elseif (!can_remove_role($myRole, $target['role'])) {
            $error = "You can only remove or deactivate admins ranked below you.";
        } elseif ($action === 'delete_admin') {
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM admin_permissions WHERE admin_id = ?")->execute([$target['id']]);
            $conn->prepare("DELETE FROM admin_password_resets WHERE admin_id = ?")->execute([$target['id']]);
            $conn->prepare("DELETE FROM adminpanel WHERE id = ?")->execute([$target['id']]);
            $conn->commit();
            log_activity('admin_deleted', 'Account removed (was ' . role_label($target['role']) . ')', null, null, admin_target($target), $target['role']);
            back_with($target['username'] . ' was removed. Their role is free for a new admin.');
        } else {
            $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
            $conn->prepare("UPDATE adminpanel SET status = ? WHERE id = ?")->execute([$newStatus, $target['id']]);
            log_activity($newStatus === 'active' ? 'admin_activated' : 'admin_deactivated',
                $newStatus === 'active' ? 'Powers restored' : 'Powers revoked; signed out', null, null, admin_target($target), $target['role']);
            back_with($target['username'] . ($newStatus === 'active'
                ? ' is active again and can log in.'
                : ' was deactivated and signed out. They cannot log in until reactivated.'));
        }
    } elseif ($action === 'save_permissions') {
        $target = visible_admin($conn, $_POST['admin_id'] ?? 0, $iAmSuper);
        if (!$target || !can_toggle_permissions_for($myRole, $target['role'])) {
            $error = "You can't change permissions for that admin.";
        } else {
            $before = permissions_for_admin($conn, $target['id'], $target['role']);
            $wanted = array_values(array_intersect(array_keys(PERMISSIONS), (array) ($_POST['perms'] ?? [])));
            $defaults = ROLE_DEFAULTS[$target['role']] ?? [];
            $conn->beginTransaction();
            $conn->prepare("DELETE FROM admin_permissions WHERE admin_id = ?")->execute([$target['id']]);
            $ins = $conn->prepare("INSERT INTO admin_permissions (admin_id, permission, allowed, updated_by) VALUES (?, ?, ?, ?)");
            foreach (array_keys(PERMISSIONS) as $perm) {
                $on = in_array($perm, $wanted, true);
                if ($on !== in_array($perm, $defaults, true)) {
                    $ins->execute([$target['id'], $perm, $on ? 1 : 0, $myId]); // store only differences from the role default
                }
            }
            $conn->commit();
            $desc = [];
            foreach (array_diff($wanted, $before) as $p) $desc[] = '+ ' . PERMISSIONS[$p]['label'];
            foreach (array_diff($before, $wanted) as $p) $desc[] = '− ' . PERMISSIONS[$p]['label'];
            log_activity('permissions_changed', $desc ? implode('; ', $desc) : 'No changes', null, null, admin_target($target), $target['role']);
            back_with('Permissions for ' . $target['username'] . ' were saved.');
        }
    }
}

// Lists and forms
$all = $conn->query("SELECT * FROM adminpanel ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$admins = [];
foreach ($all as $a) {
    $a['role'] = role_normalize($a['role']);
    if ($a['role'] === 'super_admin' && !$iAmSuper) continue;
    $a['perms'] = $a['role'] ? permissions_for_admin($conn, $a['id'], $a['role']) : [];
    $admins[] = $a;
}
usort($admins, fn($x, $y) => role_rank($x['role']) <=> role_rank($y['role']) ?: $x['id'] <=> $y['id']);

$creatable = array_values(array_intersect(roles_creatable_by($myRole), vacant_roles($conn)));
$edit = isset($_GET['edit_id']) ? visible_admin($conn, $_GET['edit_id'], $iAmSuper) : null;
if ($edit && !((int) $edit['id'] === $myId ? $iAmSuper : can_edit_role($myRole, $edit['role']))) $edit = null;
$permTarget = isset($_GET['perms_id']) ? visible_admin($conn, $_GET['perms_id'], $iAmSuper) : null;
if ($permTarget && !can_toggle_permissions_for($myRole, $permTarget['role'])) $permTarget = null;
if ($permTarget) $permTarget['perms'] = permissions_for_admin($conn, $permTarget['id'], $permTarget['role']);

$roleTone = ['super_admin' => 'r1', 'panel_admin' => 'r2', 'hr_admin' => 'r3', 'miap_admin' => 'r4', 'secretary_admin' => 'r5'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - BUCuC</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <style>
        .management-container { padding: 2rem 1rem; color: var(--ink, rgb(var(--c-fixed-ffffff, 255 255 255))); }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .back-btn { text-decoration: none; color: inherit; padding: .5rem 1rem; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); border-radius: 999px; }
        .back-btn:hover { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); color: inherit; }
        .panel { background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-radius: 18px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .panel h4 { margin-bottom: 1rem; }
        .form-control, .form-select { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-control:focus, .form-select:focus { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.35); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-select option { color: rgb(var(--c-fixed-000000, 0 0 0)); }
        .form-label { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        .admin-table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        .admin-table th { text-align: left; color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .75rem; letter-spacing: .08em; text-transform: uppercase; padding: .6rem .5rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.18); }
        .admin-table td { padding: .7rem .5rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.07); vertical-align: middle; }
        .role-pill { display: inline-block; padding: .2rem .65rem; border-radius: 999px; font-size: .75rem; font-weight: 700; white-space: nowrap; }
        .role-pill.r1 { background: rgb(var(--c-accent3, 255 79 216) / 0.18); color: rgb(var(--c-accent3-ff9be9, 255 155 233)); }
        .role-pill.r2 { background: rgb(var(--c-primary, 139 92 255) / 0.22); color: rgb(var(--c-primary-c8b6ff, 200 182 255)); }
        .role-pill.r3 { background: rgb(var(--c-accent, 45 226 230) / 0.16); color: rgb(var(--c-accent, 45 226 230)); }
        .role-pill.r4 { background: rgb(var(--c-accent2, 92 255 176) / 0.14); color: rgb(var(--c-accent2, 92 255 176)); }
        .role-pill.r5 { background: rgb(var(--c-highlight, 255 207 125) / 0.16); color: rgb(var(--c-highlight, 255 207 125)); }
        .role-pill.none { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); color: rgb(var(--c-muted, 170 163 210)); }
        .status-pill { font-size: .75rem; font-weight: 700; }
        .status-pill.on { color: rgb(var(--c-accent2, 92 255 176)); }
        .status-pill.off { color: rgb(var(--c-danger, 255 147 180)); }
        .perm-list { font-size: .78rem; color: var(--mute, rgb(var(--c-muted, 170 163 210))); max-width: 280px; }
        .act { display: flex; gap: 6px; flex-wrap: wrap; }
        .act form { margin: 0; }
        .act .btn { border-radius: 999px; }
        .perm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: .75rem; }
        .perm-item { display: flex; gap: .7rem; align-items: flex-start; padding: .8rem 1rem; border-radius: 14px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.12); background: rgb(var(--c-fixed-000000, 0 0 0) / 0.2); cursor: pointer; }
        .perm-item input { margin-top: .25rem; width: 18px; height: 18px; accent-color: rgb(var(--c-accent2, 92 255 176)); flex: none; }
        .perm-item b { display: block; }
        .perm-item small { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .perm-item .def { font-size: .7rem; color: rgb(var(--c-highlight, 255 207 125)); }
        .note { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        @media (max-width: 860px) {
            .admin-table thead { display: none; }
            .admin-table tr { display: block; padding: .8rem 0; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.12); }
            .admin-table td { display: block; border: 0; padding: .2rem 0; }
        }
    </style>
    <link rel="icon" type="image/png" href="images/logopng.png">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="container management-container">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-users-cog me-2"></i>Admin Management</h2>
            <a href="<?= auth_dashboard_page() ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($permTarget): ?>
            <!-- Permission ticks -->
            <div class="panel" id="permissions">
                <h4><i class="fas fa-sliders-h me-2"></i>Permissions: <?= htmlspecialchars($permTarget['username']) ?>
                    <span class="role-pill <?= $roleTone[$permTarget['role']] ?>"><?= htmlspecialchars(role_label($permTarget['role'])) ?></span></h4>
                <p class="note">Shortlisting is always on. Ticks marked "default" are what this role gets by itself; untick to take a power away or tick to grant one.</p>
                <form method="POST" action="admin_management.php">
                    <input type="hidden" name="action" value="save_permissions">
                    <input type="hidden" name="admin_id" value="<?= (int) $permTarget['id'] ?>">
                    <div class="perm-grid">
                        <?php foreach (PERMISSIONS as $key => $p): ?>
                            <label class="perm-item">
                                <input type="checkbox" name="perms[]" value="<?= $key ?>" <?= in_array($key, $permTarget['perms'], true) ? 'checked' : '' ?>>
                                <span><b><?= htmlspecialchars($p['label']) ?></b>
                                    <small><?= htmlspecialchars($p['help']) ?></small>
                                    <?php if (in_array($key, ROLE_DEFAULTS[$permTarget['role']] ?? [], true)): ?><span class="def d-block">default for this role</span><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Save permissions</button>
                        <a href="admin_management.php" class="btn btn-secondary ms-2">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($edit): $editSelf = (int) $edit['id'] === $myId; ?>
            <!-- Edit admin -->
            <div class="panel">
                <h4><i class="fas fa-edit me-2"></i>Edit <?= htmlspecialchars($edit['username']) ?></h4>
                <form method="POST" action="admin_management.php">
                    <input type="hidden" name="action" value="update_admin">
                    <input type="hidden" name="admin_id" value="<?= (int) $edit['id'] ?>">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required value="<?= htmlspecialchars($edit['username']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($edit['email']) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Role</label>
                            <?php if ($editSelf): ?>
                                <input type="text" class="form-control" value="<?= htmlspecialchars(role_label($edit['role'])) ?>" disabled>
                            <?php else:
                                $roleChoices = array_unique(array_merge($edit['role'] ? [$edit['role']] : [], array_intersect(roles_creatable_by($myRole), vacant_roles($conn, $edit['id'])))); ?>
                                <select name="role" class="form-select">
                                    <?php if (!$edit['role']): ?><option value="">No role (can't log in)</option><?php endif; ?>
                                    <?php foreach (array_keys(ROLES) as $r): if (!in_array($r, $roleChoices, true)) continue; ?>
                                        <option value="<?= $r ?>" <?= $r === $edit['role'] ? 'selected' : '' ?>><?= htmlspecialchars(role_label($r)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Status</label>
                            <?php if ($editSelf || !can_remove_role($myRole, $edit['role'])): ?>
                                <input type="text" class="form-control" value="<?= ucfirst($edit['status']) ?>" disabled>
                            <?php else: ?>
                                <select name="status" class="form-select">
                                    <option value="active" <?= $edit['status'] === 'active' ? 'selected' : '' ?>>Active (can log in)</option>
                                    <option value="inactive" <?= $edit['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (powers revoked)</option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">New password (blank = keep)</label>
                            <input type="password" name="password" class="form-control" minlength="<?= AUTH_MIN_PASSWORD ?>" autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Save</button>
                    <a href="admin_management.php" class="btn btn-secondary ms-2">Cancel</a>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$edit && !$permTarget && roles_creatable_by($myRole)): ?>
            <!-- Add admin -->
            <div class="panel">
                <h4><i class="fas fa-user-plus me-2"></i>Add Admin</h4>
                <?php if (!$creatable): ?>
                    <p class="note m-0">Every role you can create already has an admin (one account per role). Remove one first to free its role.</p>
                <?php else: ?>
                    <form method="POST" action="admin_management.php">
                        <input type="hidden" name="action" value="add_admin">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Role (only free roles are listed)</label>
                                <select name="role" class="form-select" required>
                                    <?php foreach ($creatable as $r): ?>
                                        <option value="<?= $r ?>"><?= htmlspecialchars(role_label($r)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Password (min <?= AUTH_MIN_PASSWORD ?>)</label>
                                <input type="password" name="password" class="form-control" required minlength="<?= AUTH_MIN_PASSWORD ?>" autocomplete="new-password">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Confirm password</label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="<?= AUTH_MIN_PASSWORD ?>" autocomplete="new-password">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success"><i class="fas fa-plus-circle me-2"></i>Create Admin</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Admin list -->
        <div class="panel">
            <h4><i class="fas fa-list me-2"></i>Admins</h4>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Admin</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Powers</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $a):
                        $self = (int) $a['id'] === $myId;
                        $canEdit = $self ? $iAmSuper : can_edit_role($myRole, $a['role']);
                        $canRemove = !$self && can_remove_role($myRole, $a['role']);
                        $canPerms = can_toggle_permissions_for($myRole, $a['role']); ?>
                        <tr>
                            <td><b><?= htmlspecialchars($a['username']) ?></b><?= $self ? ' <small class="note">(you)</small>' : '' ?><br><small class="note"><?= htmlspecialchars($a['email']) ?></small></td>
                            <td><span class="role-pill <?= $a['role'] ? $roleTone[$a['role']] : 'none' ?>"><?= htmlspecialchars(role_label($a['role'])) ?></span></td>
                            <td><span class="status-pill <?= $a['status'] === 'active' ? 'on' : 'off' ?>"><?= $a['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
                            <td class="perm-list">
                                <?php if ($a['role'] === 'super_admin'): ?>Everything
                                <?php elseif (!$a['role']): ?>None until a role is assigned
                                <?php else: ?>Shortlist<?= $a['perms'] ? ', ' . htmlspecialchars(implode(', ', array_map(fn($p) => PERMISSIONS[$p]['label'], $a['perms']))) : '' ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="act">
                                    <?php if ($canEdit): ?>
                                        <a href="admin_management.php?edit_id=<?= (int) $a['id'] ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php endif; ?>
                                    <?php if ($canPerms): ?>
                                        <a href="admin_management.php?perms_id=<?= (int) $a['id'] ?>#permissions" class="btn btn-sm btn-info" title="Permissions"><i class="fas fa-sliders-h"></i></a>
                                    <?php endif; ?>
                                    <?php if ($canRemove): ?>
                                        <form method="POST" action="admin_management.php">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                                            <?php if ($a['status'] === 'active'): ?>
                                                <button type="submit" class="btn btn-sm btn-warning" title="Deactivate (revoke powers)"><i class="fas fa-user-slash"></i></button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-sm btn-success" title="Activate"><i class="fas fa-user-check"></i></button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="POST" action="admin_management.php" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Remove ' . $a['username'] . ' (' . role_label($a['role']) . ')? This deletes the account.'), ENT_QUOTES) ?>);">
                                            <input type="hidden" name="action" value="delete_admin">
                                            <input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Remove"><i class="fas fa-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="note mt-3 mb-0">One admin per role. You can remove or deactivate only admins ranked below you<?= can('manage_permissions') ? ', and set permissions for Secretary Admins (HR, MIAP and Secretary)' : '' ?>.</p>
        </div>
    </div>
</body>

</html>
