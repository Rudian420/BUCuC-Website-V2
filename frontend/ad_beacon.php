<?php
/**
 * Ad view beacon endpoint.
 * Accepts background navigator.sendBeacon() or keepalive fetch() from visitors' browsers.
 * Buffers views and writes them in batches without blocking page rendering.
 */
require_once __DIR__ . '/../backend/includes/auth.php';
require_once __DIR__ . '/../backend/includes/ads.php';
auth_start_session();

header('Content-Type: application/json');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, nofollow');

// Check if request is from a bot or admin
if (ads_is_bot() || !empty($_SESSION['admin'])) {
    http_response_code(204);
    exit();
}

$adId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($adId > 0) {
    // Only queue if not already counted during this page load
    $last = $_SESSION['ad_view_' . $adId] ?? 0;
    if (time() - $last > 60) {
        $_SESSION['ad_view_' . $adId] = time();
        ad_views_queue($adId);
    }
    http_response_code(204);
    exit();
}

http_response_code(400);
echo json_encode(['ok' => false]);
