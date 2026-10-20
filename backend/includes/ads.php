<?php
/**
 * Sponsor ads (Super Admin only). For every other admin this feature does not exist: the pages
 * (frontend/sponsors.php, sponsors_export.php) answer 404 (ads_require_super()), there is no menu
 * item, and nothing about it appears in their pages, scripts or the activity log. Clicks go through
 * frontend/go.php. Names avoid "ad" (ad blockers hide ad.php, /ads/, .ad-slot).
 *
 * ad_spots   on/off per spot          ads   one banner (desktop + optional phone image, link, dates)
 * ad_stats   views and clicks per ad per day (Asia/Dhaka)
 * ad_clicks  recent clicks (IP hash) so one visitor clicking again within 10 minutes counts once
 */

require_once __DIR__ . '/site.php';

/** Spots on the public site: size and ratio of the desktop and phone images. */
const AD_SPOTS = [
    'home_hero'   => ['label' => 'Homepage: below the hero', 'desktop' => [1200, 200], 'mobile' => [600, 300]],
    'home_middle' => ['label' => 'Homepage: between Secretaries and Past Events', 'desktop' => [1200, 300], 'mobile' => [600, 400]],
    'home_footer' => ['label' => 'Homepage: above the footer', 'desktop' => [1200, 200], 'mobile' => [600, 300]],
    'events'      => ['label' => 'Events page (appears once the events calendar exists)', 'desktop' => [1200, 200], 'mobile' => [600, 300], 'future' => true],
];
const AD_MAX_BYTES = 1024 * 1024;     // ad images must be compressed: at most 1 MB (the crop tool re-saves bigger ones)
const AD_CLICK_DEDUPE_MINUTES = 10;
// Link previews, crawlers, monitoring and scripts: not real visitors
const AD_BOT_RE = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|telegram|discord|linkedin|headless|lighthouse|pagespeed|curl|wget|python|httpclient|okhttp|go-http|java\/|libwww|scrapy|^$/i';

function ads_ensure(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_spots (
        spot VARCHAR(20) NOT NULL PRIMARY KEY, enabled TINYINT(1) NOT NULL DEFAULT 0, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ads (
        id INT AUTO_INCREMENT PRIMARY KEY, spot VARCHAR(20) NOT NULL, advertiser VARCHAR(120) NOT NULL, link VARCHAR(500) NOT NULL,
        image VARCHAR(255) NOT NULL, image_mobile VARCHAR(255) NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, KEY idx_ads_live (spot, active, starts_on, ends_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_stats (
        ad_id INT NOT NULL, day DATE NOT NULL, views INT NOT NULL DEFAULT 0, clicks INT NOT NULL DEFAULT 0, PRIMARY KEY (ad_id, day),
        CONSTRAINT fk_ad_stats_ad FOREIGN KEY (ad_id) REFERENCES ads (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_clicks (
        id INT AUTO_INCREMENT PRIMARY KEY, ad_id INT NOT NULL, ip_hash CHAR(16) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ad_clicks (ad_id, ip_hash, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ins = $pdo->prepare("INSERT IGNORE INTO ad_spots (spot, enabled) VALUES (?, 0)");
    foreach (array_keys(AD_SPOTS) as $s) $ins->execute([$s]);
}

/**
 * For the ads pages: anyone who isn't the signed-in Super Admin gets the server's normal 404, as if
 * the page didn't exist (no login redirect, no "access denied").
 */
function ads_require_super($json = false)
{
    auth_start_session();
    if (empty($_SESSION['admin']) || role_normalize($_SESSION['admin_role'] ?? null) !== 'super_admin') ads_not_found();
    require_admin(['super' => true, 'json' => $json, 'inject' => !$json]);   // full account checks (active, password, role)
    if (!auth_is_super()) ads_not_found();
}

function ads_not_found()
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/html; charset=iso-8859-1');
    $uri = htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), ENT_QUOTES);
    echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>404 Not Found</title>\n</head><body>\n<h1>Not Found</h1>\n<p>The requested URL $uri was not found on this server.</p>\n</body></html>\n";
    exit();
}

/** The image rules for the crop tool on the ads page (same shape as media_rules_js()). */
function ads_rules_js()
{
    $rules = [];
    foreach (AD_SPOTS as $k => $s) {
        foreach (['desktop', 'mobile'] as $v) {
            [$w, $h] = $s[$v];
            $g = (function ($a, $b) { while ($b) [$a, $b] = [$b, $a % $b]; return $a; })($w, $h);
            $rules["ad_{$k}_$v"] = ['ratio' => [$w / $g, $h / $g], 'max' => [$w, $h], 'warn' => [(int) round($w * 0.6), (int) round($h * 0.6)], 'label' => ($v === 'desktop' ? 'Desktop' : 'Phone') . " banner, at most $w × $h px"];
        }
    }
    return ['tolerance' => MEDIA_RATIO_TOLERANCE, 'maxBytes' => AD_MAX_BYTES, 'rules' => $rules];
}

/** Server check of an uploaded ad image: real type, ratio, maximum size, at most 1 MB. */
function ads_check_image(array $file, $spot, $variant)
{
    [$w0, $h0] = AD_SPOTS[$spot][$variant];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [false, upload_error_message($file['error'] ?? UPLOAD_ERR_NO_FILE), null];
    if ($file['size'] > AD_MAX_BYTES) return [false, 'Ad images must be at most 1 MB (the crop tool compresses them).', null];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset(MEDIA_IMAGE_TYPES[$info[2]])) return [false, 'Upload a JPG, PNG or WebP image.', null];
    [$w, $h] = $info;
    if (abs($w / $h - $w0 / $h0) / ($w0 / $h0) > MEDIA_RATIO_TOLERANCE) return [false, "This image is $w × $h px; this spot needs the ratio of $w0 × $h0. Crop it and try again.", null];
    if ($w > $w0 || $h > $h0) return [false, "This image is $w × $h px, bigger than $w0 × $h0. Resize it and try again.", null];
    return [true, '', MEDIA_IMAGE_TYPES[$info[2]]];
}

/** Today in Dhaka (PHP runs on Asia/Dhaka, db.php). */
function ads_today()
{
    return date('Y-m-d');
}

function ad_status(array $ad)
{
    $t = ads_today();
    if (!$ad['active']) return ['paused', 'Paused'];
    if ($t < $ad['starts_on']) return ['scheduled', 'Starts ' . date('j M Y', strtotime($ad['starts_on']))];
    if ($t > $ad['ends_on']) return ['ended', 'Ended ' . date('j M Y', strtotime($ad['ends_on']))];
    return ['live', 'Live until ' . date('j M Y', strtotime($ad['ends_on']))];
}

function ads_is_bot()
{
    return (bool) preg_match(AD_BOT_RE, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/* ------------------------------------------------------------------ public side */

/**
 * The banner for one spot, or '' when the spot is off or has no live ad (nothing at all is printed,
 * so no gap). Several live ads in one spot rotate at random on each page load. Counts one view.
 */
function ad_slot($spot)
{
    try {
        $pdo = db();
        ads_ensure($pdo);
        $on = $pdo->prepare("SELECT enabled FROM ad_spots WHERE spot = ?");
        $on->execute([$spot]);
        if (!(int) $on->fetchColumn() || !empty(AD_SPOTS[$spot]['future'])) return '';
        $q = $pdo->prepare("SELECT * FROM ads WHERE spot = ? AND active = 1 AND starts_on <= ? AND ends_on >= ?");
        $q->execute([$spot, ads_today(), ads_today()]);
        $live = $q->fetchAll();
        if (!$live) return '';
        $ad = $live[random_int(0, count($live) - 1)];
        // a view = this page load, by a real visitor (not a bot, not an admin previewing the site)
        if (!ads_is_bot() && empty($_SESSION['admin'])) {
            $pdo->prepare("INSERT INTO ad_stats (ad_id, day, views) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1")->execute([$ad['id'], ads_today()]);
        }
    } catch (\Throwable $e) {
        error_log('ad_slot: ' . $e->getMessage());
        return '';
    }
    [$w, $h] = AD_SPOTS[$spot]['desktop'];
    [$mw, $mh] = AD_SPOTS[$spot]['mobile'];
    $h_ = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    $mobile = $ad['image_mobile'] ? '<source media="(max-width: 600px)" srcset="' . $h_($ad['image_mobile']) . '" width="' . $mw . '" height="' . $mh . '">' : '';
    // Neutral names (sp-band, go.php, images/sponsors/): ad blockers hide anything called "ad-slot", "ad.php" or "/ads/"
    return '<aside class="sp-band sp-' . $h_($spot) . '" aria-label="Sponsor">'
        . '<a href="go.php?id=' . (int) $ad['id'] . '" target="_blank" rel="sponsored noopener" title="' . $h_($ad['advertiser']) . '">'
        . '<span class="sp-tag">Sponsored</span>'
        . '<picture>' . $mobile . '<img src="' . $h_($ad['image']) . '" width="' . $w . '" height="' . $h . '" alt="' . $h_($ad['advertiser']) . '" loading="lazy" decoding="async"></picture>'
        . '</a></aside>';
}

/** Records a click (once per visitor per ad per 10 minutes, no bots). Returns the ad or null. */
function ad_click(PDO $pdo, $id)
{
    ads_ensure($pdo);
    $st = $pdo->prepare("SELECT * FROM ads WHERE id = ?");
    $st->execute([(int) $id]);
    $ad = $st->fetch();
    if (!$ad) return null;
    if (ad_status($ad)[0] === 'live' && !ads_is_bot() && empty($_SESSION['admin'])) {
        $ip = substr(hash('sha256', 'adclick|' . auth_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
        $seen = $pdo->prepare("SELECT COUNT(*) FROM ad_clicks WHERE ad_id = ? AND ip_hash = ? AND created_at > (NOW() - INTERVAL " . AD_CLICK_DEDUPE_MINUTES . " MINUTE)");
        $seen->execute([$ad['id'], $ip]);
        if (!(int) $seen->fetchColumn()) {
            $pdo->prepare("INSERT INTO ad_clicks (ad_id, ip_hash) VALUES (?, ?)")->execute([$ad['id'], $ip]);
            $pdo->prepare("INSERT INTO ad_stats (ad_id, day, clicks) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1")->execute([$ad['id'], ads_today()]);
        }
        if (random_int(1, 100) === 1) $pdo->exec("DELETE FROM ad_clicks WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
    return $ad;
}

/** Views, clicks per ad between two dates (inclusive): [ad_id => ['views', 'clicks']] */
function ads_totals(PDO $pdo, $from, $to)
{
    $st = $pdo->prepare("SELECT ad_id, SUM(views) v, SUM(clicks) c FROM ad_stats WHERE day BETWEEN ? AND ? GROUP BY ad_id");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int) $r['ad_id']] = ['views' => (int) $r['v'], 'clicks' => (int) $r['c']];
    return $out;
}

function ads_ctr($views, $clicks)
{
    return $views > 0 ? round($clicks * 100 / $views, 2) : null;
}
