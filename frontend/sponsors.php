<?php
/**
 * Sponsor banners (Super Admin only; everyone else gets a plain 404, as if this page didn't exist).
 * Tabs: Ads (add / edit / pause / delete), Spots (on / off), Stats (views, clicks, click rate).
 * Rules and counting: backend/includes/ads.php. Click redirect: go.php.
 */
require_once '../backend/includes/auth.php';
require_once '../backend/includes/ads.php';
ads_require_super();

$conn = db();
ads_ensure($conn);
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$message = $_GET['success'] ?? '';
$error = '';
$tab = in_array($_GET['tab'] ?? '', ['spots', 'stats'], true) ? $_GET['tab'] : 'ads';
const SPONSOR_DIR = 'images/sponsors/';

function sponsor_store(array $file, $base, $ext)
{
    $abs = __DIR__ . '/' . SPONSOR_DIR;
    if (!is_dir($abs) && !@mkdir($abs, 0755, true)) throw new RuntimeException('Could not create the folder for sponsor images.');
    $name = $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $abs . $name)) throw new RuntimeException('Could not save the uploaded image.');
    @chmod($abs . $name, 0644);
    if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp'], true)) {
        media_optimize_and_webp($abs . $name);
    }
    return SPONSOR_DIR . $name;
}
function sponsor_unlink($rel)
{
    if ($rel && strpos($rel, SPONSOR_DIR) === 0) {
        $abs = __DIR__ . '/' . $rel;
        if (is_file($abs)) @unlink($abs);
        $w = preg_replace('/\.(jpe?g|png)$/i', '.webp', $abs);
        if ($w !== $abs && is_file($w)) @unlink($w);
    }
}
function ad_by_id(PDO $pdo, $id)
{
    $st = $pdo->prepare("SELECT * FROM ads WHERE id = ?");
    $st->execute([(int) $id]);
    return $st->fetch() ?: null;
}
$back = function ($tab, $msg, $anchor = '') {
    site_cache_clear();
    header('Location: sponsors.php?tab=' . $tab . '&success=' . urlencode($msg) . $anchor);
    exit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $fresh = [];
    try {
        if ($action === 'save_ad') {
            $id = (int) ($_POST['id'] ?? 0);
            $old = $id ? ad_by_id($conn, $id) : null;
            $spot = (string) ($_POST['spot'] ?? '');
            $advertiser = trim((string) ($_POST['advertiser'] ?? ''));
            $link = trim((string) ($_POST['link'] ?? ''));
            $start = (string) ($_POST['starts_on'] ?? '');
            $end = (string) ($_POST['ends_on'] ?? '');
            $active = !empty($_POST['active']) ? 1 : 0;
            $vd = fn($d) => ($x = DateTime::createFromFormat('Y-m-d', $d)) && $x->format('Y-m-d') === $d;
            $hasImg = ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $hasMob = ($_FILES['image_mobile']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($id && !$old) $error = 'That ad no longer exists.';
            elseif (!isset(AD_SPOTS[$spot])) $error = 'Choose a spot.';
            elseif ($advertiser === '') $error = "Enter the advertiser's name.";
            elseif (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $link)) $error = 'The link must be a full web address starting with https:// (or http://).';
            elseif (!$vd($start) || !$vd($end)) $error = 'Enter the start and end dates.';
            elseif ($end < $start) $error = 'The end date must be on or after the start date.';
            elseif (!$old && !$hasImg) $error = 'Choose the banner image.';
            elseif ($old && $old['spot'] !== $spot && !$hasImg) $error = 'A different spot has a different size: upload a new banner image for it.';
            if (!$error && $hasImg) {
                [$ok, $msg, $ext] = ads_check_image($_FILES['image'], $spot, 'desktop');
                if (!$ok) $error = $msg; else $fresh['image'] = [$_FILES['image'], $ext];
            }
            if (!$error && $hasMob) {
                [$ok, $msg, $ext] = ads_check_image($_FILES['image_mobile'], $spot, 'mobile');
                if (!$ok) $error = 'Phone image: ' . $msg; else $fresh['image_mobile'] = [$_FILES['image_mobile'], $ext];
            }
            if (!$error) {
                $slug = media_slug($advertiser) ?: 'sponsor';
                $img = $old['image'] ?? null;
                $mob = $old['image_mobile'] ?? null;
                $stored = [];
                if (isset($fresh['image'])) $stored[] = $img = sponsor_store($fresh['image'][0], $slug, $fresh['image'][1]);
                if (isset($fresh['image_mobile'])) $stored[] = $mob = sponsor_store($fresh['image_mobile'][0], $slug . '-phone', $fresh['image_mobile'][1]);
                if (!isset($fresh['image_mobile']) && (!empty($_POST['remove_mobile']) || ($old && $old['spot'] !== $spot))) $mob = null;
                try {
                    $vals = [$spot, mb_substr($advertiser, 0, 120), mb_substr($link, 0, 500), $img, $mob, $start, $end, $active];
                    if ($old) {
                        $conn->prepare("UPDATE ads SET spot = ?, advertiser = ?, link = ?, image = ?, image_mobile = ?, starts_on = ?, ends_on = ?, active = ? WHERE id = ?")->execute(array_merge($vals, [$id]));
                    } else {
                        $conn->prepare("INSERT INTO ads (spot, advertiser, link, image, image_mobile, starts_on, ends_on, active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_merge($vals, [$_SESSION['admin_id']]));
                        $id = (int) $conn->lastInsertId();
                    }
                } catch (\Throwable $e) {
                    foreach ($stored as $f) sponsor_unlink($f);   // nothing in the database points at them
                    throw $e;
                }
                if ($old) {   // files no longer used
                    if ($old['image'] !== $img) sponsor_unlink($old['image']);
                    if ($old['image_mobile'] && $old['image_mobile'] !== $mob) sponsor_unlink($old['image_mobile']);
                }
                $what = AD_SPOTS[$spot]['label'] . ', ' . date('j M Y', strtotime($start)) . ' – ' . date('j M Y', strtotime($end)) . ($active ? '' : ', paused') . ', ' . $link;
                log_activity($old ? 'ad_updated' : 'ad_created', $what, null, null, $advertiser);
                $back('ads', $old ? "\"$advertiser\" saved." : "\"$advertiser\" added.", '#ad' . $id);
            }
        } elseif ($action === 'toggle_ad') {
            $ad = ad_by_id($conn, $_POST['id'] ?? 0);
            if (!$ad) $error = 'That ad no longer exists.';
            else {
                $conn->prepare("UPDATE ads SET active = ? WHERE id = ?")->execute([$ad['active'] ? 0 : 1, $ad['id']]);
                log_activity($ad['active'] ? 'ad_paused' : 'ad_resumed', AD_SPOTS[$ad['spot']]['label'] ?? $ad['spot'], null, null, $ad['advertiser']);
                $back('ads', $ad['active'] ? "\"{$ad['advertiser']}\" paused." : "\"{$ad['advertiser']}\" is active again.", '#ad' . $ad['id']);
            }
        } elseif ($action === 'delete_ad') {
            $ad = ad_by_id($conn, $_POST['id'] ?? 0);
            if (!$ad) $error = 'That ad no longer exists.';
            else {
                $t = $conn->prepare("SELECT COALESCE(SUM(views),0), COALESCE(SUM(clicks),0) FROM ad_stats WHERE ad_id = ?");
                $t->execute([$ad['id']]);
                [$v, $c] = $t->fetch(PDO::FETCH_NUM);
                $conn->prepare("DELETE FROM ads WHERE id = ?")->execute([$ad['id']]);   // its stats go with it
                sponsor_unlink($ad['image']);
                sponsor_unlink($ad['image_mobile']);
                log_activity('ad_deleted', (AD_SPOTS[$ad['spot']]['label'] ?? $ad['spot']) . "; had $v view(s), $c click(s)", null, null, $ad['advertiser']);
                $back('ads', "\"{$ad['advertiser']}\" deleted with its images and stats.");
            }
        } elseif ($action === 'spot') {
            $spot = (string) ($_POST['spot'] ?? '');
            if (!isset(AD_SPOTS[$spot]) || !empty(AD_SPOTS[$spot]['future'])) $error = 'That spot cannot be switched on yet.';
            else {
                $on = !empty($_POST['enabled']) ? 1 : 0;
                $conn->prepare("UPDATE ad_spots SET enabled = ? WHERE spot = ?")->execute([$on, $spot]);
                log_activity($on ? 'ad_spot_on' : 'ad_spot_off', $on ? 'Spot switched on' : 'Spot switched off', null, null, AD_SPOTS[$spot]['label']);
                $back('spots', AD_SPOTS[$spot]['label'] . ($on ? ' is on.' : ' is off.'));
            }
        }
    } catch (\Throwable $e) {
        error_log('sponsors.php: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Saving failed because of a database problem. Nothing was changed; please try again.';
    }
}

$ads = $conn->query("SELECT * FROM ads ORDER BY (ends_on >= CURDATE()) DESC, starts_on DESC, id DESC")->fetchAll();
$spots = [];
foreach ($conn->query("SELECT * FROM ad_spots")->fetchAll() as $s) $spots[$s['spot']] = (int) $s['enabled'];
$editing = isset($_GET['edit']) ? ad_by_id($conn, $_GET['edit']) : null;
$posted = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_ad') ? $_POST : null;
if ($posted && (int) ($posted['id'] ?? 0)) $editing = ad_by_id($conn, $posted['id']);
$formSpot = (string) ($_GET['spot'] ?? ($posted['spot'] ?? ($editing['spot'] ?? 'home_hero')));
if (!isset(AD_SPOTS[$formSpot])) $formSpot = 'home_hero';
$f = fn($k, $d = '') => $posted ? (string) ($posted[$k] ?? '') : ($editing ? (string) ($editing[$k] ?? '') : $d);
$showForm = $editing || $posted || isset($_GET['new']);

// stats
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
$to = $_GET['to'] ?? date('Y-m-d');
foreach (['from', 'to'] as $v) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$v)) $$v = date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$totals = $tab === 'stats' ? ads_totals($conn, $from, $to) : [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Sponsor Banners | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css">
    <link rel="stylesheet" href="css/attendance.css?v=<?= filemtime(__DIR__ . '/css/attendance.css') ?>">
    <link rel="stylesheet" href="vendor/cropperjs/cropper.min.css">
    <link rel="stylesheet" href="css/image-crop.css?v=<?= filemtime(__DIR__ . '/css/image-crop.css') ?>">
    <style>
        .ad-row { display: flex; gap: 1rem; align-items: center; padding: .8rem 0; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); flex-wrap: wrap; }
        .ad-row picture { display: block; }
        .ad-row img, .ad-row picture img { width: 220px; max-width: 100%; border-radius: 8px; background: rgb(var(--c-fixed-000000, 0 0 0) / 0.3); }
        .ad-row .grow { flex: 1; min-width: 200px; }
        .ad-row .acts { display: flex; gap: .35rem; flex-wrap: wrap; }
        .ad-row .acts form { margin: 0; }
        .chip.live { background: rgb(var(--c-accent2, 92 255 176) / 0.18); color: rgb(var(--c-accent2, 92 255 176)); }
        .chip.paused, .chip.ended { background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .chip.scheduled { background: rgb(var(--c-accent, 45 226 230) / 0.15); color: rgb(var(--c-accent, 45 226 230)); }
        .spot { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: .9rem 0; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); flex-wrap: wrap; }
        .switch { position: relative; width: 52px; height: 30px; flex: none; }
        .switch input { opacity: 0; width: 100%; height: 100%; position: absolute; margin: 0; cursor: pointer; z-index: 1; }
        .switch span { position: absolute; inset: 0; border-radius: 999px; background: rgb(var(--c-fixed-ffffff, 255 255 255) / 0.18); transition: background .2s; }
        .switch span::after { content: ""; position: absolute; top: 3px; left: 3px; width: 24px; height: 24px; border-radius: 50%; background: rgb(var(--c-fixed-ffffff, 255 255 255)); transition: transform .2s; }
        .switch input:checked + span { background: rgb(var(--c-accent2, 92 255 176)); }
        .switch input:checked + span::after { transform: translateX(22px); }
        .switch input:focus-visible + span { outline: 2px solid rgb(var(--c-fixed-ffffff, 255 255 255)); outline-offset: 2px; }
    </style>
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="wrapx">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-bullhorn me-2"></i>Sponsor banners</h2>
            <div class="links"><a href="<?= $h(auth_dashboard_page()) ?>" class="back-btn"><i class="fas fa-arrow-left"></i>Dashboard</a></div>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><?= $h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>
        <div class="tabs">
            <a href="sponsors.php" class="<?= $tab === 'ads' ? 'on' : '' ?>"><i class="fas fa-rectangle-ad me-1"></i>Ads</a>
            <a href="sponsors.php?tab=spots" class="<?= $tab === 'spots' ? 'on' : '' ?>"><i class="fas fa-toggle-on me-1"></i>Spots</a>
            <a href="sponsors.php?tab=stats" class="<?= $tab === 'stats' ? 'on' : '' ?>"><i class="fas fa-chart-line me-1"></i>Stats</a>
        </div>

        <?php if ($tab === 'ads'): ?>
            <?php if ($showForm): $sp = AD_SPOTS[$formSpot]; ?>
                <div class="panel" id="adForm">
                    <h4 class="mb-3"><?= $editing ? 'Edit ad' : 'New ad' ?></h4>
                    <form method="POST" action="sponsors.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_ad">
                        <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label" for="spot">Spot</label>
                                <select class="form-select" id="spot" name="spot">
                                    <?php foreach (AD_SPOTS as $k => $s): ?><option value="<?= $k ?>" <?= $formSpot === $k ? 'selected' : '' ?>><?= $h($s['label']) ?> (<?= $s['desktop'][0] ?> × <?= $s['desktop'][1] ?>)</option><?php endforeach; ?>
                                </select>
                                <small class="note">Changing the spot reloads the form with that spot's image sizes.</small>
                            </div>
                            <div class="col-md-6 mb-3"><label class="form-label" for="advertiser">Advertiser</label><input type="text" class="form-control" id="advertiser" name="advertiser" maxlength="120" required value="<?= $h($f('advertiser')) ?>"></div>
                            <div class="col-md-6 mb-3"><label class="form-label" for="link">Link (opens in a new tab)</label><input type="url" class="form-control" id="link" name="link" maxlength="500" required placeholder="https://…" value="<?= $h($f('link')) ?>"></div>
                            <div class="col-sm-3 mb-3"><label class="form-label" for="starts_on">Starts</label><input type="date" class="form-control" id="starts_on" name="starts_on" required value="<?= $h($f('starts_on', date('Y-m-d'))) ?>"></div>
                            <div class="col-sm-3 mb-3"><label class="form-label" for="ends_on">Ends</label><input type="date" class="form-control" id="ends_on" name="ends_on" required value="<?= $h($f('ends_on', date('Y-m-d', strtotime('+30 days')))) ?>"></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Banner (desktop), <?= $sp['desktop'][0] ?> × <?= $sp['desktop'][1] ?> px or smaller<?= $editing ? ' · blank = keep' : '' ?></label>
                                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" <?= $editing ? '' : 'required' ?> data-rule="ad_<?= $formSpot ?>_desktop">
                                <div class="check-msg"></div>
                                <?php if ($editing): ?><picture><source srcset="<?= $h(media_webp_url($editing['image'])) ?>" type="image/webp"><img src="<?= $h($editing['image']) ?>" alt="Current banner" class="mt-2" style="max-width:100%;border-radius:8px"></picture><?php endif; ?>
                            </div>
                            <div class="col-md-6 mb-3"><label class="form-label">Phone image (optional), <?= $sp['mobile'][0] ?> × <?= $sp['mobile'][1] ?> px or smaller; without it phones get the banner</label>
                                <input type="file" name="image_mobile" class="form-control" accept="image/jpeg,image/png,image/webp" data-rule="ad_<?= $formSpot ?>_mobile">
                                <div class="check-msg"></div>
                                <?php if ($editing && $editing['image_mobile']): ?><picture><source srcset="<?= $h(media_webp_url($editing['image_mobile'])) ?>" type="image/webp"><img src="<?= $h($editing['image_mobile']) ?>" alt="Current phone image" class="mt-2" style="max-width:60%;border-radius:8px"></picture>
                                    <label class="form-check-label d-block mt-1"><input type="checkbox" class="form-check-input me-2" name="remove_mobile" value="1">Remove the phone image</label><?php endif; ?>
                            </div>
                        </div>
                        <label class="form-check-label mb-3 d-block"><input type="checkbox" class="form-check-input me-2" name="active" value="1" <?= $f('active', '1') ? 'checked' : '' ?>>Active (shows between the start and end dates, Dhaka time). Untick to pause.</label>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i><?= $editing ? 'Save ad' : 'Add ad' ?></button>
                        <a href="sponsors.php" class="btn btn-secondary ms-2">Cancel</a>
                    </form>
                </div>
            <?php endif; ?>
            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h4 class="m-0">Ads (<?= count($ads) ?>)</h4>
                    <?php if (!$showForm): ?><a href="sponsors.php?new=1#adForm" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i>New ad</a><?php endif; ?>
                </div>
                <?php if (!$ads): ?><p class="note m-0">No ads yet.</p><?php endif; ?>
                <?php foreach ($ads as $ad): [$sc, $sl] = ad_status($ad); ?>
                    <div class="ad-row" id="ad<?= (int) $ad['id'] ?>">
                        <picture><source srcset="<?= $h(media_webp_url($ad['image'])) ?>" type="image/webp"><img src="<?= $h($ad['image']) ?>" alt="" loading="lazy"></picture>
                        <div class="grow"><b><?= $h($ad['advertiser']) ?></b> <span class="chip <?= $sc ?>"><?= $h($sl) ?></span><br>
                            <small class="note"><?= $h(AD_SPOTS[$ad['spot']]['label'] ?? $ad['spot']) ?> · <?= date('j M Y', strtotime($ad['starts_on'])) ?> – <?= date('j M Y', strtotime($ad['ends_on'])) ?><?= $ad['image_mobile'] ? ' · phone image' : '' ?><?= empty($spots[$ad['spot']]) ? ' · <span style="color:rgb(var(--c-highlight, 255 207 125))">spot is off</span>' : '' ?></small><br>
                            <small class="note"><?= $h($ad['link']) ?></small></div>
                        <div class="acts">
                            <a href="sponsors.php?edit=<?= (int) $ad['id'] ?>#adForm" class="btn btn-sm btn-outline-light"><i class="fas fa-pen me-1"></i>Edit</a>
                            <form method="POST" action="sponsors.php"><input type="hidden" name="action" value="toggle_ad"><input type="hidden" name="id" value="<?= (int) $ad['id'] ?>"><button class="btn btn-sm btn-outline-light" type="submit"><i class="fas <?= $ad['active'] ? 'fa-pause' : 'fa-play' ?> me-1"></i><?= $ad['active'] ? 'Pause' : 'Resume' ?></button></form>
                            <form method="POST" action="sponsors.php" onsubmit="return confirm(<?= $h(json_encode('Delete the ad of ' . $ad['advertiser'] . '? Its images and stats are deleted too. Export its report first if the sponsor needs it.')) ?>)"><input type="hidden" name="action" value="delete_ad"><input type="hidden" name="id" value="<?= (int) $ad['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="fas fa-trash me-1"></i>Delete</button></form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($tab === 'spots'): ?>
            <div class="panel">
                <h4 class="mb-1">Spots</h4>
                <p class="note">A spot that is off, or has no live ad, disappears from the page completely. Several live ads in one spot take turns (a random one on each page load).</p>
                <?php foreach (AD_SPOTS as $k => $s):
                    $live = array_filter($ads, fn($a) => $a['spot'] === $k && ad_status($a)[0] === 'live'); ?>
                    <div class="spot">
                        <div><b><?= $h($s['label']) ?></b><br><small class="note">Desktop <?= $s['desktop'][0] ?> × <?= $s['desktop'][1] ?> px · phone <?= $s['mobile'][0] ?> × <?= $s['mobile'][1] ?> px · <?= count($live) ?> live ad<?= count($live) === 1 ? '' : 's' ?></small></div>
                        <?php if (!empty($s['future'])): ?><span class="chip ended">Not on the site yet</span>
                        <?php else: ?>
                            <form method="POST" action="sponsors.php?tab=spots" class="m-0 d-flex align-items-center gap-2">
                                <input type="hidden" name="action" value="spot"><input type="hidden" name="spot" value="<?= $k ?>">
                                <span class="note"><?= !empty($spots[$k]) ? 'On' : 'Off' ?></span>
                                <label class="switch" title="Switch <?= $h($s['label']) ?> <?= !empty($spots[$k]) ? 'off' : 'on' ?>"><input type="checkbox" name="enabled" value="1" <?= !empty($spots[$k]) ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="<?= $h($s['label']) ?>"><span></span></label>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="panel">
                <form method="GET" action="sponsors.php" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="tab" value="stats">
                    <div class="col-sm-4"><label class="form-label" for="from">From</label><input type="date" class="form-control" id="from" name="from" value="<?= $h($from) ?>"></div>
                    <div class="col-sm-4"><label class="form-label" for="to">To</label><input type="date" class="form-control" id="to" name="to" value="<?= $h($to) ?>"></div>
                    <div class="col-sm-4"><button class="btn btn-outline-light w-100" type="submit">Show</button></div>
                </form>
                <p class="note">Views: page loads by real visitors (bots, link previews and signed-in admins aren't counted). Clicks: through the site's redirect, one per visitor per ad per <?= AD_CLICK_DEDUPE_MINUTES ?> minutes. Days are Dhaka time.</p>
                <?php if (!$ads): ?><p class="note m-0">No ads yet.</p><?php else: ?>
                    <div class="scroll-x"><table class="tbl">
                        <thead><tr><th>Ad</th><th class="hide-sm">Spot</th><th class="num">Views</th><th class="num">Clicks</th><th class="num">Click rate</th><th></th></tr></thead>
                        <tbody>
                            <?php $tv = 0; $tc = 0; foreach ($ads as $ad): $t = $totals[(int) $ad['id']] ?? ['views' => 0, 'clicks' => 0]; $tv += $t['views']; $tc += $t['clicks']; $ctr = ads_ctr($t['views'], $t['clicks']); ?>
                                <tr><td><b><?= $h($ad['advertiser']) ?></b><br><small class="note"><?= date('j M', strtotime($ad['starts_on'])) ?> – <?= date('j M Y', strtotime($ad['ends_on'])) ?></small></td>
                                    <td class="hide-sm"><?= $h(AD_SPOTS[$ad['spot']]['label'] ?? $ad['spot']) ?></td>
                                    <td class="num"><?= number_format($t['views']) ?></td><td class="num"><?= number_format($t['clicks']) ?></td>
                                    <td class="num"><?= $ctr === null ? '—' : $ctr . '%' ?></td>
                                    <td class="text-end"><a class="btn btn-sm btn-success" href="sponsors_export.php?<?= $h(http_build_query(['id' => $ad['id'], 'from' => $from, 'to' => $to])) ?>"><i class="fas fa-file-excel me-1"></i>Report</a></td></tr>
                            <?php endforeach; ?>
                            <tr><td><b>All ads</b></td><td class="hide-sm"></td><td class="num"><b><?= number_format($tv) ?></b></td><td class="num"><b><?= number_format($tc) ?></b></td><td class="num"><b><?= ($x = ads_ctr($tv, $tc)) === null ? '—' : $x . '%' ?></b></td><td></td></tr>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'ads' && $showForm): ?>
        <script>window.MEDIA_RULES = <?= json_encode(ads_rules_js()) ?>;</script>
        <script src="vendor/cropperjs/cropper.min.js"></script>
        <script src="js/image-crop.js?v=<?= filemtime(__DIR__ . '/js/image-crop.js') ?>"></script>
        <script>
            // a different spot has different image sizes: reload the form for it (typed text is carried over)
            document.getElementById('spot').addEventListener('change', e => {
                const f = e.target.form, p = new URLSearchParams({ spot: e.target.value });
                p.set(f.elements.id.value ? 'edit' : 'new', f.elements.id.value || '1');
                ['advertiser', 'link', 'starts_on', 'ends_on'].forEach(k => sessionStorage.setItem('sp_' + k, f.elements[k].value));
                location.href = 'sponsors.php?' + p + '#adForm';
            });
            ['advertiser', 'link', 'starts_on', 'ends_on'].forEach(k => {
                try { const v = sessionStorage.getItem('sp_' + k); if (v !== null) { document.getElementById(k).value = v; sessionStorage.removeItem('sp_' + k); } } catch (e) { }
            });
        </script>
    <?php endif; ?>
</body>

</html>
