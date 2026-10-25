<?php
require_once '../backend/includes/auth.php';

// Strict check for Main Admin
require_admin(['super' => true]);

require_once '../backend/Database/db.php';
require_once '../backend/includes/dashboard_stats.php';

$database = new Database();
$dashboardData = getDashboardStats($database->createConnection());
$isSuper = true;

require __DIR__ . '/includes/dashboard_view.php';
