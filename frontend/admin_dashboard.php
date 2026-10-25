<?php
require_once '../backend/includes/auth.php';
require_admin();

// Redirect Super Admins to their dedicated dashboard
if (auth_is_super()) {
    header("Location: super_admin_dashboard.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

require_once '../backend/Database/db.php';
require_once '../backend/includes/dashboard_stats.php';

$database = new Database();
$dashboardData = getDashboardStats($database->createConnection());
$isSuper = false;

require __DIR__ . '/includes/dashboard_view.php';
