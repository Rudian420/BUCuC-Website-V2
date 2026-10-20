<?php
/**
 * Performance Gallery (admin): YouTube videos shown on performances.php.
 * Permission manage_gallery (Super, Panel, HR, MIAP by default; grantable). Only video IDs and
 * details are stored; every new or changed link is checked with YouTube's oEmbed endpoint.
 */
require_once '../backend/includes/auth.php';
require_admin(['can' => 'manage_gallery', 'csrf_redirect' => 'video_gallery.php']);
require_once '../backend/includes/gallery.php';

$conn = db();
gallery_ensure($conn);
$message = $_GET['success'] ?? '';
$error = '';
$dup = [];   // same video already in the gallery (save needs confirming)

function back($msg, $anchor = '')
{
    header('Location: video_gallery.php?success=' . urlencode($msg) . $anchor);
    exit();
}
function video_by_id(PDO $pdo, $id)
{
    $st = $pdo->prepare("SELECT g.*, t.label AS tenure_label FROM gallery_videos g LEFT JOIN tenures t ON t.id = g.tenure_id WHERE g.id = ?");
    $st->execute([(int) $id]);
    return $st->fetch() ?: null;
}
function same_video(PDO $pdo, $youtubeId, $exceptId)
{
    $st = $pdo->prepare("SELECT id, title, hidden FROM gallery_videos WHERE youtube_id = ? AND id <> ?");
    $st->execute([$youtubeId, (int) $exceptId]);
    return $st->fetchAll();
}

$tenures = tenures_all(false);
$tenureLabels = array_column($tenures, 'label', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'lookup':
                // Called by the page's script when a link is pasted: checks it and fills the preview
                header('Content-Type: application/json');
                $yt = youtube_parse($_POST['link'] ?? '');
                if (!$yt) {
                    echo json_encode(['success' => false, 'error' => "That isn't a YouTube video link. Paste a link like https://www.youtube.com/watch?v=… or https://youtu.be/…"]);
                    exit();
                }
                [$ok, $info] = youtube_check($yt['id']);
                if (!$ok) { echo json_encode(['success' => false, 'error' => $info]); exit(); }
                echo json_encode(['success' => true, 'youtube_id' => $yt['id'], 'start' => $yt['start'], 'short' => $yt['short'] || $info['vertical'],
                    'title' => $info['title'], 'thumbnail' => $info['thumbnail'],
                    'duplicates' => array_map(fn($d) => ['title' => $d['title'], 'hidden' => (bool) $d['hidden']], same_video($conn, $yt['id'], (int) ($_POST['id'] ?? 0)))]);
                exit();

            case 'save':
                $id = (int) ($_POST['id'] ?? 0);
                $old = $id ? video_by_id($conn, $id) : null;
                if ($id && !$old) { $error = 'That video is no longer in the gallery.'; break; }
                $yt = youtube_parse($_POST['link'] ?? '');
                if (!$yt) { $error = "That isn't a YouTube video link. Paste a link like https://www.youtube.com/watch?v=… or https://youtu.be/…"; break; }
                $title = trim((string) ($_POST['title'] ?? ''));
                $category = (string) ($_POST['category'] ?? '');
                $date = trim((string) ($_POST['video_date'] ?? ''));
                $tenureId = (int) ($_POST['tenure_id'] ?? 0) ?: null;
                $desc = trim((string) ($_POST['description'] ?? ''));
                $featured = !empty($_POST['featured']) ? 1 : 0;
                $short = !empty($_POST['is_short']) ? 1 : 0;
                if ($date !== '') {
                    $d = DateTime::createFromFormat('Y-m-d', $date);
                    if (!$d || $d->format('Y-m-d') !== $date) { $error = 'Enter a valid date, or leave it empty.'; break; }
                }
                if ($tenureId && !isset($tenureLabels[$tenureId])) { $error = 'That tenure no longer exists.'; break; }
                if (!isset(GALLERY_CATEGORIES[$category])) { $error = 'Choose a category.'; break; }
                if (mb_strlen($desc) > GALLERY_DESC_MAX) { $error = 'The description can be at most ' . GALLERY_DESC_MAX . ' characters.'; break; }
                // A new video or a changed link is checked with YouTube again (never trust the page)
                $linkChanged = !$old || $old['youtube_id'] !== $yt['id'];
                $thumb = $old['thumbnail'] ?? '';
                if ($linkChanged) {
                    [$ok, $info] = youtube_check($yt['id']);
                    if (!$ok) { $error = $info; break; }
                    $thumb = $info['thumbnail'];
                    if ($title === '') $title = $info['title'];
                }
                if ($title === '') { $error = 'Enter a title.'; break; }
                $title = mb_substr($title, 0, GALLERY_TITLE_MAX);
                $dup = $linkChanged ? same_video($conn, $yt['id'], $id) : [];
                if ($dup && empty($_POST['confirm_dup'])) break;   // the page asks "Save anyway?"
                $dup = [];
                $fields = [$yt['id'], $yt['start'], $title, $category, $date ?: null, $tenureId, $desc ?: null, $thumb, $short, $featured];
                $what = GALLERY_CATEGORIES[$category] . ($tenureId ? ', tenure ' . $tenureLabels[$tenureId] : '') . ($date ? ', ' . date('j M Y', strtotime($date)) : '') . ($featured ? ', featured' : '') . ', youtu.be/' . $yt['id'];
                if ($old) {
                    $conn->prepare("UPDATE gallery_videos SET youtube_id = ?, start_seconds = ?, title = ?, category = ?, video_date = ?, tenure_id = ?, description = ?, thumbnail = ?, is_short = ?, featured = ? WHERE id = ?")
                        ->execute(array_merge($fields, [$id]));
                    $changes = [];
                    if ($linkChanged) $changes[] = 'new link';
                    if ($old['title'] !== $title) $changes[] = 'title (was "' . $old['title'] . '")';
                    if ($old['category'] !== $category) $changes[] = 'category';
                    if ((string) $old['video_date'] !== (string) ($date ?: '') || (int) $old['tenure_id'] !== (int) $tenureId) $changes[] = 'date / tenure';
                    if ((string) $old['description'] !== $desc) $changes[] = 'description';
                    if ((int) $old['featured'] !== $featured) $changes[] = $featured ? 'now featured' : 'no longer featured';
                    if ((int) $old['is_short'] !== $short) $changes[] = $short ? 'shown vertical' : 'shown wide';
                    log_activity('gallery_video_updated', ($changes ? 'Changed: ' . implode(', ', $changes) . '. ' : 'Saved without changes. ') . $what, null, null, $title);
                    back("\"$title\" was saved.", '#v' . $id);
                }
                $conn->prepare("INSERT INTO gallery_videos (youtube_id, start_seconds, title, category, video_date, tenure_id, description, thumbnail, is_short, featured, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute(array_merge($fields, [$_SESSION['admin_id']]));
                $newId = (int) $conn->lastInsertId();
                gallery_move($conn, $newId, 1);   // new videos go to the top
                log_activity('gallery_video_added', $what, null, null, $title);
                back("\"$title\" was added to the gallery.", '#v' . $newId);

            case 'toggle':
                $v = video_by_id($conn, $_POST['id'] ?? 0);
                if (!$v) { $error = 'That video is no longer in the gallery.'; break; }
                $hide = (int) !$v['hidden'];
                $conn->prepare("UPDATE gallery_videos SET hidden = ? WHERE id = ?")->execute([$hide, $v['id']]);
                log_activity($hide ? 'gallery_video_hidden' : 'gallery_video_shown', $hide ? 'Hidden from the public page' : 'Shown on the public page again', null, null, $v['title']);
                back($hide ? "\"{$v['title']}\" is hidden from the public page." : "\"{$v['title']}\" is visible again.", '#v' . $v['id']);

            case 'delete':
                $v = video_by_id($conn, $_POST['id'] ?? 0);
                if (!$v) { $error = 'That video is no longer in the gallery.'; break; }
                $conn->prepare("DELETE FROM gallery_videos WHERE id = ?")->execute([$v['id']]);
                gallery_set_order($conn, []);
                log_activity('gallery_video_deleted', GALLERY_CATEGORIES[$v['category']] ?? '', null, null, $v['title'] . ' (youtu.be/' . $v['youtube_id'] . ')');
                back("\"{$v['title']}\" was deleted from the gallery.");

            case 'move':
                $v = video_by_id($conn, $_POST['id'] ?? 0);
                if (!$v) { $error = 'That video is no longer in the gallery.'; break; }
                $pos = (int) ($_POST['position'] ?? 0);
                if ($pos < 1) { $error = 'Type a position number of 1 or more.'; break; }
                gallery_move($conn, $v['id'], $pos);
                back("\"{$v['title']}\" moved to position " . min($pos, count(gallery_videos($conn))) . '.', '#v' . $v['id']);

            case 'reorder':
                // Drag and drop on the page; sends every id in the new order
                header('Content-Type: application/json');
                $ids = json_decode((string) ($_POST['ids'] ?? '[]'), true);
                if (!is_array($ids)) { echo json_encode(['success' => false, 'error' => 'Bad order.']); exit(); }
                gallery_set_order($conn, $ids);
                echo json_encode(['success' => true]);
                exit();
        }
    } catch (\Throwable $e) {
        error_log('video_gallery.php: ' . $e->getMessage());
        if (in_array($action, ['lookup', 'reorder'], true)) { echo json_encode(['success' => false, 'error' => 'Saving failed because of a database problem.']); exit(); }
        $error = 'Saving failed because of a database problem. Nothing was changed; please try again.';
    }
}

$videos = gallery_videos($conn);
$editing = isset($_GET['edit']) ? video_by_id($conn, $_GET['edit']) : null;
// The form shows: what was just typed (after an error), else the video being edited, else a new one
$posted = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') ? $_POST : null;
if ($posted && (int) ($posted['id'] ?? 0)) $editing = video_by_id($conn, $posted['id']);
$f = function ($k, $default = '') use ($posted, $editing) {
    if ($posted) return (string) ($posted[$k] ?? '');
    if ($editing) {
        if ($k === 'link') return 'https://www.youtube.com/watch?v=' . $editing['youtube_id'] . ($editing['start_seconds'] ? '&t=' . $editing['start_seconds'] . 's' : '');
        return (string) ($editing[$k] ?? '');
    }
    return $default;
};
$currentTenure = '';
foreach ($tenures as $t) if ($t['is_current']) $currentTenure = (string) $t['id'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Gallery - BUCuC</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/gallery.css?v=<?= filemtime(__DIR__ . '/css/gallery.css') ?>">
    <style>
        .wrapx { padding: 2rem 1rem; color: var(--ink, rgb(var(--c-fixed-ffffff, 255 255 255))); max-width: 1180px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .page-header .links { display: flex; gap: .5rem; flex-wrap: wrap; }
        .back-btn { text-decoration: none; color: inherit; padding: .5rem 1rem; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); border-radius: 999px; }
        .panel { background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-radius: 18px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .panel.warn { border-color: rgb(var(--c-highlight, 255 207 125) / 0.6); }
        .form-control, .form-select { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-control:focus, .form-select:focus { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.35); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-select option { color: rgb(var(--c-fixed-000000, 0 0 0)); }
        .form-label { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        .note { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        .check-msg { font-size: .88rem; margin-top: .4rem; min-height: 1.2em; }
        .check-msg.bad { color: rgb(var(--c-danger, 255 147 180)); }
        .check-msg.good { color: rgb(var(--c-accent2, 92 255 176)); }
        .check-msg.warn { color: rgb(var(--c-highlight, 255 207 125)); }
        .add-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1.5rem; align-items: start; }
        .preview-wrap .gv-grid { grid-template-columns: 1fr; }
        @media (max-width: 860px) { .add-grid { grid-template-columns: 1fr; } }
        .vlist { list-style: none; padding: 0; margin: 0; }
        .vrow { display: flex; gap: .9rem; align-items: center; padding: .75rem .5rem; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); background: transparent; border-radius: 10px; }
        .vrow.dragging { opacity: .45; }
        .vrow.over { background: rgb(var(--c-accent, 45 226 230) / 0.08); }
        .vrow.is-hidden .vthumb, .vrow.is-hidden .vinfo { opacity: .55; }
        .handle { cursor: grab; color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: 1.1rem; padding: .25rem; }
        .pos { width: 4.2rem; flex: none; }
        .vthumb { width: 120px; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 8px; flex: none; background: rgb(var(--c-fixed-000000, 0 0 0)); }
        .vthumb.short { width: 54px; aspect-ratio: 9 / 16; }
        .vinfo { flex: 1; min-width: 0; }
        .vinfo b { display: block; overflow-wrap: anywhere; }
        .vinfo small { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .tag { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .72rem; font-weight: 700; margin-right: .3rem; }
        .tag.feat { background: rgb(var(--c-highlight, 255 207 125) / 0.18); color: rgb(var(--c-highlight, 255 207 125)); }
        .tag.hid { background: rgb(var(--c-danger, 255 147 180) / 0.16); color: rgb(var(--c-danger, 255 147 180)); }
        .tag.cat { background: rgb(var(--c-accent, 45 226 230) / 0.14); color: rgb(var(--c-accent, 45 226 230)); }
        .tag.sh { background: rgb(var(--c-primary, 139 92 255) / 0.2); color: rgb(var(--c-primary-c8b6ff, 200 182 255)); }
        .vacts { display: flex; gap: .35rem; flex-wrap: wrap; justify-content: flex-end; }
        .vacts form, .posform { margin: 0; }
        .order-status { font-size: .85rem; min-height: 1.2em; }
        @media (max-width: 700px) {
            .vrow { flex-wrap: wrap; }
            .vthumb { width: 96px; }
            .vacts { width: 100%; justify-content: flex-start; }
            .handle { display: none; }   /* phones: use the position numbers */
        }
    </style>
    <link rel="icon" type="image/png" href="images/logopng.png">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="wrapx">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-film me-2"></i>Performance Gallery</h2>
            <div class="links">
                <a href="performances.php" class="back-btn" target="_blank" rel="noopener"><i class="fas fa-external-link-alt me-2"></i>View public page</a>
                <a href="<?= $h(auth_dashboard_page()) ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
            </div>
        </div>

        <?php if ($message): ?><div class="alert alert-success"><?= $h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>

        <div class="panel" id="addPanel">
            <h4 class="mb-1"><?= $editing ? 'Edit video' : 'Add a video' ?></h4>
            <p class="note mb-3">Paste a YouTube link (normal, youtu.be, Shorts or embed). The site checks it with YouTube and fills in the title and picture. Only the link and these details are saved; no video file is uploaded.</p>
            <form method="POST" action="video_gallery.php" id="videoForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
                <?php if ($dup): ?>
                    <div class="panel warn mb-3" id="dupWarn" role="alert">
                        <b><i class="fas fa-exclamation-triangle me-2"></i>This video is already in the gallery</b>
                        <ul class="mb-2 mt-2"><?php foreach ($dup as $d): ?><li><?= $h($d['title']) ?><?= $d['hidden'] ? ' (hidden)' : '' ?></li><?php endforeach; ?></ul>
                        <button type="submit" name="confirm_dup" value="1" class="btn btn-warning btn-sm">Add it again anyway</button>
                        <a href="video_gallery.php" class="btn btn-secondary btn-sm ms-1">Cancel</a>
                    </div>
                <?php endif; ?>
                <div class="add-grid">
                    <div>
                        <label class="form-label" for="link">YouTube link</label>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control" id="link" name="link" required inputmode="url" autocomplete="off" placeholder="https://www.youtube.com/watch?v=…" value="<?= $h($f('link')) ?>">
                            <button type="button" class="btn btn-outline-light" id="checkBtn">Check</button>
                        </div>
                        <div class="check-msg" id="linkMsg" aria-live="polite"></div>
                        <div class="row">
                            <div class="col-12 mb-2"><label class="form-label" for="title">Title</label><input type="text" class="form-control" id="title" name="title" maxlength="<?= GALLERY_TITLE_MAX ?>" placeholder="Filled in from YouTube" value="<?= $h($f('title')) ?>"></div>
                            <div class="col-sm-6 mb-2"><label class="form-label" for="category">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <?php foreach (GALLERY_CATEGORIES as $k => $label): ?><option value="<?= $k ?>" <?= $f('category', 'show_highlight') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 mb-2"><label class="form-label" for="tenure_id">Tenure (optional)</label>
                                <select class="form-select" id="tenure_id" name="tenure_id">
                                    <option value="">None</option>
                                    <?php foreach ($tenures as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $f('tenure_id', $currentTenure) === (string) $t['id'] ? 'selected' : '' ?>><?= $h($t['label']) ?><?= $t['is_current'] ? ' (current)' : '' ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 mb-2"><label class="form-label" for="video_date">Date (optional)</label><input type="date" class="form-control" id="video_date" name="video_date" value="<?= $h($f('video_date')) ?>"></div>
                            <div class="col-sm-6 mb-2 d-flex flex-column justify-content-end gap-1">
                                <label class="form-check-label"><input type="checkbox" class="form-check-input me-2" name="featured" id="featured" value="1" <?= $f('featured') ? 'checked' : '' ?>>Featured (pinned to the top)</label>
                                <label class="form-check-label"><input type="checkbox" class="form-check-input me-2" name="is_short" id="is_short" value="1" <?= $f('is_short') ? 'checked' : '' ?>>Vertical video (Short)</label>
                            </div>
                            <div class="col-12 mb-2"><label class="form-label" for="description">Short description (optional)</label><textarea class="form-control" id="description" name="description" rows="3" maxlength="<?= GALLERY_DESC_MAX ?>"><?= $h($f('description')) ?></textarea></div>
                        </div>
                        <button type="submit" class="btn btn-success"><i class="fas <?= $editing ? 'fa-save' : 'fa-plus' ?> me-2"></i><?= $editing ? 'Save changes' : 'Add to gallery' ?></button>
                        <?php if ($editing): ?><a href="video_gallery.php" class="btn btn-secondary ms-2">Cancel</a><?php endif; ?>
                    </div>
                    <div class="preview-wrap">
                        <div class="form-label mb-2">Preview (how it looks on the public page)</div>
                        <div class="gv-grid" id="preview"><p class="note">Paste a link to see the preview.</p></div>
                    </div>
                </div>
            </form>
        </div>

        <div class="panel">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h4 class="m-0">Videos (<?= count($videos) ?>)</h4>
                <span class="order-status" id="orderStatus" aria-live="polite"></span>
            </div>
            <?php if (!$videos): ?>
                <p class="note m-0">No videos yet. Paste a YouTube link above to add the first one.</p>
            <?php else: ?>
                <p class="note">Drag a row by its handle, or type a position number. Featured videos always show first on the public page.</p>
                <ol class="vlist" id="vlist">
                    <?php foreach ($videos as $i => $v): ?>
                        <li class="vrow<?= $v['hidden'] ? ' is-hidden' : '' ?>" id="v<?= (int) $v['id'] ?>" data-id="<?= (int) $v['id'] ?>" draggable="true">
                            <span class="handle" title="Drag to reorder" aria-hidden="true"><i class="fas fa-grip-vertical"></i></span>
                            <form method="POST" action="video_gallery.php" class="posform">
                                <input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                <input type="number" class="form-control form-control-sm pos" name="position" min="1" max="<?= count($videos) ?>" value="<?= $i + 1 ?>" aria-label="Position of <?= $h($v['title']) ?>" onchange="this.form.submit()">
                            </form>
                            <img class="vthumb<?= $v['is_short'] ? ' short' : '' ?>" src="<?= $h($v['thumbnail']) ?>" alt="" loading="lazy">
                            <div class="vinfo">
                                <b><?= $h($v['title']) ?></b>
                                <span class="tag cat"><?= $h(GALLERY_CATEGORIES[$v['category']] ?? 'Other') ?></span><?php if ($v['featured']): ?><span class="tag feat">★ Featured</span><?php endif; ?><?php if ($v['is_short']): ?><span class="tag sh">Short</span><?php endif; ?><?php if ($v['hidden']): ?><span class="tag hid">Hidden</span><?php endif; ?>
                                <br><small><?= $h(gallery_meta($v) ?: 'No date or tenure') ?> · <a href="https://www.youtube.com/watch?v=<?= $h($v['youtube_id']) ?>" target="_blank" rel="noopener" class="text-reset">youtu.be/<?= $h($v['youtube_id']) ?></a></small>
                            </div>
                            <div class="vacts">
                                <a href="video_gallery.php?edit=<?= (int) $v['id'] ?>#addPanel" class="btn btn-sm btn-outline-light"><i class="fas fa-pen me-1"></i>Edit</a>
                                <form method="POST" action="video_gallery.php"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-light"><i class="fas <?= $v['hidden'] ? 'fa-eye' : 'fa-eye-slash' ?> me-1"></i><?= $v['hidden'] ? 'Show' : 'Hide' ?></button></form>
                                <form method="POST" action="video_gallery.php" onsubmit="return confirm(<?= $h(json_encode('Delete "' . $v['title'] . '" from the gallery? (The video stays on YouTube.)')) ?>)"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Delete</button></form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>

    <script>
        (function () {
            const CATS = <?= json_encode(GALLERY_CATEGORIES) ?>;
            const TENURES = <?= json_encode(array_map('strval', $tenureLabels)) ?>;
            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const form = document.getElementById('videoForm');
            const $ = id => document.getElementById(id);
            const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            // What the last check returned (the editing video's values to start with)
            let video = <?= json_encode($editing && !$posted ? ['youtube_id' => $editing['youtube_id'], 'thumbnail' => $editing['thumbnail'], 'title' => $editing['title']] : null) ?>;
            let autoTitle = video ? video.title : '';

            function preview() {
                const box = $('preview');
                if (!video) { box.innerHTML = '<p class="note">Paste a link to see the preview.</p>'; return; }
                const short = $('is_short').checked, feat = $('featured').checked;
                const d = $('video_date').value, t = $('tenure_id').value;
                const meta = [d ? new Date(d + 'T00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : '', t ? 'Tenure ' + TENURES[t] : ''].filter(Boolean).join(' · ');
                const desc = $('description').value.trim();
                box.innerHTML = `<article class="gv-card${short ? ' short' : ''}${feat ? ' featured' : ''}">
                    <div class="gv-frame"><div class="gv-badges">${feat ? '<span class="gv-badge feat">★ Featured</span>' : ''}${short ? '<span class="gv-badge">Short</span>' : ''}</div>
                    <span class="gv-play"><img src="${esc(video.thumbnail)}" alt=""><span class="gv-icon"></span></span></div>
                    <div class="gv-body"><span class="gv-cat">${esc(CATS[$('category').value] || '')}</span><h3 class="gv-title">${esc($('title').value.trim() || video.title)}</h3>
                    ${meta ? `<span class="gv-meta">${esc(meta)}</span>` : ''}${desc ? `<p class="gv-desc">${esc(desc)}</p>` : ''}</div></article>`;
            }
            ['title', 'category', 'tenure_id', 'video_date', 'description', 'featured', 'is_short'].forEach(id => $(id).addEventListener('input', preview));

            const msg = (tone, text) => { $('linkMsg').className = 'check-msg' + (tone ? ' ' + tone : ''); $('linkMsg').textContent = text; };
            let lastLink = '';
            async function check() {
                const link = $('link').value.trim();
                if (!link || link === lastLink) return;
                lastLink = link;
                msg('', 'Checking with YouTube…');
                const fd = new FormData();
                fd.append('action', 'lookup'); fd.append('link', link); fd.append('id', form.elements.id.value); fd.append('csrf_token', csrf);
                try {
                    const r = await fetch('video_gallery.php', { method: 'POST', body: fd });
                    const d = await r.json();
                    if (!d.success) { video = null; preview(); return msg('bad', d.error); }
                    // Fill the title from YouTube unless the admin already typed their own
                    if (!$('title').value.trim() || $('title').value === autoTitle) $('title').value = d.title;
                    autoTitle = d.title;
                    $('is_short').checked = d.short;
                    video = d;
                    if (d.duplicates.length) msg('warn', `⚠ Already in the gallery: ${d.duplicates.map(x => '"' + x.title + '"' + (x.hidden ? ' (hidden)' : '')).join(', ')}. You can still add it; you'll be asked to confirm.`);
                    else msg('good', '✓ Found on YouTube and allowed on other websites' + (d.start ? `. Starts at ${Math.floor(d.start / 60)}:${String(d.start % 60).padStart(2, '0')}` : '') + (d.short ? '. Vertical video (Short)' : '') + '.');
                    preview();
                } catch (e) {
                    lastLink = '';
                    msg('bad', 'Could not check the link. Reload the page and try again.');
                }
            }
            $('checkBtn').addEventListener('click', () => { lastLink = ''; check(); });
            $('link').addEventListener('change', check);
            $('link').addEventListener('paste', () => setTimeout(check, 50));
            if (video) preview();
            else if ($('link').value.trim()) check();   // refilled after an error

            // Drag to reorder (desktop); phones use the position numbers
            const list = $('vlist');
            if (!list) return;
            let dragged = null;
            list.addEventListener('dragstart', e => { dragged = e.target.closest('.vrow'); if (!dragged) return; dragged.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragged.dataset.id); });
            list.addEventListener('dragover', e => {
                const row = e.target.closest('.vrow');
                if (!dragged || !row || row === dragged) return;
                e.preventDefault();
                const r = row.getBoundingClientRect();
                list.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? row.nextSibling : row);
            });
            list.addEventListener('dragend', async () => {
                if (!dragged) return;
                dragged.classList.remove('dragging');
                dragged = null;
                const rows = [...list.querySelectorAll('.vrow')];
                rows.forEach((row, i) => row.querySelector('.pos').value = i + 1);
                const fd = new FormData();
                fd.append('action', 'reorder'); fd.append('ids', JSON.stringify(rows.map(r => +r.dataset.id))); fd.append('csrf_token', csrf);
                $('orderStatus').textContent = 'Saving the order…';
                try {
                    const d = await (await fetch('video_gallery.php', { method: 'POST', body: fd })).json();
                    $('orderStatus').textContent = d.success ? '✓ Order saved' : (d.error || 'Could not save the order.');
                } catch (e) { $('orderStatus').textContent = 'Could not save the order. Reload the page.'; }
            });
        })();
    </script>
</body>

</html>
