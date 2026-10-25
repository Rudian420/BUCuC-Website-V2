<?php
require_once '../backend/includes/auth.php';

// Website Media: panels by tenure (Presidential Panel + Secretary Panel), past events, hero video.
// Each part needs its own permission (roles.php); every action re-checks it on the server.
// Deleting anything removes the database row AND the file (media_delete_file in site.php).
require_admin(['can' => ['upload_carousel', 'upload_panel', 'upload_events', 'upload_hero'], 'csrf_redirect' => 'media.php']);

$conn = db();
site_ensure_schema($conn);
tenures_seed($conn);
const FRONTEND_DIR = __DIR__ . '/';

$message = $_GET['success'] ?? '';
$error = '';
$tab = $_GET['tab'] ?? '';
$tenureId = (int) ($_GET['t'] ?? 0);
$confirmNeeded = null;   // tenure that will be deleted if the new one is saved (12-tenure limit)

$freshUploads = [];   // files saved during this request; deleted again if the database change fails

/** Moves an uploaded file into frontend/$dir and returns its path relative to frontend/. */
function store_upload(array $file, $dir, $baseName, $ext)
{
    global $freshUploads;
    $abs = FRONTEND_DIR . $dir;
    if (!is_dir($abs) && !@mkdir($abs, 0755, true)) throw new RuntimeException("Could not create the folder $dir.");
    $name = $baseName . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $abs . $name)) throw new RuntimeException('Could not save the uploaded file.');
    @chmod($abs . $name, 0644);
    $freshUploads[] = $abs . $name;
    if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp'], true)) {
        media_optimize_and_webp($abs . $name);
    }
    return $dir . $name;
}

/** URL for a stored image path (spaces etc. encoded). */
function mediaUrl($path)
{
    return implode('/', array_map('rawurlencode', explode('/', (string) $path)));
}

function tenure_by_id(PDO $conn, $id)
{
    $stmt = $conn->prepare("SELECT * FROM tenures WHERE id = ?");
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}

function done($query, $msg)
{
    site_cache_clear();
    // keep a #section jump after the query string, or the message would be lost
    [$query, $hash] = array_pad(explode('#', $query, 2), 2, '');
    header('Location: media.php?' . $query . '&success=' . urlencode($msg) . ($hash !== '' ? '#' . $hash : ''));
    exit();
}

const GROUPS = [
    'panel'     => ['perm' => 'upload_panel', 'rule' => 'panel', 'label' => 'Presidential Panel', 'one' => 'panel member', 'log' => 'panel_member'],
    'secretary' => ['perm' => 'upload_carousel', 'rule' => 'carousel', 'label' => 'Secretary Panel', 'one' => 'secretary', 'log' => 'secretary'],
];

/* ------------------------------------------------------------ actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'tenure_create':
                require_can(['upload_panel', 'upload_carousel']);
                $tab = 'tenures';
                $label = trim($_POST['label'] ?? '');
                if (!preg_match('/^(\d{4})-(\d{2})$/', $label, $m) || (int) $m[2] === (int) substr($m[1], -2)) {
                    $error = 'Write the tenure like 2026-27 (start year, dash, last two digits of the end year).';
                    break;
                }
                $startYear = (int) $m[1];
                $exists = $conn->prepare("SELECT COUNT(*) FROM tenures WHERE label = ?");
                $exists->execute([$label]);
                if ($exists->fetchColumn()) { $error = "Tenure $label already exists."; break; }
                $count = (int) $conn->query("SELECT COUNT(*) FROM tenures")->fetchColumn();
                $oldest = $count >= TENURE_LIMIT ? tenure_oldest_removable($conn) : null;
                if ($oldest && (int) ($_POST['confirm_delete'] ?? 0) !== (int) $oldest['id']) {
                    // Ask first: saving this one deletes the oldest tenure for good
                    $confirmNeeded = $oldest;
                    break;
                }
                $conn->beginTransaction();
                $oldFiles = [];
                if ($oldest) {
                    $cnt = $conn->prepare("SELECT COUNT(*) FROM tenure_people WHERE tenure_id = ?");
                    $cnt->execute([$oldest['id']]);
                    $people = (int) $cnt->fetchColumn();
                    $oldFiles = tenure_delete($conn, $oldest['id']);
                }
                $makeCurrent = !empty($_POST['make_current']);
                if ($makeCurrent) $conn->exec("UPDATE tenures SET is_current = 0");
                $conn->prepare("INSERT INTO tenures (label, start_year, is_current, created_by) VALUES (?, ?, ?, ?)")
                    ->execute([$label, $startYear, $makeCurrent ? 1 : 0, $_SESSION['admin_id']]);
                $newId = (int) $conn->lastInsertId();
                $conn->commit();
                $removedFiles = media_delete_files($oldFiles);
                if ($oldest) {
                    log_activity('tenure_deleted', "Automatic: " . TENURE_LIMIT . "-tenure limit reached when adding $label. $people people and $removedFiles photo file(s) deleted.", null, null, 'Tenure ' . $oldest['label']);
                }
                log_activity('tenure_created', $makeCurrent ? 'Created and set as current' : 'Created', null, null, "Tenure $label");
                tenures_export_json();
                done("tab=tenures&t=$newId", "Tenure $label was created" . ($oldest ? " and tenure {$oldest['label']} was deleted" : '') . '. Add its panel and secretaries below.');

            case 'tenure_current':
                require_can(['upload_panel', 'upload_carousel']);
                $tab = 'tenures';
                $t = tenure_by_id($conn, $_POST['tenure_id'] ?? 0);
                if (!$t) { $error = 'That tenure no longer exists.'; break; }
                $conn->beginTransaction();
                $conn->exec("UPDATE tenures SET is_current = 0");
                $conn->prepare("UPDATE tenures SET is_current = 1 WHERE id = ?")->execute([$t['id']]);
                $conn->commit();
                log_activity('tenure_set_current', 'Now shown first on the homepage', null, null, 'Tenure ' . $t['label']);
                tenures_export_json();
                done("tab=tenures&t={$t['id']}", "Tenure {$t['label']} is now the current panel on the homepage.");

            case 'tenure_delete':
                require_can(['upload_panel', 'upload_carousel']);
                $tab = 'tenures';
                $t = tenure_by_id($conn, $_POST['tenure_id'] ?? 0);
                if (!$t) { $error = 'That tenure no longer exists.'; break; }
                if ($t['is_current']) { $error = 'The current tenure cannot be deleted. Make another tenure current first.'; break; }
                $cnt = $conn->prepare("SELECT COUNT(*) FROM tenure_people WHERE tenure_id = ?");
                $cnt->execute([$t['id']]);
                $people = (int) $cnt->fetchColumn();
                $conn->beginTransaction();
                $oldFiles = tenure_delete($conn, $t['id']);
                $conn->commit();
                $files = media_delete_files($oldFiles);
                log_activity('tenure_deleted', "Deleted by hand. $people people and $files photo file(s) deleted.", null, null, 'Tenure ' . $t['label']);
                tenures_export_json();
                done('tab=tenures', "Tenure {$t['label']} and its photos were deleted.");

            case 'person_save':
                $grp = $_POST['grp'] ?? '';
                if (!isset(GROUPS[$grp])) { $error = 'Unknown group.'; break; }
                $g = GROUPS[$grp];
                require_can($g['perm']);
                $tab = 'tenures';
                $t = tenure_by_id($conn, $_POST['tenure_id'] ?? 0);
                if (!$t) { $error = 'That tenure no longer exists.'; break; }
                $tenureId = (int) $t['id'];
                $pid = (int) ($_POST['person_id'] ?? 0);
                $person = null;
                if ($pid) {
                    $stmt = $conn->prepare("SELECT * FROM tenure_people WHERE id = ? AND tenure_id = ? AND grp = ?");
                    $stmt->execute([$pid, $t['id'], $grp]);
                    $person = $stmt->fetch();
                    if (!$person) { $error = 'That person no longer exists.'; break; }
                }
                $name = trim($_POST['name'] ?? '');
                $position = trim($_POST['position'] ?? '');
                $facebook = trim($_POST['facebook'] ?? '');
                if ($name === '' || $position === '') { $error = 'Enter the name and the position.'; break; }
                if ($facebook !== '' && !filter_var($facebook, FILTER_VALIDATE_URL)) { $error = 'The Facebook link must be a full URL (https://…).'; break; }
                $hasPhoto = ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if (!$person && !$hasPhoto) { $error = 'Choose a photo.'; break; }
                $image = $person['image'] ?? null;
                if ($hasPhoto) {
                    [$ok, $msg, $ext] = media_check_image($_FILES['photo'], $g['rule']);
                    if (!$ok) { $error = $msg; break; }
                    $folder = 'images/tenures/' . $t['label'] . '/' . ($grp === 'panel' ? 'panel' : 'secretaries') . '/';
                    $image = store_upload($_FILES['photo'], $folder, media_slug($name) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)), $ext);
                }
                if ($person) {
                    $conn->prepare("UPDATE tenure_people SET name = ?, position = ?, facebook = ?, image = ? WHERE id = ?")
                        ->execute([$name, $position, $facebook ?: null, $image, $person['id']]);
                    $oldGone = $hasPhoto && $person['image'] && $person['image'] !== $image ? media_delete_file($person['image']) : false;
                    log_activity($g['log'] . '_updated', "Tenure {$t['label']}, $position" . ($hasPhoto ? ', new photo' : '') . ($oldGone ? ', old photo file deleted' : ''), null, null, $name);
                } else {
                    $max = $conn->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tenure_people WHERE tenure_id = ? AND grp = ?");
                    $max->execute([$t['id'], $grp]);
                    $conn->prepare("INSERT INTO tenure_people (tenure_id, grp, name, position, facebook, image, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$t['id'], $grp, $name, $position, $facebook ?: null, $image, (int) $max->fetchColumn()]);
                    log_activity($g['log'] . '_added', "Tenure {$t['label']}, $position", null, null, $name);
                }
                tenures_export_json();
                done("tab=tenures&t={$t['id']}#$grp", $person ? "$name was updated." : "$name was added to the {$g['label']} of {$t['label']}.");

            case 'person_remove':
                $stmt = $conn->prepare("SELECT p.*, t.label FROM tenure_people p JOIN tenures t ON t.id = p.tenure_id WHERE p.id = ?");
                $stmt->execute([(int) ($_POST['person_id'] ?? 0)]);
                $person = $stmt->fetch();
                if (!$person || !isset(GROUPS[$person['grp']])) { $error = 'That person no longer exists.'; break; }
                $g = GROUPS[$person['grp']];
                require_can($g['perm']);
                $conn->prepare("DELETE FROM tenure_people WHERE id = ?")->execute([$person['id']]);
                $fileGone = $person['image'] ? media_delete_file($person['image']) : false;
                log_activity($g['log'] . '_removed', "Tenure {$person['label']}, {$person['position']}" . ($fileGone ? ', photo file deleted' : ''), null, null, $person['name']);
                tenures_export_json();
                done("tab=tenures&t={$person['tenure_id']}#{$person['grp']}", "{$person['name']} and their photo were removed.");

            case 'event_add':
            case 'event_update':
                require_can('upload_events');
                $tab = 'events';
                $ev = null;
                if ($action === 'event_update') {
                    $stmt = $conn->prepare("SELECT * FROM site_events WHERE id = ?");
                    $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                    $ev = $stmt->fetch();
                    if (!$ev) { $error = 'That event no longer exists.'; break; }
                }
                $title = trim($_POST['title'] ?? '');
                $date = $_POST['event_date'] ?? '';
                $d = DateTime::createFromFormat('Y-m-d', $date);
                if ($title === '' || !$d || $d->format('Y-m-d') !== $date) { $error = 'Enter the event title and date.'; break; }
                if ($date > date('Y-m-d')) { $error = 'Past events only: the date cannot be in the future.'; break; }
                // Text boxes send \r\n line breaks; store \n so unchanged text stays unchanged
                $text = fn($k) => trim(str_replace("\r\n", "\n", (string) ($_POST[$k] ?? '')));
                $stats = $text('stats');
                foreach (preg_split('/\r?\n/', $stats) as $line) {
                    if (trim($line) !== '' && (strpos($line, '|') === false || count(event_stats($line)) !== 1)) { $error = "Write each number tile as: number | label (for example 400+ | Attendees). This line isn't: \"" . trim($line) . '"'; break 2; }
                }
                $hasPhoto = ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if (!$ev && !$hasPhoto) { $error = 'Choose a photo.'; break; }
                $img = $ev['image'] ?? null;
                $imgClass = $ev['image_class'] ?? null;
                if ($hasPhoto) {
                    [$ok, $msg, $ext] = media_check_image($_FILES['photo'], 'events');
                    if (!$ok) { $error = $msg; break; }
                    $img = store_upload($_FILES['photo'], 'images/events/', $date . '-' . media_slug($title) . '-' . substr(bin2hex(random_bytes(3)), 0, 6), $ext);
                    $imgClass = null;   // special framing belonged to the old photo
                }
                $vals = [mb_substr($title, 0, 200), $date, mb_substr(trim($_POST['place'] ?? ''), 0, 200) ?: null, mb_substr(trim($_POST['organizer'] ?? ''), 0, 120) ?: null,
                    $text('description') ?: null, $text('highlights') ?: null, $stats ?: null, $img, $imgClass];
                if (!$ev) {
                    $conn->prepare("INSERT INTO site_events (title, event_date, place, organizer, description, highlights, stats, image, image_class, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute(array_merge($vals, [$_SESSION['admin_id']]));
                    log_activity('event_added', date('j M Y', strtotime($date)), null, null, $title);
                    done('tab=events', "\"$title\" was added to Past Events.");
                }
                $conn->prepare("UPDATE site_events SET title = ?, event_date = ?, place = ?, organizer = ?, description = ?, highlights = ?, stats = ?, image = ?, image_class = ? WHERE id = ?")
                    ->execute(array_merge($vals, [$ev['id']]));
                // The replaced photo is deleted only if it was uploaded here and nothing else uses it
                $oldGone = $hasPhoto && $ev['image'] !== $img && event_photo_deletable($ev['image']) ? media_delete_file($ev['image']) : false;
                $changes = [];
                foreach (['title' => 'title', 'event_date' => 'date', 'place' => 'place', 'organizer' => 'organizer', 'description' => 'description', 'highlights' => 'highlights', 'stats' => 'number tiles'] as $col => $label) {
                    $new = ['title' => $vals[0], 'event_date' => $vals[1], 'place' => $vals[2], 'organizer' => $vals[3], 'description' => $vals[4], 'highlights' => $vals[5], 'stats' => $vals[6]][$col];
                    if ((string) $ev[$col] !== (string) $new) $changes[] = $label;
                }
                if ($hasPhoto) $changes[] = 'new photo' . ($oldGone ? ' (old photo file deleted)' : '');
                log_activity('event_updated', $changes ? 'Changed: ' . implode(', ', $changes) : 'Saved without changes', null, null, $title);
                done('tab=events#e' . $ev['id'], "\"$title\" was saved.");

            case 'event_remove':
                require_can('upload_events');
                $tab = 'events';
                $stmt = $conn->prepare("SELECT * FROM site_events WHERE id = ?");
                $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                $ev = $stmt->fetch();
                if (!$ev) { $error = 'That event no longer exists.'; break; }
                $conn->prepare("DELETE FROM site_events WHERE id = ?")->execute([$ev['id']]);
                // Original photos of the imported events stay (the homepage carousel uses some of them)
                $fileGone = event_photo_deletable($ev['image']) ? media_delete_file($ev['image']) : false;
                log_activity('event_removed', date('j M Y', strtotime($ev['event_date'])) . ($fileGone ? ', photo file deleted' : ''), null, null, $ev['title']);
                done('tab=events', "\"{$ev['title']}\" was removed" . ($fileGone ? ' and its photo file deleted.' : '.'));

            case 'hero_upload':
                require_can('upload_hero');
                $tab = 'hero';
                [$ok, $msg] = media_check_video($_FILES['video'] ?? []);
                if (!$ok) { $error = $msg; break; }
                $old = setting_get('hero_video');
                $rel = store_upload($_FILES['video'], 'video/', 'hero-' . date('Ymd-His'), 'mp4');
                setting_set('hero_video', $rel);
                $oldGone = $old ? media_delete_file($old) : false;   // the replaced upload; the original video is never deleted
                $info = mp4_inspect(FRONTEND_DIR . $rel);
                log_activity('hero_video_changed', "{$info['width']}×{$info['height']}, " . round($info['duration'], 1) . ' s, ' . round(filesize(FRONTEND_DIR . $rel) / 1048576, 1) . ' MB'
                    . ($oldGone ? '; previous uploaded video file deleted' : ''), null, null, 'Homepage hero video');
                done('tab=hero', 'The new hero video is live on the homepage.');

            case 'hero_reset':
                // Replaces the uploaded video with the original one (the hero is never left empty)
                require_can('upload_hero');
                $old = setting_get('hero_video');
                setting_set('hero_video', '');
                $oldGone = $old ? media_delete_file($old) : false;
                log_activity('hero_video_reset', 'Replaced with the original BUCuC video' . ($oldGone ? '; uploaded video file deleted' : ''), null, null, 'Homepage hero video');
                done('tab=hero', 'The homepage plays the original video again.');
        }
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        foreach ($freshUploads as $f) {
            @unlink($f);   // nothing in the database points at them
            $w = preg_replace('/\.(jpe?g|png)$/i', '.webp', $f);
            if ($w !== $f && is_file($w)) @unlink($w);
        }
        error_log('media.php: ' . $e->getMessage());
        $error = $e instanceof PDOException ? 'Saving failed because of a database problem. Nothing was changed; please try again.' : $e->getMessage();
    }
}

/* ------------------------------------------------------------ data for the page */

$sections = array_filter([
    'tenures' => can_any(['upload_panel', 'upload_carousel']) ? 'Panels & Tenures' : null,
    'events'  => can('upload_events') ? 'Past Events' : null,
    'hero'    => can('upload_hero') ? 'Hero Video' : null,
]);
if (!isset($sections[$tab])) $tab = array_key_first($sections);

$tenures = tenures_all();
// Past Events form: refilled with what was typed when adding failed (the photo has to be chosen again)
$evEditId = (int) ($_GET['edit'] ?? 0);
if ($error && ($_POST['action'] ?? '') === 'event_update') $evEditId = (int) ($_POST['id'] ?? 0);
$selected = null;
foreach ($tenures as $t) {
    if ((int) $t['id'] === $tenureId) $selected = $t;
}
$selected = $selected ?: ($tenures[0] ?? null);
$editId = (int) ($_GET['edit'] ?? 0);
$oldestNow = count($tenures) >= TENURE_LIMIT ? tenure_oldest_removable($conn) : null;
$nextStart = $tenures ? max(array_map(fn($t) => (int) $t['start_year'], $tenures)) + 1 : (int) date('Y');
$nextLabel = $nextStart . '-' . substr((string) ($nextStart + 1), -2);   // suggestion for the next tenure

$events = site_events_all();
$evEditing = null;
foreach ($events as $e) if ((int) $e['id'] === $evEditId) $evEditing = $e;
// Events form: what was typed (after an error), else the event being edited, else empty
$evPosted = ($error && in_array($_POST['action'] ?? '', ['event_add', 'event_update'], true)) ? $_POST : null;
$evOld = fn($k, $def = '') => $evPosted ? (string) ($evPosted[$k] ?? '') : ($evEditing ? (string) ($evEditing[$k] ?? '') : $def);
$heroCustom = setting_get('hero_video');
$heroSrc = hero_video_sources()[0][0];
$positions = [
    'panel' => ['President', 'Vice President', 'General Secretary', 'Treasurer', 'Joint Secretary', 'Organizing Secretary'],
    'secretary' => ['Secretary of Human Resources', 'Secretary of Marketing, IT, Archive & Photography', 'Secretary of Performance (Music)',
        'Secretary of Performance (Dance)', 'Secretary of Finance', 'Secretary of Event Management & Logistics', 'Secretary of Administration',
        'Secretary of Creative', 'Secretary of Public Relation & Editorial', 'Secretary of Research & Development'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Media | BUCuC Admin</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/all.min.css" />
    <link rel="stylesheet" href="AdminCss/Dashboard.css">
    <style>
        .wrapx { padding: 2rem 1rem; color: var(--ink, rgb(var(--c-fixed-ffffff, 255 255 255))); }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .back-btn { text-decoration: none; color: inherit; padding: .5rem 1rem; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.3); border-radius: 999px; }
        .tabs, .tchips { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .tabs a, .tchips a { padding: .55rem 1.1rem; border-radius: 999px; border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: inherit; text-decoration: none; font-weight: 600; font-size: .9rem; }
        .tabs a.on, .tchips a.on { background: linear-gradient(100deg, rgb(var(--c-accent2, 92 255 176)), rgb(var(--c-accent, 45 226 230)) 50%, rgb(var(--c-primary, 139 92 255))); color: rgb(var(--c-surface-0a0620, 10 6 32)); border-color: transparent; }
        .tchips a small { font-weight: 700; color: rgb(var(--c-highlight, 255 207 125)); margin-left: .35rem; }
        .tchips a.on small { color: rgb(var(--c-paperAccent-3b1a66, 59 26 102)); }
        .panel { background: rgb(var(--c-surface, 14 9 36) / 0.72); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); border-radius: 18px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .panel.warn { border-color: rgb(var(--c-danger, 255 147 180) / 0.6); background: rgb(var(--c-surface-3c0a1e, 60 10 30) / 0.75); }
        .form-control, .form-select { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.2); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-control:focus { background: rgb(var(--c-fixed-000000, 0 0 0) / 0.35); color: rgb(var(--c-fixed-ffffff, 255 255 255)); }
        .form-label { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        .rule { display: inline-flex; gap: .5rem; align-items: center; padding: .45rem .9rem; border-radius: 12px; background: rgb(var(--c-highlight, 255 207 125) / 0.12); color: rgb(var(--c-highlight, 255 207 125)); font-size: .85rem; margin-bottom: 1rem; }
        .check-msg { font-size: .85rem; margin-top: .4rem; min-height: 1.2em; }
        .check-msg.bad { color: rgb(var(--c-danger, 255 147 180)); }
        .check-msg.good { color: rgb(var(--c-accent2, 92 255 176)); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
        .tile { border-radius: 14px; overflow: hidden; background: rgb(var(--c-fixed-000000, 0 0 0) / 0.25); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.1); display: flex; flex-direction: column; }
        .tile.editing { border-color: rgb(var(--c-accent, 45 226 230)); }
        .tile img { width: 100%; object-fit: cover; object-position: top; display: block; background: rgb(var(--c-surface-0a0724, 10 7 36)); }
        .tile .meta { padding: .6rem .7rem; font-size: .82rem; flex: 1; }
        .tile .meta b { display: block; font-size: .9rem; }
        .tile .meta small { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
        .tile .acts { display: flex; gap: .4rem; padding: 0 .7rem .7rem; }
        .tile form { margin: 0; }
        .tenure-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .75rem; }
        .tenure-head form { margin: 0; }
        .cur-badge { display: inline-block; padding: .2rem .7rem; border-radius: 999px; background: rgb(var(--c-accent2, 92 255 176) / 0.16); color: rgb(var(--c-accent2, 92 255 176)); font-size: .78rem; font-weight: 700; margin-left: .5rem; vertical-align: middle; }
        .ev-row { display: flex; gap: 1rem; align-items: center; padding: .7rem 0; border-bottom: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.08); }
        .ev-row.editing { background: rgb(var(--c-accent, 45 226 230) / 0.08); border-radius: 10px; }
        .ev-row picture { flex: none; display: block; width: 120px; }
        .ev-row img, .ev-row picture img { width: 120px; aspect-ratio: 3 / 2; object-fit: cover; border-radius: 10px; flex: none; display: block; }
        .ev-row .grow { flex: 1; min-width: 0; }
        .note { color: var(--mute, rgb(var(--c-muted, 170 163 210))); font-size: .85rem; }
        video.preview { width: 100%; max-width: 560px; aspect-ratio: 16 / 9; background: rgb(var(--c-fixed-000000, 0 0 0)); border-radius: 14px; }
        @media (max-width: 600px) { .ev-row { flex-wrap: wrap; } .ev-row img, .ev-row picture, .ev-row picture img { width: 100%; } }
    </style>
    <link rel="icon" type="image/png" sizes="32x32" href="images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="images/favicon-16.png">
    <link rel="apple-touch-icon" href="images/apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="vendor/cropperjs/cropper.min.css">
    <link rel="stylesheet" href="css/image-crop.css?v=<?= filemtime(__DIR__ . '/css/image-crop.css') ?>">
    <link rel="stylesheet" href="css/aurora.css">
</head>

<body class="au au-admin">
    <script src="js/aurora-sky.js"></script>
    <div class="container wrapx">
        <div class="page-header">
            <h2 class="m-0"><i class="fas fa-photo-video me-2"></i>Website Media</h2>
            <a href="<?= auth_dashboard_page() ?>" class="back-btn"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>

        <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <nav class="tabs">
            <?php foreach ($sections as $key => $label): ?>
                <a href="media.php?tab=<?= $key ?>" class="<?= $key === $tab ? 'on' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($tab === 'tenures'): ?>

            <?php if ($confirmNeeded): ?>
                <!-- 12-tenure limit: confirm before the oldest tenure is deleted -->
                <div class="panel warn" id="confirmDelete">
                    <h4 class="mb-2"><i class="fas fa-exclamation-triangle me-2"></i>Delete tenure <?= htmlspecialchars($confirmNeeded['label']) ?>?</h4>
                    <p>The site keeps at most <?= TENURE_LIMIT ?> tenures. Saving <b><?= htmlspecialchars($_POST['label'] ?? '') ?></b> will permanently delete the oldest tenure,
                        <b><?= htmlspecialchars($confirmNeeded['label']) ?></b>: its Presidential Panel, its Secretary Panel and all of their photo files. This cannot be undone.</p>
                    <form method="POST" action="media.php?tab=tenures" class="d-flex gap-2 flex-wrap">
                        <input type="hidden" name="action" value="tenure_create">
                        <input type="hidden" name="label" value="<?= htmlspecialchars($_POST['label'] ?? '') ?>">
                        <?php if (!empty($_POST['make_current'])): ?><input type="hidden" name="make_current" value="1"><?php endif; ?>
                        <input type="hidden" name="confirm_delete" value="<?= (int) $confirmNeeded['id'] ?>">
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-2"></i>Delete <?= htmlspecialchars($confirmNeeded['label']) ?> and save <?= htmlspecialchars($_POST['label'] ?? '') ?></button>
                        <a href="media.php?tab=tenures" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            <?php endif; ?>

            <div class="panel">
                <h4 class="mb-2"><i class="fas fa-layer-group me-2"></i>Tenures (<?= count($tenures) ?> of <?= TENURE_LIMIT ?>)</h4>
                <p class="note">Each tenure has its own Presidential Panel and Secretary Panel. The homepage shows the <b>current</b> tenure first; the others are listed as past panels.</p>
                <nav class="tchips">
                    <?php foreach ($tenures as $t): ?>
                        <a href="media.php?tab=tenures&t=<?= (int) $t['id'] ?>" class="<?= $selected && $t['id'] === $selected['id'] ? 'on' : '' ?>"><?= htmlspecialchars($t['label']) ?><?= $t['is_current'] ? '<small>current</small>' : '' ?></a>
                    <?php endforeach; ?>
                </nav>
                <form method="POST" action="media.php?tab=tenures" class="row g-2 align-items-end" id="newTenureForm"
                    <?php if ($oldestNow): ?>onsubmit="return confirm(<?= htmlspecialchars(json_encode('The site keeps at most ' . TENURE_LIMIT . ' tenures. Adding this one will permanently delete the oldest tenure, ' . $oldestNow['label'] . ', with all its photos. Continue?'), ENT_QUOTES) ?>)"<?php endif; ?>>
                    <input type="hidden" name="action" value="tenure_create">
                    <?php if ($oldestNow): ?><input type="hidden" name="confirm_delete" value="<?= (int) $oldestNow['id'] ?>"><?php endif; ?>
                    <div class="col-sm-4 col-md-3"><label class="form-label">New tenure</label>
                        <input type="text" name="label" class="form-control" placeholder="<?= $nextLabel ?>" pattern="\d{4}-\d{2}" required value="<?= $error && ($_POST['action'] ?? '') === 'tenure_create' ? htmlspecialchars($_POST['label'] ?? '') : '' ?>"></div>
                    <div class="col-sm-4 col-md-3"><label class="form-check-label"><input type="checkbox" name="make_current" value="1" class="form-check-input me-2"<?= $error && ($_POST['action'] ?? '') === 'tenure_create' && !empty($_POST['make_current']) ? ' checked' : '' ?>>Make it the current tenure</label></div>
                    <div class="col-sm-4 col-md-3"><button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-2"></i>Create tenure</button></div>
                </form>
                <?php if ($oldestNow): ?>
                    <p class="note mt-2 mb-0" style="color:rgb(var(--c-danger, 255 147 180))"><i class="fas fa-info-circle me-1"></i>The limit is reached: adding a tenure deletes <b><?= htmlspecialchars($oldestNow['label']) ?></b> (the oldest) and all its photos.</p>
                <?php endif; ?>
            </div>

            <?php if ($selected): ?>
                <div class="panel">
                    <div class="tenure-head">
                        <h4 class="m-0">Tenure <?= htmlspecialchars($selected['label']) ?><?= $selected['is_current'] ? '<span class="cur-badge">Current panel</span>' : '' ?></h4>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if (!$selected['is_current']): ?>
                                <form method="POST" action="media.php?tab=tenures">
                                    <input type="hidden" name="action" value="tenure_current">
                                    <input type="hidden" name="tenure_id" value="<?= (int) $selected['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-light"><i class="fas fa-star me-1"></i>Make current</button>
                                </form>
                                <form method="POST" action="media.php?tab=tenures" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Delete tenure ' . $selected['label'] . '? Its Presidential Panel, Secretary Panel and all their photo files will be deleted. This cannot be undone.'), ENT_QUOTES) ?>)">
                                    <input type="hidden" name="action" value="tenure_delete">
                                    <input type="hidden" name="tenure_id" value="<?= (int) $selected['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Delete tenure</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($selected['is_current'] && !$selected['panel'] && !$selected['secretaries'] && ($fb = tenure_public_fallback())): ?>
                        <p class="note mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>Nobody has been added to this tenure yet, so the homepage still shows tenure <?= htmlspecialchars($fb['label']) ?> as the current panel. It switches over as soon as someone is added here.</p>
                    <?php endif; ?>
                </div>

                <?php foreach (GROUPS as $grp => $g):
                    if (!can($g['perm'])) continue;
                    $people = $grp === 'panel' ? $selected['panel'] : $selected['secretaries'];
                    $editing = null;
                    // After an error, the form shows what was typed instead of starting empty
                    $keep = ($error && ($_POST['action'] ?? '') === 'person_save' && ($_POST['grp'] ?? '') === $grp) ? $_POST : null;
                    $keepId = $keep ? (int) ($keep['person_id'] ?? 0) : $editId;
                    foreach ($people as $p) if ((int) $p['id'] === $keepId) $editing = $p;
                    $val = fn($k) => $keep ? (string) ($keep[$k] ?? '') : (string) ($editing[$k] ?? '');
                    $rule = MEDIA_IMAGE_RULES[$g['rule']]; ?>
                    <div class="panel" id="<?= $grp ?>">
                        <h4 class="mb-2"><?= $g['label'] ?> · <?= htmlspecialchars($selected['label']) ?> (<?= count($people) ?>)</h4>
                        <p class="note mb-2"><?= $grp === 'panel' ? 'Homepage → "Meet Presidential Panel". Photos show in a 4:5 card, top-aligned.' : 'Homepage → "Meet Our Secretaries" carousel. Each slide is 250 × 330 px.' ?></p>
                        <div class="rule"><i class="fas fa-crop-alt"></i><?= $rule['label'] ?> · JPG, PNG or WebP · any photo: you crop it to fit</div>
                        <form method="POST" action="media.php?tab=tenures&t=<?= (int) $selected['id'] ?>" enctype="multipart/form-data" class="mb-3">
                            <input type="hidden" name="action" value="person_save">
                            <input type="hidden" name="grp" value="<?= $grp ?>">
                            <input type="hidden" name="tenure_id" value="<?= (int) $selected['id'] ?>">
                            <input type="hidden" name="person_id" value="<?= $editing ? (int) $editing['id'] : '' ?>">
                            <div class="row">
                                <div class="col-md-3 mb-2"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required maxlength="120" value="<?= htmlspecialchars($val('name')) ?>"></div>
                                <div class="col-md-3 mb-2"><label class="form-label">Position</label>
                                    <input type="text" name="position" class="form-control" list="pos-<?= $grp ?>" required maxlength="120" value="<?= htmlspecialchars($val('position')) ?>">
                                    <datalist id="pos-<?= $grp ?>"><?php foreach ($positions[$grp] as $pp): ?><option value="<?= htmlspecialchars($pp) ?>"><?php endforeach; ?></datalist>
                                </div>
                                <div class="col-md-3 mb-2"><label class="form-label">Facebook link (optional)</label><input type="url" name="facebook" class="form-control" placeholder="https://facebook.com/…" value="<?= htmlspecialchars($val('facebook')) ?>"></div>
                                <div class="col-md-3 mb-2"><label class="form-label">Photo<?= $editing ? ' (blank = keep)' : '' ?></label>
                                    <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp" <?= $editing ? '' : 'required' ?> data-rule="<?= $g['rule'] ?>">
                                    <div class="check-msg"></div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success"><i class="fas <?= $editing ? 'fa-save' : 'fa-upload' ?> me-2"></i><?= $editing ? 'Save ' . htmlspecialchars($editing['name']) : 'Add ' . $g['one'] ?></button>
                            <?php if ($editing): ?><a href="media.php?tab=tenures&t=<?= (int) $selected['id'] ?>#<?= $grp ?>" class="btn btn-secondary ms-2">Cancel</a><?php endif; ?>
                        </form>
                        <div class="grid">
                            <?php foreach ($people as $p): ?>
                                <div class="tile<?= $editing && $editing['id'] === $p['id'] ? ' editing' : '' ?>">
                                    <picture>
                                        <source srcset="<?= htmlspecialchars(mediaUrl(media_webp_url($p['image']))) ?>" type="image/webp">
                                        <img src="<?= htmlspecialchars(mediaUrl($p['image'])) ?>" alt="" style="aspect-ratio:<?= $grp === 'panel' ? '4/5' : '250/330' ?>" loading="lazy" onerror="this.onerror=null;this.src='images/placeholder.svg'">
                                    </picture>
                                    <div class="meta"><b><?= htmlspecialchars($p['name']) ?></b><small><?= htmlspecialchars($p['position']) ?></small></div>
                                    <div class="acts">
                                        <a href="media.php?tab=tenures&t=<?= (int) $selected['id'] ?>&edit=<?= (int) $p['id'] ?>#<?= $grp ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                        <form method="POST" action="media.php?tab=tenures&t=<?= (int) $selected['id'] ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Remove ' . $p['name'] . '? Their photo file is deleted too.'), ENT_QUOTES) ?>)">
                                            <input type="hidden" name="action" value="person_remove">
                                            <input type="hidden" name="person_id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Remove"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="panel"><p class="note m-0">No tenures yet. Create the first one above.</p></div>
            <?php endif; ?>

        <?php elseif ($tab === 'events'): ?>
            <div class="panel" id="eventForm">
                <h4 class="mb-2"><i class="fas <?= $evEditing ? 'fa-pen' : 'fa-plus' ?> me-2"></i><?= $evEditing ? 'Edit: ' . htmlspecialchars($evEditing['title']) : 'Add a past event' ?></h4>
                <p class="note">Every event shows on the Past Events page (newest first); the 5 newest also show on the homepage. In the description, highlights and labels you can write <b>**bold**</b> and <i>*italic*</i>.</p>
                <div class="rule"><i class="fas fa-crop-alt"></i><?= MEDIA_IMAGE_RULES['events']['label'] ?> · JPG, PNG or WebP · any photo: you crop it to fit · keep the subject near the middle</div>
                <form method="POST" action="media.php?tab=events" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?= $evEditing ? 'event_update' : 'event_add' ?>">
                    <?php if ($evEditing): ?><input type="hidden" name="id" value="<?= (int) $evEditing['id'] ?>"><?php endif; ?>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required maxlength="200" value="<?= htmlspecialchars($evOld('title')) ?>"></div>
                        <div class="col-md-3 mb-3"><label class="form-label">Date</label><input type="date" name="event_date" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($evOld('event_date')) ?>"></div>
                        <div class="col-md-3 mb-3"><label class="form-label">Organised by</label><input type="text" name="organizer" class="form-control" maxlength="120" value="<?= htmlspecialchars($evOld('organizer', 'By BUCuC')) ?>"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Place (optional)</label><input type="text" name="place" class="form-control" maxlength="200" placeholder="BRACU Auditorium" value="<?= htmlspecialchars($evOld('place')) ?>"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Photo<?= $evEditing ? ' (blank = keep the current one)' : '' ?></label>
                            <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp" <?= $evEditing ? '' : 'required' ?> data-rule="events">
                            <div class="check-msg"></div>
                            <?php if ($evEditing): ?><div class="d-flex gap-2 align-items-center mt-2"><picture><source srcset="<?= htmlspecialchars(mediaUrl(media_webp_url($evEditing['image']))) ?>" type="image/webp"><img src="<?= htmlspecialchars(mediaUrl($evEditing['image'])) ?>" alt="Current photo" style="width:120px;aspect-ratio:3/2;object-fit:cover;border-radius:8px" onerror="this.onerror=null;this.src='images/placeholder.svg'"></picture><small class="note">Current photo</small></div><?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3"><label class="form-label">Description (optional; blank line = new paragraph)</label><textarea name="description" class="form-control" rows="7"><?= htmlspecialchars($evOld('description')) ?></textarea></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Highlights (optional; one per line)</label><textarea name="highlights" class="form-control" rows="7"><?= htmlspecialchars($evOld('highlights')) ?></textarea></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Number tiles (optional; one per line: number | label)</label><textarea name="stats" class="form-control" rows="4" placeholder="400+ | Attendees&#10;17 | Performances"><?= htmlspecialchars($evOld('stats')) ?></textarea></div>
                    </div>
                    <button type="submit" class="btn btn-success"><i class="fas <?= $evEditing ? 'fa-save' : 'fa-upload' ?> me-2"></i><?= $evEditing ? 'Save changes' : 'Add event' ?></button>
                    <?php if ($evEditing): ?><a href="media.php?tab=events" class="btn btn-secondary ms-2">Cancel</a><?php endif; ?>
                </form>
            </div>
            <div class="panel">
                <h4 class="mb-2">Past events (<?= count($events) ?>)</h4>
                <p class="note">All events, newest first, including the older ones that used to be written into the page. Removing an older event keeps its original photo file (some are used elsewhere on the site); photos uploaded here are deleted with their event.</p>
                <?php foreach ($events as $ev): ?>
                    <div class="ev-row<?= $evEditing && (int) $evEditing['id'] === (int) $ev['id'] ? ' editing' : '' ?>" id="e<?= (int) $ev['id'] ?>">
                        <picture>
                            <source srcset="<?= htmlspecialchars(mediaUrl(media_webp_url($ev['image']))) ?>" type="image/webp">
                            <img src="<?= htmlspecialchars(mediaUrl($ev['image'])) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='images/placeholder.svg'">
                        </picture>
                        <div class="grow"><b><?= htmlspecialchars($ev['title']) ?></b><br><small class="note"><?= date('j F Y', strtotime($ev['event_date'])) ?><?= $ev['place'] ? ' · ' . htmlspecialchars($ev['place']) : '' ?></small></div>
                        <a href="past_events.php#<?= htmlspecialchars(event_anchor($ev)) ?>" target="_blank" class="btn btn-sm btn-secondary" title="View on the Past Events page" aria-label="View <?= htmlspecialchars($ev['title']) ?>"><i class="fas fa-external-link-alt"></i></a>
                        <a href="media.php?tab=events&edit=<?= (int) $ev['id'] ?>#eventForm" class="btn btn-sm btn-outline-light" title="Edit" aria-label="Edit <?= htmlspecialchars($ev['title']) ?>"><i class="fas fa-pen"></i></a>
                        <form method="POST" action="media.php?tab=events" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Remove "' . $ev['title'] . '" from Past Events?' . (event_photo_deletable($ev['image']) ? ' Its photo file is deleted too.' : ' (Its original photo file is kept.)')), ENT_QUOTES) ?>)">
                            <input type="hidden" name="action" value="event_remove">
                            <input type="hidden" name="id" value="<?= (int) $ev['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Remove" aria-label="Remove <?= htmlspecialchars($ev['title']) ?>"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if (!$events): ?><p class="note mb-0">No past events yet. Add the first one with the form above; it appears on the Past Events page and the homepage.</p><?php endif; ?>
            </div>

        <?php elseif ($tab === 'hero'): ?>
            <div class="panel">
                <h4 class="mb-2"><i class="fas fa-film me-2"></i>Homepage hero video</h4>
                <p class="note">Now playing: <?= $heroCustom ? 'an uploaded video' : 'the original BUCuC video' ?>. The hero video can't be deleted, only replaced, so the homepage always has one.</p>
                <video class="preview mb-3" src="<?= htmlspecialchars($heroSrc) ?>" controls muted playsinline preload="metadata"></video>
                <div class="rule"><i class="fas fa-ruler"></i><?= MEDIA_VIDEO_LABEL ?></div>
                <form method="POST" action="media.php?tab=hero" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="hero_upload">
                    <div class="row align-items-end">
                        <div class="col-md-8 mb-3"><label class="form-label">Replace with a new video</label>
                            <input type="file" name="video" class="form-control" accept="video/mp4" required data-video="1">
                            <div class="check-msg"></div>
                        </div>
                        <div class="col-md-4 mb-3"><button type="submit" class="btn btn-success w-100"><i class="fas fa-upload me-2"></i>Upload &amp; replace</button></div>
                    </div>
                </form>
                <?php if ($heroCustom): ?>
                    <form method="POST" action="media.php?tab=hero" onsubmit="return confirm('Replace the uploaded video with the original BUCuC video? The uploaded file is deleted.')">
                        <input type="hidden" name="action" value="hero_reset">
                        <button type="submit" class="btn btn-outline-light btn-sm"><i class="fas fa-undo me-2"></i>Replace with the original video</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script type="application/json" id="media-rules-data"><?= json_encode(media_rules_js()) ?></script>
    <div id="media-video-config" data-max-bytes="<?= MEDIA_VIDEO_MAX_BYTES ?>" data-max-seconds="<?= MEDIA_VIDEO_MAX_SECONDS ?>" hidden></div>
    <script src="vendor/cropperjs/cropper.min.js"></script>
    <script src="js/image-crop.js?v=<?= filemtime(__DIR__ . '/js/image-crop.js') ?>"></script>
    <script src="js/media-video-check.js?v=<?= filemtime(__DIR__ . '/js/media-video-check.js') ?>"></script>
</body>

</html>
