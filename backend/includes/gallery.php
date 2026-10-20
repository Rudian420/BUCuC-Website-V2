<?php
/**
 * Performance gallery: YouTube videos shown on frontend/performances.php, managed in
 * frontend/video_gallery.php (permission manage_gallery). Only the video ID and details are stored;
 * no video file ever reaches the server. Each video is checked with YouTube's oEmbed endpoint
 * (no API key), which also gives the title and thumbnail.
 */

require_once __DIR__ . '/site.php';

const GALLERY_CATEGORIES = [
    'music_video'    => 'Music Video',
    'flash_mob'      => 'Flash Mob',
    'show_highlight' => 'Show Highlight',
    'other'          => 'Other',
];
const GALLERY_TITLE_MAX = 150;
const GALLERY_DESC_MAX = 600;
// YouTube's oEmbed endpoint; a test setup can point it at a fake
defined('GALLERY_OEMBED_URL') || define('GALLERY_OEMBED_URL', 'https://www.youtube.com/oembed');

function gallery_ensure(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    site_ensure_schema($pdo);   // tenures must exist for the foreign key
    $pdo->exec("CREATE TABLE IF NOT EXISTS gallery_videos (
        id INT AUTO_INCREMENT PRIMARY KEY, youtube_id CHAR(11) NOT NULL, start_seconds INT NOT NULL DEFAULT 0,
        title VARCHAR(150) NOT NULL, category VARCHAR(20) NOT NULL DEFAULT 'other', video_date DATE NULL, tenure_id INT NULL,
        description TEXT NULL, thumbnail VARCHAR(255) NOT NULL, is_short TINYINT(1) NOT NULL DEFAULT 0,
        featured TINYINT(1) NOT NULL DEFAULT 0, hidden TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0,
        created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_gallery_order (hidden, featured, sort_order), KEY idx_gallery_yt (youtube_id),
        CONSTRAINT fk_gallery_tenure FOREIGN KEY (tenure_id) REFERENCES tenures (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Reads a YouTube link: youtube.com/watch?v=, youtu.be/, /shorts/, /embed/, /live/, /v/, m. and
 * music. hosts, youtube-nocookie.com, with any extra parameters (t= / start= timestamps, list=,
 * si=, feature=…). Returns ['id', 'start' (seconds), 'short' (bool)] or null if it isn't one.
 */
function youtube_parse($link)
{
    $link = trim((string) $link);
    if ($link === '' || strlen($link) > 500) return null;
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $link)) $link = 'https://' . ltrim($link, '/');
    $u = parse_url($link);
    if (!$u || empty($u['host']) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true)) return null;
    $host = strtolower($u['host']);
    $path = $u['path'] ?? '';
    parse_str($u['query'] ?? '', $q);
    $id = null;
    $short = false;
    if ($host === 'youtu.be') {
        $id = explode('/', trim($path, '/'))[0] ?? null;
    } elseif (preg_match('~^((www|m|music)\.)?youtube\.com$~', $host) || preg_match('~^(www\.)?youtube-nocookie\.com$~', $host)) {
        if (preg_match('~^/(shorts|embed|live|v|e)/([^/?#]+)~', $path, $m)) {
            $id = $m[2];
            $short = $m[1] === 'shorts';
        } elseif (rtrim($path, '/') === '/watch') {
            $id = is_string($q['v'] ?? null) ? $q['v'] : null;
        }
    } else {
        return null;
    }
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) return null;
    $start = 0;
    foreach (['t', 'start'] as $k) {
        if (isset($q[$k]) && is_string($q[$k])) { $start = youtube_seconds($q[$k]); break; }
    }
    // youtu.be/ID#t=90 style
    if (!$start && !empty($u['fragment']) && preg_match('/(?:^|&)t=([0-9hms]+)/', $u['fragment'], $m)) $start = youtube_seconds($m[1]);
    return ['id' => $id, 'start' => $start, 'short' => $short];
}

/** "90", "90s", "1m30s", "1h2m3s" → seconds. */
function youtube_seconds($t)
{
    $t = strtolower(trim($t));
    if (preg_match('/^\d+$/', $t)) return min((int) $t, 86400);
    if (!preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $t, $m) || $t === '') return 0;
    return min(((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0), 86400);
}

/**
 * Asks YouTube's oEmbed endpoint about a video. Returns [true, ['title', 'thumbnail', 'vertical']]
 * or [false, message]. Private, deleted and embedding-disabled videos are refused here.
 */
function youtube_check($id)
{
    if (!function_exists('curl_init')) return [false, 'This server cannot reach YouTube (PHP cURL is missing).'];
    $url = GALLERY_OEMBED_URL . '?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'BUCuC website (performance gallery)']);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log("youtube_check $id: $err");
        return [false, "Couldn't reach YouTube to check this video. Try again in a minute."];
    }
    if ($status === 401 || $status === 403) {
        return [false, "YouTube won't let this video be shown on other websites: it is private, or its owner has turned off embedding. Choose another video, or ask the owner to allow embedding."];
    }
    if ($status === 400 || $status === 404) {
        return [false, "This video doesn't exist any more, or it is private. Check the link on YouTube."];
    }
    $j = json_decode((string) $body, true);
    if ($status !== 200 || !is_array($j) || empty($j['title'])) {
        error_log("youtube_check $id: HTTP $status");
        return [false, "YouTube didn't confirm this video (HTTP $status). Try again in a minute."];
    }
    $thumb = (string) ($j['thumbnail_url'] ?? '');
    $th = strtolower((string) parse_url($thumb, PHP_URL_HOST));
    if (!preg_match('~^https://~', $thumb) || !in_array($th, ['i.ytimg.com', 'i9.ytimg.com', 'img.youtube.com'], true)) {
        $thumb = "https://i.ytimg.com/vi/$id/hqdefault.jpg";
    }
    $vertical = (int) ($j['height'] ?? 0) > (int) ($j['width'] ?? 0);
    return [true, ['title' => mb_substr(trim((string) $j['title']), 0, GALLERY_TITLE_MAX), 'thumbnail' => mb_substr($thumb, 0, 255), 'vertical' => $vertical]];
}

/** Every video, display order (featured first, then position). Public pages pass $visibleOnly. */
function gallery_videos(PDO $pdo, $visibleOnly = false)
{
    gallery_ensure($pdo);
    return $pdo->query("SELECT g.*, t.label AS tenure_label, t.start_year AS tenure_year FROM gallery_videos g
                        LEFT JOIN tenures t ON t.id = g.tenure_id" . ($visibleOnly ? " WHERE g.hidden = 0" : '') . "
                        ORDER BY g.featured DESC, g.sort_order ASC, g.id DESC")->fetchAll();
}

/** Saves a new order: $ids in display order (any id not listed keeps its place after them). */
function gallery_set_order(PDO $pdo, array $ids)
{
    $all = array_map('intval', array_column(gallery_videos($pdo), 'id'));
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => in_array($i, $all, true))));
    $order = array_merge($ids, array_values(array_diff($all, $ids)));
    $st = $pdo->prepare("UPDATE gallery_videos SET sort_order = ? WHERE id = ?");
    foreach ($order as $i => $id) $st->execute([$i + 1, $id]);
}

/** Moves one video to a 1-based position in the admin list. */
function gallery_move(PDO $pdo, $id, $position)
{
    $ids = array_map('intval', array_column(gallery_videos($pdo), 'id'));
    $id = (int) $id;
    if (!in_array($id, $ids, true)) return;
    $ids = array_values(array_diff($ids, [$id]));
    array_splice($ids, max(0, min(count($ids), (int) $position - 1)), 0, [$id]);
    gallery_set_order($pdo, $ids);
}

/** "When" a video belongs to, for the public filter: tenure label, else the year of its date, else ''. */
function gallery_when(array $v)
{
    if (!empty($v['tenure_label'])) return 'Tenure ' . $v['tenure_label'];
    if (!empty($v['video_date'])) return substr($v['video_date'], 0, 4);
    return '';
}

/** One line under the title: "12 March 2026 · Tenure 2025-26". */
function gallery_meta(array $v)
{
    $parts = [];
    if (!empty($v['video_date'])) $parts[] = date('j F Y', strtotime($v['video_date']));
    if (!empty($v['tenure_label'])) $parts[] = 'Tenure ' . $v['tenure_label'];
    return implode(' · ', $parts);
}

/** youtube-nocookie.com player address, used only after a visitor clicks. */
function gallery_embed_url(array $v)
{
    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($v['youtube_id']) . '?autoplay=1&playsinline=1&rel=0' . ((int) $v['start_seconds'] > 0 ? '&start=' . (int) $v['start_seconds'] : '');
}
