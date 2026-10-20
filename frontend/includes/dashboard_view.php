<?php
/**
 * Shared dashboard markup for admin_dashboard.php and super_admin_dashboard.php.
 * Expects: $isSuper (bool), $dashboardData (from getDashboardStats()).
 */
$username = $_SESSION['username'] ?? 'Admin';
$firstName = explode(' ', $username)[0];
$pendingCount = $dashboardData['pending_applications'];

// Handover notes: which sections of the current tenure are still empty
require_once __DIR__ . '/../../backend/includes/handover.php';
$handoverDoc = null;
$handoverEmpty = [];
try {
    $handoverDoc = handover_current_doc(db());
    if ($handoverDoc) $handoverEmpty = handover_empty_sections(db(), $handoverDoc);
} catch (\Throwable $e) {
    error_log('dashboard handover: ' . $e->getMessage());
}
// The handover reminder e-mail (about a month before the tenure ends) goes out from here when due,
// after the page has been delivered, so nobody waits for it. backend/scripts/handover_reminders.php does the same from cron.
register_shutdown_function(function () {
    while (ob_get_level() > 0) ob_end_flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    try { handover_send_due_reminders(db()); } catch (\Throwable $e) { error_log('handover reminder: ' . $e->getMessage()); }
});
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isSuper ? 'Super Admin Dashboard' : 'Admin Dashboard' ?> - BRAC University Cultural Club</title>

    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/bootstrap-icons.css" rel="stylesheet">
    <link href="css/templatemo-festava-live.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <link rel="icon" type="image/png" href="images/logopng.png">
    <link rel="stylesheet" href="css/aurora.css">
    <style>
        .handover-card { display: flex; gap: 1rem; align-items: center; margin: 0 0 1.5rem; padding: 1rem 1.25rem; border-radius: 18px; text-decoration: none; color: inherit;
            background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); }
        .handover-card.has-empty { border-color: rgb(var(--c-highlight, 255 207 125) / 0.55); }
        .handover-card > i { font-size: 1.6rem; color: rgb(var(--c-highlight, 255 207 125)); }
        .handover-card b { display: block; }
        .handover-card span { font-size: .88rem; color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .handover-card:hover { color: inherit; border-color: rgb(var(--c-accent, 45 226 230) / 0.6); }
    </style>
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <!-- Mobile Toggle Button -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Open menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="images/logo.png" alt="BUCuC Logo">
                <h3>BUCuC</h3>
            </div>
            <p class="sidebar-subtitle"><?= htmlspecialchars(role_label($_SESSION['admin_role'] ?? null)) ?></p>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <a href="<?= $isSuper ? 'super_admin_dashboard.php' : 'admin_dashboard.php' ?>" class="nav-item active">Overview</a>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Management</div>
                <?php if (can('view_members')): ?>
                    <a href="member_types.php" class="nav-item">Members</a>
                <?php endif; ?>
                <a href="pending_applications.php" class="nav-item">
                    Applications
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-warning ms-2"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="shortlisted_members.php" class="nav-item">Shortlisted Members</a>
                <?php if (can('view_members')): ?>
                    <a href="approved_members.php" class="nav-item">Approved Members</a>
                <?php endif; ?>
                <?php if (can_any(['upload_carousel', 'upload_panel', 'upload_events', 'upload_hero'])): ?>
                    <a href="media.php" class="nav-item">Website Media</a>
                <?php endif; ?>
                <?php if (can('take_attendance')): ?>
                    <a href="attendance.php" class="nav-item">Attendance</a>
                <?php endif; ?>
                <a href="handover.php" class="nav-item">Handover Notes</a>
                <?php if (auth_is_super()): /* sponsor banners exist only for the Super Admin */ ?>
                    <a href="sponsors.php" class="nav-item">Sponsor Banners</a>
                <?php endif; ?>
                <?php if (can('manage_gallery')): ?>
                    <a href="video_gallery.php" class="nav-item">Performance Gallery</a>
                <?php endif; ?>
                <?php if (can('manage_site')): ?>
                    <a href="dashboard_updates.php" class="nav-item">Dashboard Updates</a>
                <?php endif; ?>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Settings</div>
                <?php if (can('manage_site')): ?>
                    <a href="admin-signup-control.php" class="nav-item">Signup Control</a>
                <?php endif; ?>
                <?php if (can('home_stats')): ?>
                    <a href="home_stats.php" class="nav-item">Homepage Stats</a>
                <?php endif; ?>
                <?php if (can('theme')): ?>
                    <a href="theme.php" class="nav-item">Site Colours</a>
                <?php endif; ?>
                <?php if (can('send_notices')): ?>
                    <a href="notices.php" class="nav-item">Email Notices</a>
                <?php endif; ?>
                <a href="profile.php" class="nav-item">My Profile</a>
                <?php if (can('admin_management')): ?>
                    <a href="admin_management.php" class="nav-item">Admin Management</a>
                <?php endif; ?>
                <a href="activity_log.php" class="nav-item">Activity Log</a>
                <?php if (can('lockdown')): ?>
                    <a href="lockdown.php" class="nav-item">Site Lockdown</a>
                <?php endif; ?>
                <a href="logout.php" class="nav-item">Logout</a>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="dashboard-header">
            <div class="header-left">
                <h1><?= $isSuper ? 'Super Admin Dashboard' : 'Cultural Club Dashboard' ?></h1>
                <p>Welcome back, <?= htmlspecialchars($username) ?>! Here's what's happening with your club.</p>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mt-2" role="alert"
                        style="background: rgb(var(--c-accent2-28a745, 40 167 69) / 0.2); border: 1px solid rgb(var(--c-accent2-28a745, 40 167 69) / 0.3); color: rgb(var(--c-accent2-51cf66, 81 207 102));">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_GET['success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" style="filter: brightness(0) invert(1);"></button>
                    </div>
                <?php endif; ?>
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show mt-2" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($_GET['error']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="header-right">
                <a href="profile.php" class="user-profile" style="text-decoration: none; color: inherit;">
                    <div class="user-avatar"><?= htmlspecialchars(strtoupper(substr($username, 0, 1))) ?></div>
                    <span><?= htmlspecialchars($firstName) ?></span>
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?= $dashboardData['total_members'] ?></div>
                <div class="stat-label">Total Members</div>
            </div>

            <a href="pending_applications.php" class="stat-card" style="text-decoration: none;">
                <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
                <div class="stat-number"><?= $pendingCount ?></div>
                <div class="stat-label">Pending Applications</div>
                <div class="stat-change <?= $pendingCount > 0 ? '' : 'positive' ?>" style="<?= $pendingCount > 0 ? 'color: rgb(var(--c-highlight-ffc107, 255 193 7));' : '' ?>">
                    <?php if ($pendingCount > 0): ?>
                        <i class="fas fa-clock"></i> Needs Attention
                    <?php else: ?>
                        <i class="fas fa-check"></i> All Reviewed
                    <?php endif; ?>
                </div>
            </a>

            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-number"><?= $dashboardData['completed_events'] ?></div>
                <div class="stat-label">Completed Events</div>
                <div class="stat-change positive"><i class="fas fa-trophy"></i> Successfully Organized</div>
            </div>

            <a href="shortlisted_members.php" class="stat-card" style="text-decoration: none;">
                <div class="stat-icon"><i class="fas fa-list-ul"></i></div>
                <div class="stat-number"><?= $dashboardData['shortlisted'] ?></div>
                <div class="stat-label">Shortlisted Members</div>
                <div class="stat-change positive"><i class="fas fa-clock"></i> Awaiting Final Approval</div>
            </a>
        </div>

        <?php if ($handoverDoc): ?>
            <!-- Handover notes of the current tenure -->
            <a href="handover.php" class="handover-card<?= $handoverEmpty ? ' has-empty' : '' ?>">
                <i class="fas fa-handshake"></i>
                <div>
                    <b>Handover notes · Tenure <?= htmlspecialchars($handoverDoc['tenure_label']) ?></b>
                    <span><?= $handoverEmpty ? count($handoverEmpty) . ' section' . (count($handoverEmpty) === 1 ? ' is' : 's are') . ' still empty: ' . htmlspecialchars(implode(', ', $handoverEmpty)) : 'Every section has something in it. Keep them up to date for the next panel.' ?><?= $handoverDoc['ends_on'] ? ' · tenure ends ' . date('j M Y', strtotime($handoverDoc['ends_on'])) : '' ?></span>
                </div>
            </a>
        <?php endif; ?>

        <!-- Charts Section -->
        <div class="charts-section">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Member Growth</h3>
                </div>
                <div style="position: relative; height: 420px; width: 100%;">
                    <canvas id="memberGrowthChart"></canvas>
                    <p class="chart-empty text-center text-muted" id="memberGrowthEmpty" style="display: none; padding-top: 160px;">
                        No growth data yet.<?= can('manage_site') ? ' Add points in <a href="dashboard_updates.php">Dashboard Updates</a>.' : '' ?>
                    </p>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Gender Distribution</h3>
                </div>
                <div style="position: relative; height: 420px; width: 100%;">
                    <canvas id="genderDistributionChart"></canvas>
                    <p class="chart-empty text-center text-muted" id="genderEmpty" style="display: none; padding-top: 160px;">
                        No gender data yet.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.min.js"></script>
    <script>
        window.dashboardStats = <?= json_encode([
            'memberGrowth' => $dashboardData['member_growth'],
            'gender'       => $dashboardData['gender'],
        ]) ?>;
    </script>
    <script src="js/dashboard-charts.js"></script>
</body>

</html>
