<?php
/**
 * Sponsor banner click (named go.php, not ad.php, so ad blockers leave it alone): counts the click (once per visitor per ad per 10 minutes, no bots or admins),
 * then sends the visitor to the advertiser. The target always comes from the database, never from
 * the link, so this can't be used to redirect anywhere else.
 */
require_once __DIR__ . '/../backend/includes/auth.php';
require_once __DIR__ . '/../backend/includes/ads.php';
auth_start_session();

header('X-Robots-Tag: noindex, nofollow');
site_send_never_cache_headers();
try {
    $ad = ad_click(db(), $_GET['id'] ?? 0);
} catch (\Throwable $e) {
    error_log('ad.php: ' . $e->getMessage());
    $ad = null;
}
$target = ($ad && preg_match('~^https?://~i', $ad['link'])) ? $ad['link'] : 'index.php';
header('Referrer-Policy: origin');
header('Location: ' . $target, true, 302);
exit();
