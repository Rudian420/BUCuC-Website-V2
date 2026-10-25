<?php
/**
 * The admin menu: one list of links (filtered by the signed-in admin's permissions) used by the
 * dashboard sidebar and by the "Menu" bar that csrf_autoinject() adds to every other admin page.
 * Add a new admin page here once and it appears in both places.
 * A page can leave the bar out with define('NO_ADMIN_MENU', true) (the dashboard has its own
 * sidebar; the print and projector pages show nothing but their content).
 */

/** [section title => [[label, page], ...]] for the current admin. */
function admin_menu_sections()
{
    $dash = auth_dashboard_page();
    $s = [
        'Dashboard' => [['Overview', $dash]],
        'Management' => [],
        'Settings' => [],
    ];
    if (can('view_members')) $s['Management'][] = ['Members', 'member_types.php'];
    $s['Management'][] = ['Applications', 'pending_applications.php'];
    $s['Management'][] = ['Shortlisted Members', 'shortlisted_members.php'];
    if (can('view_members')) $s['Management'][] = ['Approved Members', 'approved_members.php'];
    if (can_any(['upload_carousel', 'upload_panel', 'upload_events', 'upload_hero'])) $s['Management'][] = ['Website Media', 'media.php'];
    if (can('take_attendance')) $s['Management'][] = ['Attendance', 'attendance.php'];
    if (can_handover()) $s['Management'][] = ['Handover Notes', 'handover.php'];
    if (auth_is_super()) $s['Management'][] = ['Sponsor Banners', 'sponsors.php'];   // exists only for the Super Admin
    if (can('manage_gallery')) $s['Management'][] = ['Performance Gallery', 'video_gallery.php'];
    if (can('manage_site')) $s['Management'][] = ['Dashboard Updates', 'dashboard_updates.php'];

    if (can('manage_site')) $s['Settings'][] = ['Signup Control', 'admin-signup-control.php'];
    if (can('home_stats')) $s['Settings'][] = ['Homepage Stats', 'home_stats.php'];
    if (can('theme')) $s['Settings'][] = ['Site Colours', 'theme.php'];
    if (can('send_notices')) $s['Settings'][] = ['Email Notices', 'notices.php'];
    $s['Settings'][] = ['My Profile', 'profile.php'];
    if (can('admin_management')) $s['Settings'][] = ['Admin Management', 'admin_management.php'];
    $s['Settings'][] = ['Activity Log', 'activity_log.php'];
    if (can('lockdown')) $s['Settings'][] = ['Site Lockdown', 'lockdown.php'];
    $s['Settings'][] = ['Logout', 'logout.php'];
    return $s;
}

/** The page a menu link counts as "current" for (sub-pages belong to their section). */
function admin_menu_current()
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $parents = [
        'gb_members.php' => 'member_types.php', 'sb_members.php' => 'member_types.php', 'asb_panel.php' => 'member_types.php',
        'attendance_session.php' => 'attendance.php', 'attendance_member.php' => 'attendance.php', 'attendance_checkin.php' => 'attendance.php',
        'set_venue.php' => 'shortlisted_members.php',
    ];
    return $parents[$page] ?? $page;
}

/** The "Menu" bar and slide-out menu for admin pages (behaviour in js/ui.js, look in css/admin-ui.css). */
function admin_menu_html()
{
    $h = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES);
    $base = auth_frontend_url('');
    $cur = admin_menu_current();
    $links = '';
    foreach (admin_menu_sections() as $title => $items) {
        if (!$items) continue;
        $links .= '<div class="am-section">' . $h($title) . '</div>';
        foreach ($items as [$label, $page]) {
            $on = $page === $cur;
            $links .= '<a class="am-link' . ($on ? ' on' : '') . '" href="' . $h($base . $page) . '"' . ($on ? ' aria-current="page"' : '') . '>' . $h($label) . '</a>';
        }
    }
    return '<div class="am-bar"><button type="button" class="am-btn" data-drawer-open="amDrawer" aria-controls="amDrawer" aria-expanded="false">'
        . '<span class="am-burger" aria-hidden="true"><i></i><i></i><i></i></span>Menu</button>'
        . '<a class="am-home" href="' . $h($base . auth_dashboard_page()) . '">BUCuC Admin</a></div>'
        . '<div class="am-overlay" data-drawer-close="amDrawer" hidden></div>'
        . '<nav id="amDrawer" class="am-drawer" aria-label="Admin menu" data-drawer inert>'
        . '<button type="button" class="am-close" data-drawer-close="amDrawer" aria-label="Close menu">&times;</button>'
        . $links . '</nav>';
}
