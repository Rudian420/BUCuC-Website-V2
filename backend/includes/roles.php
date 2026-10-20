<?php
/**
 * Admin roles and permissions (loaded by auth.php).
 *
 * Five roles, one account each, highest first. A role's powers come from:
 *   - fixed powers (ROLE_FIXED): never toggled, e.g. shortlisting, managing admins, lockdown
 *   - grantable permissions (PERMISSIONS): role defaults (ROLE_DEFAULTS), which a Panel Admin or
 *     the Super Admin can tick on/off per account for HR, MIAP and Secretary Admin
 *     (stored in admin_permissions as overrides).
 * The Super Admin can do everything. Every check runs on the server: use can() / require_can().
 */

const ROLES = [
    'super_admin'     => ['rank' => 1, 'label' => 'Super Admin'],
    'panel_admin'     => ['rank' => 2, 'label' => 'Panel Admin'],
    'hr_admin'        => ['rank' => 3, 'label' => 'Secretary Admin (HR)'],
    'miap_admin'      => ['rank' => 4, 'label' => 'Secretary Admin (MIAP)'],
    'secretary_admin' => ['rank' => 5, 'label' => 'Secretary Admin'],
];

/** Grantable permissions (the ones with on/off ticks). */
const PERMISSIONS = [
    'final_approve'   => ['label' => 'Final approval',                 'help' => 'Final Approve / reject shortlisted members, set the orientation venue'],
    'view_members'    => ['label' => 'View member lists',              'help' => 'GB, ASB, Secretary Panel and approved members; Excel export of approved members'],
    'promote_gb_asb'  => ['label' => 'Promote / demote GB ↔ ASB',      'help' => 'Move members GB → ASB and ASB → GB (includes viewing the lists)'],
    'promote_sp'      => ['label' => 'Promote / demote Secretary Panel', 'help' => 'Move members ASB → Secretary Panel and back (includes viewing the lists)'],
    'upload_carousel' => ['label' => 'Upload Secretary Panel photos', 'help' => 'Add, change or remove secretaries (homepage carousel) in any tenure; create tenures'],
    'upload_panel'    => ['label' => 'Upload Presidential Panel photos', 'help' => 'Add, change or remove Presidential Panel members in any tenure; create tenures'],
    'upload_events'   => ['label' => 'Upload past event photos',       'help' => 'Add or remove past events with photo and details'],
    'upload_hero'     => ['label' => 'Upload hero video',              'help' => 'Replace the homepage video'],
    'send_notices'    => ['label' => 'Send email notices',             'help' => 'E-mail all members, a group (GB / ASB / Secretary Panel) or picked members'],
    'manage_gallery'  => ['label' => 'Manage the performance gallery', 'help' => 'Add, edit, hide, reorder and delete YouTube videos on the public Performances page'],
    'handover_contacts' => ['label' => 'See handover contacts',      'help' => 'Read and edit the Contacts section of the handover notes (sponsors, vendors, venues, phone numbers)'],
    'take_attendance' => ['label' => 'Take attendance',                'help' => 'Create attendance sessions, mark members, run QR check-in, and see attendance reports (also the percentages on the member pages)'],
];

const ROLE_DEFAULTS = [
    'panel_admin'     => ['final_approve', 'view_members', 'promote_gb_asb', 'promote_sp', 'upload_carousel', 'upload_panel', 'upload_events', 'upload_hero', 'send_notices', 'manage_gallery', 'take_attendance', 'handover_contacts'],
    'hr_admin'        => ['final_approve', 'view_members', 'promote_gb_asb', 'upload_carousel', 'upload_panel', 'upload_events', 'upload_hero', 'send_notices', 'manage_gallery', 'take_attendance', 'handover_contacts'],
    'miap_admin'      => ['upload_carousel', 'upload_panel', 'upload_events', 'upload_hero', 'manage_gallery'],
    'secretary_admin' => [],
];

/**
 * Fixed powers.
 *   shortlist           shortlist / reject pending applications, export pending & shortlisted
 *   manage_site         signup on/off, dashboard numbers, clear lists, delete approved members, remove board members
 *   admin_management    open Admin Management (what they can do there: see the functions below)
 *   manage_permissions  tick permissions for HR / MIAP / Secretary Admin
 *   lockdown            schedule / cancel / lift a site lockdown
 *   home_stats          homepage numbers and labels, show/hide (top 3 admins)
 *   theme               site colour palette (top 3 admins)
 */
const ROLE_FIXED = [
    'panel_admin'     => ['shortlist', 'manage_site', 'admin_management', 'manage_permissions', 'home_stats', 'theme'],
    'hr_admin'        => ['shortlist', 'admin_management', 'home_stats', 'theme'],
    'miap_admin'      => ['shortlist'],
    'secretary_admin' => ['shortlist'],
];

/** Roles whose grantable permissions can be toggled. */
const TOGGLEABLE_ROLES = ['hr_admin', 'miap_admin', 'secretary_admin'];

/** Maps pre-2026 role values so old rows keep working until upgrade.sql runs. */
function role_normalize($role)
{
    if ($role === 'main_admin') return 'super_admin';
    if ($role === 'admin') return 'secretary_admin';
    return isset(ROLES[$role]) ? $role : null;
}

function role_label($role)
{
    $role = role_normalize($role);
    return $role ? ROLES[$role]['label'] : 'No role';
}

function role_rank($role)
{
    $role = role_normalize($role);
    return $role ? ROLES[$role]['rank'] : 99;
}

/** Roles an admin of $actorRole may create (only vacant ones are offered). */
function roles_creatable_by($actorRole)
{
    $actorRole = role_normalize($actorRole);
    if ($actorRole === 'super_admin') return array_keys(ROLES);
    if ($actorRole === 'panel_admin') return ['hr_admin', 'miap_admin', 'secretary_admin'];
    return [];
}

/** May $actorRole remove / deactivate an admin holding $targetRole? Only strictly lower ranks. */
function can_remove_role($actorRole, $targetRole)
{
    $actorRole = role_normalize($actorRole);
    $targetRole = role_normalize($targetRole);
    if ($actorRole === 'super_admin') return $targetRole !== 'super_admin';
    if ($actorRole === 'panel_admin') return in_array($targetRole, ['hr_admin', 'miap_admin', 'secretary_admin', null], true);
    if ($actorRole === 'hr_admin') return in_array($targetRole, ['miap_admin', 'secretary_admin'], true);
    return false;
}

/** May $actorRole edit name / e-mail / password / role of an admin holding $targetRole? */
function can_edit_role($actorRole, $targetRole)
{
    $actorRole = role_normalize($actorRole);
    $targetRole = role_normalize($targetRole);
    if ($actorRole === 'super_admin') return true;
    return $actorRole === 'panel_admin' && in_array($targetRole, ['hr_admin', 'miap_admin', 'secretary_admin', null], true);
}

/** May $actorRole tick permissions for an admin holding $targetRole? */
function can_toggle_permissions_for($actorRole, $targetRole)
{
    $actorRole = role_normalize($actorRole);
    return in_array($actorRole, ['super_admin', 'panel_admin'], true)
        && in_array(role_normalize($targetRole), TOGGLEABLE_ROLES, true);
}

/** Effective grantable permissions of one admin: role defaults + per-account overrides. */
function permissions_for_admin(PDO $pdo, $adminId, $role)
{
    $role = role_normalize($role);
    if ($role === 'super_admin') return array_keys(PERMISSIONS);
    $perms = array_fill_keys(ROLE_DEFAULTS[$role] ?? [], true);
    if (in_array($role, TOGGLEABLE_ROLES, true)) {
        site_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT permission, allowed FROM admin_permissions WHERE admin_id = ?");
        $stmt->execute([$adminId]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$perm, $allowed]) {
            if (!isset(PERMISSIONS[$perm])) continue;
            if ($allowed) $perms[$perm] = true; else unset($perms[$perm]);
        }
    }
    return array_keys($perms);
}

/** Does the signed-in admin have this permission or fixed power? */
function can($perm)
{
    $role = role_normalize($_SESSION['admin_role'] ?? null);
    if ($role === null) return false;
    if ($role === 'super_admin') return true;
    if (in_array($perm, ROLE_FIXED[$role] ?? [], true)) return true;
    $granted = $GLOBALS['AUTH_PERMISSIONS'] ?? [];
    if ($perm === 'view_members') {
        // Moving members needs the lists, so either promote permission includes viewing them
        return (bool) array_intersect(['view_members', 'promote_gb_asb', 'promote_sp'], $granted);
    }
    return in_array($perm, $granted, true);
}

/** True if the admin has at least one of these. */
function can_any(array $perms)
{
    foreach ($perms as $p) {
        if (can($p)) return true;
    }
    return false;
}

/** Stops the request unless the signed-in admin has the permission. */
function require_can($perm, $json = false)
{
    if (is_array($perm) ? can_any($perm) : can($perm)) {
        return;
    }
    auth_deny($json, 403, "You don't have permission to do this.",
        auth_frontend_url(auth_dashboard_page()) . '?error=' . urlencode("You don't have permission to open that page."));
}

/**
 * How an admin's name is shown to the signed-in admin. The Super Admin is never named to anyone
 * else: they see "an admin". Pass the role stored with the record; for older records without a
 * role, pass the admin id and the role is looked up.
 */
function admin_shown_name($name, $role = null, $adminId = null)
{
    if ($role === null && $adminId) {
        static $roles = [];
        if (!array_key_exists($adminId, $roles)) {
            try {
                $st = db()->prepare("SELECT role FROM adminpanel WHERE id = ?");
                $st->execute([(int) $adminId]);
                $roles[$adminId] = $st->fetchColumn() ?: null;
            } catch (\Throwable $e) {
                $roles[$adminId] = null;
            }
        }
        $role = $roles[$adminId];
    }
    if (role_normalize($role) === 'super_admin' && !auth_is_super()) return 'an admin';
    return ($name !== null && $name !== '') ? $name : 'an admin';
}

/** Dashboard page for the signed-in admin. */
function auth_dashboard_page()
{
    return auth_is_super() ? 'super_admin_dashboard.php' : 'admin_dashboard.php';
}
