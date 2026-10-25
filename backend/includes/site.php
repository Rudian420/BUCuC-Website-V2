<?php
/**
 * Site-wide helpers (loaded by auth.php): shared DB connection, schema self-check,
 * settings, site lockdown, past events, and upload rules for media.
 */

require_once __DIR__ . '/../Database/db.php';
require_once __DIR__ . '/cache.php';

/**
 * Send HTTP headers instructing browsers, CDNs, and proxies never to cache this response.
 * Used on all admin pages, forms, login/reset flows, and during a lockdown.
 */
function site_send_never_cache_headers()
{
    if (headers_sent()) return;
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Sends noindex, nofollow headers to prevent search indexing of admin and private endpoints.
 */
function site_send_noindex_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Robots-Tag: noindex, nofollow');
}

/**
 * Returns the base absolute site URL with trailing slash stripped.
 * Driven by the single config constant APP_URL (default: 'https://bucuc.org').
 */
function site_base_url(): string
{
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    return 'https://bucuc.org';
}

/**
 * Returns the absolute URL for a given site-relative path.
 */
function site_absolute_url(string $path = ''): string
{
    $base = site_base_url();
    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . '/' . $path;
}

/**
 * Renders standard Open Graph, Twitter, Canonical, Meta Description, and Structured Data tags.
 * Share images are guaranteed JPEG/PNG at 1200x630 with absolute URLs (never WebP).
 *
 * @param array $meta [
 *     'title'       => string,
 *     'description' => string,
 *     'path'        => string, // e.g. 'frontend/index.php'
 *     'image_path'  => string, // e.g. 'frontend/images/og-share.jpg'
 *     'image_type'  => string, // 'image/jpeg' or 'image/png'
 *     'is_home'     => bool,   // includes Organization JSON-LD structured data
 * ]
 */
function site_render_seo_tags(array $meta): string
{
    $url = site_absolute_url($meta['path'] ?? 'frontend/index.php');
    $imagePath = $meta['image_path'] ?? 'frontend/images/og-share.jpg';
    $imageUrl = site_absolute_url($imagePath);
    $imageType = $meta['image_type'] ?? 'image/jpeg';
    $title = htmlspecialchars($meta['title'] ?? 'BRAC University Cultural Club | BUCuC', ENT_QUOTES, 'UTF-8');
    $desc = htmlspecialchars($meta['description'] ?? '', ENT_QUOTES, 'UTF-8');
    $isHome = !empty($meta['is_home']);

    $html = "\n    <!-- SEO & Canonical -->\n";
    $html .= "    <meta name=\"description\" content=\"{$desc}\">\n";
    $html .= "    <link rel=\"canonical\" href=\"{$url}\">\n";
    $html .= "\n    <!-- Open Graph / Facebook -->\n";
    $html .= "    <meta property=\"og:type\" content=\"website\">\n";
    $html .= "    <meta property=\"og:site_name\" content=\"BRAC University Cultural Club (BUCuC)\">\n";
    $html .= "    <meta property=\"og:title\" content=\"{$title}\">\n";
    $html .= "    <meta property=\"og:description\" content=\"{$desc}\">\n";
    $html .= "    <meta property=\"og:url\" content=\"{$url}\">\n";
    $html .= "    <meta property=\"og:image\" content=\"{$imageUrl}\">\n";
    $html .= "    <meta property=\"og:image:secure_url\" content=\"{$imageUrl}\">\n";
    $html .= "    <meta property=\"og:image:width\" content=\"1200\">\n";
    $html .= "    <meta property=\"og:image:height\" content=\"630\">\n";
    $html .= "    <meta property=\"og:image:type\" content=\"{$imageType}\">\n";
    $html .= "    <meta property=\"og:image:alt\" content=\"BRAC University Cultural Club\">\n";
    $html .= "\n    <!-- Twitter Card -->\n";
    $html .= "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    $html .= "    <meta name=\"twitter:title\" content=\"{$title}\">\n";
    $html .= "    <meta name=\"twitter:description\" content=\"{$desc}\">\n";
    $html .= "    <meta name=\"twitter:image\" content=\"{$imageUrl}\">\n";
    $html .= "    <meta name=\"twitter:image:alt\" content=\"BRAC University Cultural Club\">\n";

    if ($isHome) {
        $logoUrl = site_absolute_url('frontend/images/logopng.png');
        $siteUrl = site_absolute_url('');
        $jsonLd = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Organization',
            'name'          => 'BRAC University Cultural Club',
            'alternateName' => 'BUCuC',
            'url'           => $siteUrl . '/',
            'logo'          => $logoUrl,
            'sameAs'        => [
                'https://www.facebook.com/bucuc',
                'https://www.facebook.com/bucucarchive',
                'https://www.youtube.com/@bracuniversityculturalclub717',
                'https://www.instagram.com/bucuclive/',
            ],
        ];
        $html .= "\n    <!-- Organization Structured Data (No Event data) -->\n";
        $html .= "    <script type=\"application/ld+json\">\n"
            . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n    </script>\n";
    }

    return $html;
}

/** One PDO connection per request. */
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = (new Database())->createConnection();
    }
    return $pdo;
}

/* ------------------------------------------------------------------ schema */

/**
 * Creates the admin/site tables and columns if upgrade.sql hasn't been run yet.
 * A marker file in backend/logs skips the work after the first successful run.
 */
function site_ensure_schema(PDO $pdo)
{
    static $done = false;
    $marker = __DIR__ . '/../logs/.schema_v13';
    if ($done || is_file($marker)) {
        $done = true;
        return;
    }
    $done = true;
    $tables = [
        "CREATE TABLE IF NOT EXISTS admin_login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, ip VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_attempt_email (email, created_at), KEY idx_attempt_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS admin_password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL, used_at DATETIME NULL, ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reset_token (token_hash), KEY idx_reset_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS admin_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NULL, admin_name VARCHAR(100) NULL,
            actor_role VARCHAR(20) NULL, action VARCHAR(60) NOT NULL, target VARCHAR(255) NULL,
            target_role VARCHAR(20) NULL, details TEXT NULL, ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_activity_created (created_at), KEY idx_activity_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS admin_permissions (
            admin_id INT NOT NULL, permission VARCHAR(40) NOT NULL, allowed TINYINT(1) NOT NULL,
            updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (admin_id, permission)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS site_lockdown (
            id INT AUTO_INCREMENT PRIMARY KEY, starts_at DATETIME NOT NULL, notice TEXT NOT NULL,
            public_message VARCHAR(500) NULL, status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
            created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL, ended_by INT NULL, KEY idx_lockdown_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS site_settings (
            name VARCHAR(60) NOT NULL PRIMARY KEY, value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS site_events (
            id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, event_date DATE NOT NULL,
            place VARCHAR(200) NULL, description TEXT NULL, highlights TEXT NULL, image VARCHAR(255) NOT NULL,
            created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_event_date (event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS tenures (
            id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(20) NOT NULL, start_year SMALLINT NOT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 0, created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_tenure_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS tenure_people (
            id INT AUTO_INCREMENT PRIMARY KEY, tenure_id INT NOT NULL, grp VARCHAR(10) NOT NULL,
            name VARCHAR(120) NOT NULL, position VARCHAR(120) NOT NULL DEFAULT '', facebook VARCHAR(255) NULL,
            image VARCHAR(255) NULL, sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_people_tenure (tenure_id, grp, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS cron_schedule (
            job_key VARCHAR(50) NOT NULL PRIMARY KEY,
            last_run_at DATETIME NULL,
            last_status VARCHAR(20) NOT NULL DEFAULT 'never',
            last_output TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS applicant_rejection_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            semester VARCHAR(50) NOT NULL,
            rejected_count INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rejection_semester (semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS failed_sheet_deletions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id VARCHAR(50) NOT NULL,
            action VARCHAR(20) NOT NULL DEFAULT 'delete',
            failed_at DATETIME NOT NULL,
            last_error TEXT NULL,
            attempts INT NOT NULL DEFAULT 1,
            UNIQUE KEY uq_sheet_del_student (student_id, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    try {
        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }
        // Scope cleanup: drop unwanted scaffolding tables (interviews, tracking, application_events)
        $pdo->exec("DROP TABLE IF EXISTS interview_bookings, interview_slots, application_tracking, application_events");

        // admin_activity_log created before 2026-10-13 lacks these columns
        if (db_table_exists($pdo, 'admin_activity_log')) {
            $cols = $pdo->query("SHOW COLUMNS FROM admin_activity_log")->fetchAll(PDO::FETCH_COLUMN);
            foreach (['actor_role' => 'VARCHAR(20) NULL AFTER admin_name', 'target' => 'VARCHAR(255) NULL AFTER action', 'target_role' => 'VARCHAR(20) NULL AFTER target'] as $col => $def) {
                if (!in_array($col, $cols, true)) {
                    $pdo->exec("ALTER TABLE admin_activity_log ADD COLUMN $col $def");
                }
            }
        }
        // Member level (GB / ASB / Secretary Panel), added 2026-10-18
        if (db_table_exists($pdo, 'members')) {
            $mcols = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('position', $mcols, true)) {
                $pdo->exec("ALTER TABLE members ADD COLUMN position ENUM('GB','ASB','SB') NOT NULL DEFAULT 'GB' AFTER secondPriority");
            }
            db_add_index($pdo, 'members', 'idx_members_position', 'position');
        }
        // Indexes for frequent look-ups, added 2026-10-19
        if (db_table_exists($pdo, 'shortlisted_members')) {
            db_add_index($pdo, 'shortlisted_members', 'idx_short_uid', 'university_id');
            db_add_index($pdo, 'shortlisted_members', 'idx_short_gsuite', 'gsuite_email');
        }
        if (db_table_exists($pdo, 'admin_activity_log')) {
            db_add_index($pdo, 'admin_activity_log', 'idx_activity_admin', 'admin_id');
            db_add_index($pdo, 'admin_activity_log', 'idx_activity_role', 'actor_role');
        }
        // Rows that belong to something are removed with it, added 2026-10-19
        if (db_table_exists($pdo, 'tenure_people') && db_table_exists($pdo, 'tenures')) {
            db_add_fk($pdo, 'tenure_people', 'fk_people_tenure', 'tenure_id', 'tenures');
        }
        if (db_table_exists($pdo, 'admin_permissions') && db_table_exists($pdo, 'adminpanel')) {
            db_add_fk($pdo, 'admin_permissions', 'fk_perm_admin', 'admin_id', 'adminpanel');
        }
        if (db_table_exists($pdo, 'admin_password_resets') && db_table_exists($pdo, 'adminpanel')) {
            db_add_fk($pdo, 'admin_password_resets', 'fk_reset_admin', 'admin_id', 'adminpanel');
        }
        // Past events: every event editable, incl. the imported hand-written ones (2026-10-20)
        if (db_table_exists($pdo, 'site_events')) {
            $ecols = $pdo->query("SHOW COLUMNS FROM site_events")->fetchAll(PDO::FETCH_COLUMN);
            foreach (['organizer' => 'VARCHAR(120) NULL AFTER place', 'stats' => 'TEXT NULL AFTER highlights', 'anchor' => 'VARCHAR(60) NULL AFTER image',
                      'image_class' => 'VARCHAR(60) NULL AFTER anchor', 'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at'] as $col => $def) {
                if (!in_array($col, $ecols, true)) $pdo->exec("ALTER TABLE site_events ADD COLUMN $col $def");
            }
            db_add_index($pdo, 'site_events', 'idx_event_anchor', 'anchor');
        }

        // Clean up tracking_token columns safely if the tables and columns exist
        if (db_table_exists($pdo, 'pending_applications')) {
            $pcols = $pdo->query("SHOW COLUMNS FROM pending_applications")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('tracking_token', $pcols, true)) {
                try { $pdo->exec("ALTER TABLE pending_applications DROP INDEX idx_pending_token"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE pending_applications DROP COLUMN tracking_token"); } catch (\Throwable $e) {}
            }
        }
        if (db_table_exists($pdo, 'shortlisted_members')) {
            $scols = $pdo->query("SHOW COLUMNS FROM shortlisted_members")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('tracking_token', $scols, true)) {
                try { $pdo->exec("ALTER TABLE shortlisted_members DROP INDEX idx_short_token"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE shortlisted_members DROP COLUMN tracking_token"); } catch (\Throwable $e) {}
            }
        }

        @file_put_contents($marker, date('c'));
    } catch (\Throwable $e) {
        error_log('site_ensure_schema: ' . $e->getMessage());
    }
}

/** Checks if a table exists in the current database. Never throws. */
function db_table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

/** Adds an index unless one with that name exists. Never throws (logs instead). */
function db_add_index(PDO $pdo, $table, $name, $columns)
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $st->execute([$table, $name]);
        if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($columns)");
    } catch (\Throwable $e) {
        error_log("db_add_index $table.$name: " . $e->getMessage());
    }
}

/**
 * Adds a foreign key $table.$column → $parent.id with ON DELETE CASCADE unless it exists.
 * Rows already pointing at a deleted parent are removed first (they could never be shown).
 * Never throws (logs instead).
 */
function db_add_fk(PDO $pdo, $table, $name, $column, $parent)
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?");
        $st->execute([$table, $name]);
        if ($st->fetchColumn()) return;
        $pdo->exec("DELETE c FROM `$table` c LEFT JOIN `$parent` p ON p.id = c.`$column` WHERE p.id IS NULL");
        $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$parent` (id) ON DELETE CASCADE");
    } catch (\Throwable $e) {
        error_log("db_add_fk $table.$name: " . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ settings */

/**
 * A value that is safe to put in a spreadsheet cell: text starting with = + - @ (or a tab / line
 * break) gets a leading apostrophe, so Google Sheets / Excel never run it as a formula.
 */
function sheet_safe($v)
{
    if (!is_string($v) || $v === '') return $v;
    return strpos("=+-@\t\r\n", $v[0]) !== false ? "'" . $v : $v;
}

function setting_get($name, $default = null)
{
    try {
        site_ensure_schema(db());
        $stmt = db()->prepare("SELECT value FROM site_settings WHERE name = ?");
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : $v;
    } catch (\Throwable $e) {
        return $default;
    }
}

function setting_set($name, $value)
{
    site_ensure_schema(db());
    db()->prepare("INSERT INTO site_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([$name, $value]);
}

/* ------------------------------------------------------------------ public form token */

const FORM_TOKEN_HOURS = 12;   // how long an opened Apply form stays valid

/** Server secret for public form tokens, made once and kept in site_settings. */
function form_secret()
{
    static $secret = null;
    if ($secret === null) {
        $secret = (string) setting_get('form_secret', '');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            setting_set('form_secret', $secret);
        }
    }
    return $secret;
}

/**
 * Token for a public form (the Apply form): a time stamp signed with the server secret. It does
 * not use the session, so an admin logging in/out in another tab, an expired session or a student
 * who takes a long time to fill the form never breaks it. Bots still have to load the page first.
 */
function form_token($form)
{
    site_send_never_cache_headers();
    $t = time();
    return $t . '.' . hash_hmac('sha256', $form . '|' . $t, form_secret());
}

function form_token_ok($form, $token)
{
    if (!is_string($token) || !preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $token, $m)) return false;
    $age = time() - (int) $m[1];
    if ($age < -300 || $age > FORM_TOKEN_HOURS * 3600) return false;
    return hash_equals(hash_hmac('sha256', $form . '|' . $m[1], form_secret()), $m[2]);
}

/* ------------------------------------------------------------------ lockdown */

/** The scheduled-or-running lockdown, or null. Adds 'active' (bool) and 'starts_ts'. */
function lockdown_current()
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    try {
        site_ensure_schema(db());
        $row = db()->query("SELECT * FROM site_lockdown WHERE status = 'scheduled' ORDER BY id DESC LIMIT 1")->fetch();
        if ($row) {
            $row['starts_ts'] = strtotime($row['starts_at']);
            $row['active'] = $row['starts_ts'] <= time();
            $cache = $row;
        }
    } catch (\Throwable $e) {
        error_log('lockdown_current: ' . $e->getMessage());
    }
    return $cache;
}

function lockdown_active()
{
    $l = lockdown_current();
    return $l && $l['active'];
}

/**
 * Call at the top of every public page / endpoint. A lockdown takes the PUBLIC site offline:
 * visitors get the maintenance page with the Super Admin's notice (or a JSON 503 for APIs).
 * The admin panel is not affected. Signed-in admins can still preview the public site, with a
 * ribbon saying it is offline for visitors.
 */
function site_guard_public($json = false)
{
    if (!lockdown_active()) return;
    auth_start_session();
    if (!empty($_SESSION['admin']) && role_normalize($_SESSION['admin_role'] ?? null) !== null) {
        if (!$json) {
            $ribbon = '<div style="position:fixed;top:0;left:0;right:0;z-index:5000;padding:8px 16px;text-align:center;'
                . 'background:rgb(var(--c-danger-ff4f7b, 255 79 123));color:rgb(var(--c-fixed-ffffff, 255 255 255));font:600 14px/1.4 system-ui,sans-serif">🔒 Admin preview: the public website is offline for visitors (lockdown).</div>';
            ob_start(fn($html) => preg_replace_callback('/<body\b[^>]*>/i', fn($m) => $m[0] . $ribbon, $html, 1));
        }
        return;
    }
    http_response_code(503);
    header('Retry-After: 3600');
    site_send_never_cache_headers();
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'The website is temporarily closed.']);
        exit();
    }
    lockdown_render_page(lockdown_current());
    exit();
}

/** Full-page "temporarily closed" screen in the Aurora style. */
function lockdown_render_page($lockdown)
{
    $base = auth_frontend_url('');
    // The visitor message if one was written, otherwise the Super Admin's notice
    $msg = trim((string) ($lockdown['public_message'] ?? '')) ?: (trim((string) ($lockdown['notice'] ?? '')) ?: "We're making some changes. Please check back soon.");
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Temporarily Closed | BUCuC</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>images/favicon-16.png">
    <link rel="apple-touch-icon" href="<?= $base ?>images/apple-touch-icon.png">
    <link rel="manifest" href="<?= $base ?>site.webmanifest">
    <link rel="stylesheet" href="<?= $base ?>css/aurora.css">
    <style>
        .lock-wrap { position: relative; z-index: 2; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; }
        .lock-card { max-width: 520px; width: 100%; box-sizing: border-box; text-align: center; padding: 40px 28px; border-radius: 26px; background: rgb(var(--c-surface, 14 9 36) / 0.82); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); overflow-wrap: anywhere; }
        @media (max-width: 420px) { .lock-card { padding: 32px 20px; } }
        .lock-card img { width: 84px; height: 84px; object-fit: contain; background: rgb(var(--c-fixed-ffffff, 255 255 255)); border-radius: 20px; padding: 8px; }
        .lock-card h1 { margin: 20px 0 10px; font-size: clamp(26px, 6vw, 38px); }
        .lock-card p { color: var(--mute, rgb(var(--c-muted, 170 163 210))); line-height: 1.6; margin: 0 0 8px; }
        .lock-card small a { color: var(--mute, rgb(var(--c-muted, 170 163 210))); }
    </style>
</head>
<body class="au au-admin">
    <script src="<?= $base ?>js/aurora-sky.js"></script>
    <main class="lock-wrap">
        <div class="lock-card">
            <picture><source srcset="<?= $base ?>images/logopng.webp" type="image/webp"><img src="<?= $base ?>images/logopng.png" alt="BUCuC logo"></picture>
            <h1 class="au-gradient-text">Down for maintenance</h1>
            <p><?= nl2br(htmlspecialchars($msg)) ?></p>
            <p>We'll be back soon. — BRAC University Cultural Club</p>
            <small><a href="<?= $base ?>admin-login.php">Admin login</a></small>
        </div>
    </main>
</body>
</html><?php
}

/**
 * A message for the person on the page when something failed. Database and PHP errors are written
 * to the error log and replaced with a plain explanation (they can contain table names or SQL);
 * the site's own exceptions already carry a message meant for people and are shown as they are.
 * $what says what failed, e.g. 'Saving the venue' or 'Loading the list'.
 */
function friendly_error(\Throwable $e, $what)
{
    error_log($what . ': ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof \PDOException || $e instanceof \Error) {
        return "$what failed because of a server problem. Please try again in a minute; if it keeps happening, tell whoever looks after the website (details are in the error log).";
    }
    return $e->getMessage();
}

/**
 * The site's 404 page (Aurora style, link back home), then exit. Used for missing pages
 * (.htaccess ErrorDocument → 404.php) and for pages that must look like they don't exist
 * (recover.php without a token, sponsor pages for anyone but the Super Admin), so all three look the same.
 * Absolute /frontend/ paths: the page can be shown at any URL depth.
 */
function site_not_found()
{
    while (ob_get_level() > 0) ob_end_clean();
    if (function_exists('site_send_csp_header')) site_send_csp_header();
    site_send_noindex_headers();
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $base = '/frontend/';
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Page Not Found | BUCuC</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $base ?>images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $base ?>images/favicon-16.png">
    <link rel="apple-touch-icon" href="<?= $base ?>images/apple-touch-icon.png">
    <link rel="manifest" href="<?= $base ?>site.webmanifest">
    <link rel="stylesheet" href="<?= $base ?>css/aurora.css">
    <style>
        .lock-wrap { position: relative; z-index: 2; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; }
        .lock-card { max-width: 520px; width: 100%; box-sizing: border-box; text-align: center; padding: 40px 28px; border-radius: 26px; background: rgb(var(--c-surface, 14 9 36) / 0.82); border: 1px solid rgb(var(--c-fixed-ffffff, 255 255 255) / 0.14); overflow-wrap: anywhere; }
        @media (max-width: 420px) { .lock-card { padding: 32px 20px; } }
        .lock-card img { width: 84px; height: 84px; object-fit: contain; background: rgb(var(--c-fixed-ffffff, 255 255 255)); border-radius: 20px; padding: 8px; }
        .lock-card .code { margin: 18px 0 0; font: 800 clamp(44px, 12vw, 72px)/1 'Bricolage Grotesque', sans-serif; }
        .lock-card h1 { margin: 8px 0 10px; font-size: clamp(24px, 6vw, 34px); }
        .lock-card p { color: var(--mute, rgb(var(--c-muted, 170 163 210))); line-height: 1.6; margin: 0 0 22px; }
        .lock-card .au-btn { display: inline-flex; align-items: center; min-height: 44px; padding: 0 22px; }
    </style>
</head>
<body class="au au-site">
    <script src="<?= $base ?>js/aurora-sky.js"></script>
    <main class="lock-wrap">
        <div class="lock-card">
            <picture><source srcset="<?= $base ?>images/logopng.webp" type="image/webp"><img src="<?= $base ?>images/logopng.png" alt="BUCuC logo"></picture>
            <p class="code au-gradient-text" aria-hidden="true">404</p>
            <h1>Page Not Found</h1>
            <p>This page doesn't exist or has moved. Check the address, or start again from the homepage.</p>
            <a class="au-btn" href="<?= $base ?>index.php">Back to the homepage</a>
        </div>
    </main>
</body>
</html><?php
    exit();
}

/** Fixed notice with a live countdown, shown on every admin page while a lockdown is scheduled or running. */
function lockdown_banner_html()
{
    $l = lockdown_current();
    if (!$l) return '';
    $when = date('D j M Y, g:i a', $l['starts_ts']);
    $notice = nl2br(htmlspecialchars($l['notice']));
    $isSuper = auth_is_super();
    $manage = $isSuper ? ' <a href="' . auth_frontend_url('lockdown.php') . '" style="color:rgb(var(--c-highlight, 255 207 125));font-weight:700">Manage</a>' : '';
    $headline = $l['active']
        ? '🔒 The public website is offline (lockdown since ' . $when . '). The admin panel works as normal.'
        : '⚠ The public website goes offline on ' . $when . ' — <b id="auLockCountdown" data-ts="' . ($l['starts_ts'] * 1000) . '">…</b>';
    return '<div id="auLockBanner" role="alert" style="position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:3000;width:min(680px,calc(100% - 24px));'
        . 'background:rgb(var(--c-surface-140a28, 20 10 40) / .96);color:rgb(var(--c-text, 243 240 255));border:1px solid rgb(var(--c-highlight, 255 207 125) / .6);border-radius:16px;padding:14px 44px 14px 18px;'
        . 'box-shadow:0 20px 60px -20px rgb(var(--c-fixed-000000, 0 0 0) / .9);font:14px/1.5 \'Hanken Grotesk\',system-ui,sans-serif">'
        . '<div style="font-weight:700;color:rgb(var(--c-highlight, 255 207 125));margin-bottom:4px">' . $headline . $manage . '</div>'
        . '<div style="color:rgb(var(--c-text-d9d3f1, 217 211 241))">' . $notice . '</div>'
        . '<button type="button" aria-label="Hide notice" onclick="this.parentNode.remove()" style="position:absolute;top:8px;right:10px;background:none;border:0;color:rgb(var(--c-muted, 170 163 210));font-size:20px;cursor:pointer">×</button>'
        . '</div>'
        . '<script>(function(){var el=document.getElementById("auLockCountdown");if(!el)return;var ts=+el.dataset.ts;'
        . 'function tick(){var s=Math.max(0,Math.floor((ts-Date.now())/1000));var d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60),x=s%60;'
        . 'el.textContent=(d?d+"d ":"")+(d||h?h+"h ":"")+m+"m "+x+"s";if(s<=0){location.reload();return;}setTimeout(tick,1000);}tick();})();</script>';
}

/* ------------------------------------------------------------------ past events */

/**
 * Every past event (Website Media → Past Events): the Past Events page, newest first, and the
 * homepage cards. The stories that used to be written into past_events.html are imported once from
 * backend/Database/past_events_seed.json (events_seed()); after that they are ordinary rows that
 * can be edited or deleted like uploaded ones.
 */
function site_events_all()
{
    try {
        $pdo = db();
        site_ensure_schema($pdo);
        events_seed($pdo);
        return $pdo->query("SELECT * FROM site_events ORDER BY event_date DESC, id DESC")->fetchAll();
    } catch (\Throwable $e) {
        error_log('site_events_all: ' . $e->getMessage());
        return [];
    }
}

/** One-time import of the hand-written events. Deleted ones never come back (the flag stays set). */
function events_seed(PDO $pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting_get('events_seeded')) return;
    $data = json_decode((string) @file_get_contents(__DIR__ . '/../Database/past_events_seed.json'), true);
    if (!is_array($data['events'] ?? null)) return;
    $exists = $pdo->prepare("SELECT COUNT(*) FROM site_events WHERE anchor = ?");
    $ins = $pdo->prepare("INSERT INTO site_events (title, event_date, place, organizer, description, highlights, stats, image, image_class, anchor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($data['events'] as $e) {
        $exists->execute([$e['anchor']]);
        if ($exists->fetchColumn()) continue;
        $ins->execute([$e['title'], $e['event_date'], $e['place'] ?: null, $e['organizer'] ?: null, $e['description'] ?: null, $e['highlights'] ?: null, $e['stats'] ?: null, $e['image'], $e['image_class'] ?? null, $e['anchor']]);
    }
    setting_set('events_seeded', date('c'));
}

/** The id a Past Events card is reached by: the old anchor (old links and QR codes) or ev-{id}. */
function event_anchor(array $e)
{
    return $e['anchor'] ?: 'ev-' . (int) $e['id'];
}

/** Event text: escaped, with **bold** and *italic*; single line breaks kept. */
function event_rich($text)
{
    $h = htmlspecialchars((string) $text, ENT_QUOTES);
    $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
    $h = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $h);
    return nl2br($h, false);
}

/** "160+ | Attendees" lines → [['160+', 'Attendees'], …] */
function event_stats($text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', (string) $text) as $line) {
        if (strpos($line, '|') === false) continue;
        [$n, $l] = array_map('trim', explode('|', $line, 2));
        if ($n !== '' && $l !== '') $out[] = [$n, $l];
    }
    return $out;
}

/** Only photos uploaded in Website Media (images/events/) are ever deleted with an event; the
 *  original photos of the imported events are also used elsewhere (homepage carousel). */
function event_photo_deletable($path)
{
    return strpos(ltrim((string) $path, '/'), 'images/events/') === 0;
}

/** Homepage cards: the newest events. */
function site_event_cards($limit = 5)
{
    $cards = [];
    foreach (array_slice(site_events_all(), 0, $limit) as $e) {
        $cards[] = ['anchor' => event_anchor($e), 'image' => $e['image'], 'title' => $e['title'], 'date' => $e['event_date']];
    }
    return $cards;
}

/* ------------------------------------------------------------------ media rules */

/**
 * Image upload rules, matched to how each image is shown on the homepage. The pixel sizes are
 * MAXIMUMS: bigger files are refused, smaller ones are fine as long as the ratio matches.
 *   carousel  secretary photo (carousel slide 250 × 330 px, ≈ 3:4)   → 3:4, at most 750 × 1000
 *   panel     presidential panel card photo, CSS aspect-ratio 4 / 5 → 4:5, at most 800 × 1000
 *   events    past event card + event page banner                   → 3:2, at most 1500 × 1000
 */
// One place for every image upload area: the server checks 'ratio' and 'max'; the browser crop tool
// (js/image-crop.js) uses the same values, plus 'warn' = below this size the photo may look blurry
// in its slot (a warning only, never refused).
const MEDIA_IMAGE_RULES = [
    'carousel' => ['ratio' => [3, 4], 'max' => [750, 1000], 'warn' => [300, 400], 'label' => '3:4 portrait, at most 750 × 1000 px'],
    'panel'    => ['ratio' => [4, 5], 'max' => [800, 1000], 'warn' => [320, 400], 'label' => '4:5 portrait, at most 800 × 1000 px'],
    'events'   => ['ratio' => [3, 2], 'max' => [1500, 1000], 'warn' => [750, 500], 'label' => '3:2 landscape, at most 1500 × 1000 px'],
];

/** The image rules as the browser crop tool needs them (printed as window.MEDIA_RULES). */
function media_rules_js()
{
    $out = ['tolerance' => MEDIA_RATIO_TOLERANCE, 'maxBytes' => MEDIA_IMAGE_MAX_BYTES, 'rules' => []];
    foreach (MEDIA_IMAGE_RULES as $k => $r) {
        $out['rules'][$k] = ['ratio' => $r['ratio'], 'max' => $r['max'], 'warn' => $r['warn'], 'label' => $r['label']];
    }
    return $out;
}
const MEDIA_RATIO_TOLERANCE = 0.02;          // ±2 %
const MEDIA_IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const MEDIA_IMAGE_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

/**
 * Hero video rules, taken from the current hero video (video/bucuc1.mp4: H.264 MP4, 1280 × 720,
 * 16:9, 23.3 s, 5.8 MB; the AV1 fallback is 1920 × 1080).
 */
const MEDIA_VIDEO_MAX_BYTES = 6 * 1024 * 1024;
const MEDIA_VIDEO_MAX_SECONDS = 24;
const MEDIA_VIDEO_MIN = [1280, 720];
const MEDIA_VIDEO_MAX = [1920, 1080];
const MEDIA_VIDEO_LABEL = 'MP4 (H.264), 16:9, 1280 × 720 to 1920 × 1080, at most 24 seconds and 6 MB';

/** Human message for a PHP upload error code. */
function upload_error_message($code)
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'The file is larger than the server allows.';
        case UPLOAD_ERR_PARTIAL: return 'The upload was interrupted. Try again.';
        case UPLOAD_ERR_NO_FILE: return 'Choose a file to upload.';
        default: return 'The upload failed (code ' . (int) $code . ').';
    }
}

/**
 * Validates an uploaded image against a rule set. Returns [ok(bool), message, extension].
 */
function media_check_image(array $file, $ruleKey)
{
    $rule = MEDIA_IMAGE_RULES[$ruleKey];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [false, upload_error_message($file['error'] ?? UPLOAD_ERR_NO_FILE), null];
    if ($file['size'] > MEDIA_IMAGE_MAX_BYTES) return [false, 'The image is larger than 8 MB.', null];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset(MEDIA_IMAGE_TYPES[$info[2]])) return [false, 'Upload a JPG, PNG or WebP image.', null];
    [$w, $h] = $info;
    [$rw, $rh] = $rule['ratio'];
    $want = $rw / $rh;
    $got = $w / $h;
    if (abs($got - $want) / $want > MEDIA_RATIO_TOLERANCE) {
        return [false, "This image is $w × $h px (ratio " . round($got, 3) . "). It must be {$rule['label']} (ratio $rw:$rh). Crop it and try again.", null];
    }
    if ($w > $rule['max'][0] || $h > $rule['max'][1]) {
        return [false, "This image is $w × $h px, which is too big. It must be {$rule['label']}. Resize it and try again.", null];
    }
    return [true, '', MEDIA_IMAGE_TYPES[$info[2]]];
}

/**
 * Automatically optimizes an uploaded image, corrects EXIF orientation,
 * preserves PNG transparency, and creates a companion .webp file with the same base name.
 */
function media_optimize_and_webp($absPath)
{
    if (!is_file($absPath)) return false;
    $info = @getimagesize($absPath);
    if (!$info) return false;

    $mime = $info['mime'] ?? '';
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $im = null;
    if ($mime === 'image/jpeg' || in_array($ext, ['jpg', 'jpeg'])) {
        $im = @imagecreatefromjpeg($absPath);
    } elseif ($mime === 'image/png' || $ext === 'png') {
        $im = @imagecreatefrompng($absPath);
    } elseif ($mime === 'image/webp' || $ext === 'webp') {
        $im = @imagecreatefromwebp($absPath);
    }
    if (!$im) return false;

    // Apply EXIF orientation
    if (function_exists('exif_read_data') && ($mime === 'image/jpeg' || in_array($ext, ['jpg', 'jpeg']))) {
        $exif = @exif_read_data($absPath);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $im = imagerotate($im, 180, 0);
                    break;
                case 6:
                    $im = imagerotate($im, -90, 0);
                    break;
                case 8:
                    $im = imagerotate($im, 90, 0);
                    break;
            }
        }
    }

    // Scale down if dimensions are larger than 1920px max dimension
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > 1920 || $h > 1920) {
        $scale = min(1920 / $w, 1920 / $h);
        $scaled = imagescale($im, (int) round($w * $scale), (int) round($h * $scale));
        if ($scaled) {
            imagedestroy($im);
            $im = $scaled;
        }
    }

    $isPng = ($mime === 'image/png' || $ext === 'png');

    // Preserve PNG transparency
    imagealphablending($im, false);
    imagesavealpha($im, true);

    // Save optimized fallback
    if ($isPng) {
        imagepng($im, $absPath, 9);
    } elseif ($ext === 'webp') {
        imagewebp($im, $absPath, 82);
    } else {
        imagejpeg($im, $absPath, 82);
        if (filesize($absPath) > 1048576) {
            imagejpeg($im, $absPath, 75);
        }
    }

    // Create the WebP version with the same base name
    $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $absPath);
    if ($webpPath !== $absPath) {
        imagewebp($im, $webpPath, $isPng ? 85 : 82);
        @chmod($webpPath, 0644);
        if (filesize($webpPath) > 1048576) {
            imagewebp($im, $webpPath, 75);
        }
    }

    imagedestroy($im);
    return true;
}

/**
 * Returns the companion WebP URL for an image path or URL.
 * Preserves query strings (e.g. ?v=...).
 * If the image is SVG or already WebP, returns the original.
 */
function media_webp_url($url)
{
    if (!is_string($url) || $url === '') return $url;
    $parts = explode('?', $url, 2);
    $path = $parts[0];
    $query = isset($parts[1]) ? '?' . $parts[1] : '';
    if (preg_match('/\.(jpe?g|png)$/i', $path)) {
        $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
        return $webpPath . $query;
    }
    return $url;
}

/**
 * Renders a <picture> element with WebP <source> and fallback <img>.
 * Preserves all attributes on the <img> (class, id, alt, loading, data-*, onerror, style).
 */
function picture_img($src, $alt = '', $attrs = '', $webpSrc = null)
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    if (!is_string($src) || $src === '') return '';
    $webp = $webpSrc ?: media_webp_url($src);
    if ($webp === $src || preg_match('/^(https?:)?\/\//i', $src) || preg_match('/\.svg(\?.*)?$/i', $src)) {
        return '<img src="' . $h($src) . '" alt="' . $h($alt) . '"' . ($attrs ? ' ' . $attrs : '') . '>';
    }
    return '<picture><source srcset="' . $h($webp) . '" type="image/webp"><img src="' . $h($src) . '" alt="' . $h($alt) . '"' . ($attrs ? ' ' . $attrs : '') . '></picture>';
}


/**
 * Reads duration, size and video codec from an MP4 file without ffmpeg.
 * Returns ['duration' => seconds, 'width', 'height', 'codec'] or null if it isn't a readable MP4.
 */
function mp4_inspect($path)
{
    $f = @fopen($path, 'rb');
    if (!$f) return null;
    $size = filesize($path);
    $pos = 0;
    $ftyp = false;
    $moov = null;
    while ($pos + 8 <= $size) {
        fseek($f, $pos);
        $hdr = fread($f, 8);
        if (strlen($hdr) < 8) break;
        $len = unpack('N', substr($hdr, 0, 4))[1];
        $type = substr($hdr, 4, 4);
        $head = 8;
        if ($len === 1) {
            $len = unpack('J', fread($f, 8))[1];
            $head = 16;
        } elseif ($len === 0) {
            $len = $size - $pos;
        }
        if ($len < $head) break;
        if ($type === 'ftyp') $ftyp = true;
        if ($type === 'moov') {
            if ($len > 64 * 1024 * 1024) break;
            $moov = fread($f, $len - $head);
        }
        $pos += $len;
    }
    fclose($f);
    if (!$ftyp || $moov === null) return null;

    $children = function ($data) {
        $out = [];
        $p = 0;
        $n = strlen($data);
        while ($p + 8 <= $n) {
            $len = unpack('N', substr($data, $p, 4))[1];
            $type = substr($data, $p + 4, 4);
            $head = 8;
            if ($len === 1) {
                $len = unpack('J', substr($data, $p + 8, 8))[1];
                $head = 16;
            } elseif ($len === 0) {
                $len = $n - $p;
            }
            if ($len < $head || $p + $len > $n) break;
            $out[] = [$type, substr($data, $p + $head, $len - $head)];
            $p += $len;
        }
        return $out;
    };
    $find = function ($data, $type) use ($children) {
        foreach ($children($data) as [$t, $payload]) {
            if ($t === $type) return $payload;
        }
        return null;
    };

    $result = ['duration' => null, 'width' => 0, 'height' => 0, 'codec' => null];
    foreach ($children($moov) as [$type, $payload]) {
        if ($type === 'mvhd' && strlen($payload) >= 32) {
            if (ord($payload[0]) === 1) {
                $scale = unpack('N', substr($payload, 20, 4))[1];
                $dur = unpack('J', substr($payload, 24, 8))[1];
            } else {
                $scale = unpack('N', substr($payload, 12, 4))[1];
                $dur = unpack('N', substr($payload, 16, 4))[1];
            }
            $result['duration'] = $scale ? $dur / $scale : null;
        }
        if ($type === 'trak') {
            $mdia = $find($payload, 'mdia');
            $hdlr = $mdia !== null ? $find($mdia, 'hdlr') : null;
            if ($hdlr === null || substr($hdlr, 8, 4) !== 'vide') continue;
            $tkhd = $find($payload, 'tkhd');
            if ($tkhd !== null && strlen($tkhd) >= 8) {
                $result['width'] = (int) round(unpack('N', substr($tkhd, -8, 4))[1] / 65536);
                $result['height'] = (int) round(unpack('N', substr($tkhd, -4, 4))[1] / 65536);
            }
            $minf = $find($mdia, 'minf');
            $stbl = $minf !== null ? $find($minf, 'stbl') : null;
            $stsd = $stbl !== null ? $find($stbl, 'stsd') : null;
            if ($stsd !== null && strlen($stsd) >= 16) {
                $result['codec'] = substr($stsd, 12, 4);
            }
        }
    }
    return $result;
}

/** Validates an uploaded hero video. Returns [ok(bool), message]. */
function media_check_video(array $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return [false, upload_error_message($file['error'] ?? UPLOAD_ERR_NO_FILE)];
    if ($file['size'] > MEDIA_VIDEO_MAX_BYTES) {
        return [false, 'The video is ' . round($file['size'] / 1048576, 1) . ' MB. The limit is ' . (MEDIA_VIDEO_MAX_BYTES / 1048576) . ' MB.'];
    }
    $info = mp4_inspect($file['tmp_name']);
    if (!$info || !$info['width']) return [false, 'This is not a readable MP4 video. Export it as MP4 (H.264).'];
    if (!in_array($info['codec'], ['avc1', 'avc3'], true)) {
        return [false, 'The video codec is ' . ($info['codec'] ?: 'unknown') . '. It must be H.264 (export as MP4 / H.264).'];
    }
    [$w, $h] = [$info['width'], $info['height']];
    if (abs(($w / $h) - 16 / 9) / (16 / 9) > MEDIA_RATIO_TOLERANCE) return [false, "The video is $w × $h. It must be 16:9."];
    if ($w < MEDIA_VIDEO_MIN[0] || $h < MEDIA_VIDEO_MIN[1] || $w > MEDIA_VIDEO_MAX[0] || $h > MEDIA_VIDEO_MAX[1]) {
        return [false, "The video is $w × $h. It must be between 1280 × 720 and 1920 × 1080."];
    }
    if ($info['duration'] === null || $info['duration'] > MEDIA_VIDEO_MAX_SECONDS) {
        return [false, 'The video is ' . round((float) $info['duration'], 1) . ' seconds long. The limit is ' . MEDIA_VIDEO_MAX_SECONDS . ' seconds.'];
    }
    return [true, ''];
}

/** Hero video sources for the homepage: an uploaded MP4 replaces both defaults. */
function hero_video_sources()
{
    $custom = setting_get('hero_video');
    if ($custom && is_file(__DIR__ . '/../../frontend/' . $custom)) {
        return [[$custom . '?v=' . filemtime(__DIR__ . '/../../frontend/' . $custom), 'video/mp4']];
    }
    return [['video/bucuc1.mp4', 'video/mp4'], ['video/bucuc1.webm', 'video/webm']];
}

/** URL-safe file name piece from free text. */
function media_slug($text)
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return substr($s ?: 'file', 0, 50);
}

/* ------------------------------------------------------------------ secretary photo names */

/**
 * Reads name and position from a secretary photo file name, e.g. "AbheriRahman(SOP_music).jpg"
 * → Abheri Rahman / Secretary of Performance (Music). Used by the homepage carousel and Website Media.
 */
function parsePanel26SecretaryFilename($filename) {
    $base = pathinfo($filename, PATHINFO_FILENAME);

    // New format: Name(Department) — parentheses enclose the position
    if (preg_match('/^(.+?)\s*\((.+)\)\s*$/', $base, $m)) {
        $rawName = trim($m[1]);
        $rawDept = trim($m[2]);

        // Format name: split CamelCase, fix dot initials (e.g. "A.B" → "A. B")
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $rawName);
        $name = preg_replace('/\.([A-Za-z])/', '. $1', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);

        // SOP shortcuts
        if (preg_match('/^SOP_music$/i', $rawDept)) {
            $dept = 'Secretary of Performance (Music)';
        } elseif (preg_match('/^SOP_dance$/i', $rawDept)) {
            $dept = 'Secretary of Performance (Dance)';
        } else {
            $dept = $rawDept;
            // Fix known typos
            $dept = preg_replace('/^Srecretaryof/i',  'SecretaryOf', $dept);
            $dept = str_replace('Secretay', 'Secretary', $dept);
            // Normalize "SecretaryOf" / "Secretary Of" / "SecretaryOf " → "Secretary of "
            $dept = preg_replace('/Secretary\s*[Oo]f\s*/i', 'Secretary of ', $dept);
            // Expand MIAP acronym
            $dept = str_replace('MIAP', 'Marketing, IT, Archive & Photography', $dept);
            // Split CamelCase
            $dept = preg_replace('/([a-z])([A-Z])/', '$1 $2', $dept);
            // Ensure spaces around &
            $dept = preg_replace('/([A-Za-z])\s*&\s*([A-Za-z])/', '$1 & $2', $dept);
            // Collapse multiple spaces
            $dept = preg_replace('/\s+/', ' ', $dept);
            $dept = trim($dept);
        }

        return ['name' => $name, 'department' => $dept];
    }

    // Fallback: legacy underscore format (e.g. "Name_Department_Sub.jpg")
    $deptKeywords = [
        'Performence', 'Performance', 'Marketing', 'Creative', 'Finance',
        'Administration', 'Administation', 'Human', 'PublicRelation',
        'EventManagement', 'event', 'Research', 'Archive', 'IT'
    ];
    $parts = array_values(array_filter(array_map('trim', explode('_', $base)), fn($p) => $p !== ''));
    $deptStart = count($parts);
    for ($i = 1; $i < count($parts); $i++) {
        foreach ($deptKeywords as $kw) {
            if (stripos($parts[$i], $kw) === 0) { $deptStart = $i; break 2; }
        }
    }
    $nameParts = array_slice($parts, 0, $deptStart);
    $deptParts  = array_slice($parts, $deptStart);
    $nameWords = [];
    foreach ($nameParts as $p) {
        $w = preg_replace('/([a-z])([A-Z])/', '$1 $2', $p);
        $w = preg_replace('/\.([A-Z])/', '. $1', $w);
        $nameWords[] = $w;
    }
    $name = implode(' ', $nameWords);
    $deptResult = '';
    foreach ($deptParts as $idx => $p) {
        $isNewDept = false;
        foreach ($deptKeywords as $kw) {
            if (stripos(trim($p), $kw) === 0) { $isNewDept = true; break; }
        }
        $d = preg_replace('/([a-z])([A-Z])/', '$1 $2', $p);
        $d = preg_replace('/([A-Za-z])&([A-Za-z])/', '$1 & $2', $d);
        // Map short keywords to full department names
        $d = preg_replace('/^event$/i', 'Event Management & Logistics', $d);
        if ($idx === 0) {
            $deptResult = $d;
        } elseif ($isNewDept) {
            $deptResult .= ', ' . $d;
        } else {
            $deptResult .= ' ' . $d;
        }
    }
    $dept = $deptResult;
    $dept = str_replace('Performence', 'Performance', $dept);
    $dept = str_replace('Administation', 'Administration', $dept);
    return ['name' => trim($name), 'department' => trim($dept)];
}

/* ------------------------------------------------------------------ deleting media files */

/**
 * Deletes a media file (path relative to frontend/) from the server if nothing else still uses it:
 * no tenure photo, uploaded event, built-in event or hero-video setting. Only files inside
 * frontend/images and frontend/video can be deleted, and never the original hero video.
 * Returns true if the file was removed.
 */
function media_delete_file($rel)
{
    $rel = ltrim((string) $rel, '/');
    if ($rel === '' || in_array($rel, ['video/bucuc1.mp4', 'video/bucuc1.webm'], true)) return false;
    try {
        $pdo = db();
        site_ensure_schema($pdo);
        $q = $pdo->prepare("SELECT (SELECT COUNT(*) FROM tenure_people WHERE image = ?) + (SELECT COUNT(*) FROM site_events WHERE image = ?)");
        $q->execute([$rel, $rel]);
        if ((int) $q->fetchColumn() > 0) return false;
        if (setting_get('hero_video') === $rel) return false;
    } catch (\Throwable $e) {
        return false;
    }
    $front = realpath(__DIR__ . '/../../frontend');
    $path = realpath($front . '/' . $rel);
    foreach (['images', 'video'] as $root) {
        $base = realpath($front . '/' . $root);
        if ($path && $base && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
            $ok = @unlink($path);
            $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
            if ($webp !== $path && is_file($webp)) @unlink($webp);
            return $ok;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ panel tenures */

const TENURE_LIMIT = 12;   // the site keeps at most this many tenures

/** Secretaries of a photo folder, named and ordered the way the homepage always showed them. */
function secretaries_from_folder($folder)
{
    $dir = __DIR__ . '/../../frontend/' . $folder;
    $people = [];
    $seen = [];
    foreach (glob($dir . '*.{jpg,jpeg,png,webp,JPG,JPEG,PNG}', GLOB_BRACE) ?: [] as $file) {
        $base = pathinfo($file, PATHINFO_FILENAME);
        if (isset($seen[$base])) continue;
        $seen[$base] = true;
        $parsed = parsePanel26SecretaryFilename(basename($file));
        $people[] = ['name' => $parsed['name'], 'position' => $parsed['department'], 'image' => $folder . basename($file), 'facebook' => ''];
    }
    usort($people, fn($a, $b) => strcmp($a['image'], $b['image']));
    // Pin Arafat first, Zakaria second; the rest by department
    $pinned = [];
    $rest = [];
    foreach ($people as $m) {
        if (!isset($pinned[0]) && stripos($m['name'], 'Arafat') !== false) $pinned[0] = $m;
        elseif (!isset($pinned[1]) && stripos($m['name'], 'Zakaria') !== false) $pinned[1] = $m;
        else $rest[] = $m;
    }
    $rank = ['Marketing' => 0, 'Human' => 1, 'Event' => 2, 'Finance' => 3, 'Administration' => 4, 'Creative' => 5, 'Public' => 6, 'Performance' => 7, 'Research' => 8];
    $rankOf = function ($pos) use ($rank) {
        foreach ($rank as $kw => $r) if (stripos($pos, $kw) !== false) return $r;
        return 99;
    };
    usort($rest, fn($a, $b) => $rankOf($a['position']) <=> $rankOf($b['position']));
    ksort($pinned);
    return array_merge(array_values($pinned), $rest);
}

/** "2026-27" style label (and start year) from labels like "Current Panel (2026–2027)" or "2023–2025". */
function tenure_label_from_text($text, $fallbackYear)
{
    if (preg_match('/(\d{4})\D+(\d{2,4})/u', $text, $m)) {
        return [$m[1] . '-' . substr($m[2], -2), (int) $m[1]];
    }
    return [($fallbackYear - 1) . '-' . substr((string) $fallbackYear, -2), $fallbackYear - 1];
}

/**
 * Fills the tenure tables from backend/Api/members.json (and the current secretaries folder) the
 * first time, so the panels that were on the site before keep showing.
 */
function tenures_seed(PDO $pdo)
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    site_ensure_schema($pdo);
    if ((int) $pdo->query("SELECT COUNT(*) FROM tenures")->fetchColumn() > 0) return;
    $data = json_decode((string) @file_get_contents(__DIR__ . '/../Api/members.json'), true);
    if (empty($data['years'])) return;
    $pdo->beginTransaction();
    $insT = $pdo->prepare("INSERT INTO tenures (label, start_year, is_current) VALUES (?, ?, ?)");
    $insP = $pdo->prepare("INSERT INTO tenure_people (tenure_id, grp, name, position, facebook, image, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($data['years'] as $i => $y) {
        [$label, $start] = tenure_label_from_text($y['label'] ?? '', (int) $y['year']);
        $insT->execute([$label, $start, $i === 0 ? 1 : 0]);
        $tid = (int) $pdo->lastInsertId();
        $groups = [
            'panel' => $y['panel_members'] ?? [],
            'secretary' => isset($y['secretaries_folder']) ? secretaries_from_folder($y['secretaries_folder']) : ($y['secretaries'] ?? []),
        ];
        foreach ($groups as $grp => $people) {
            foreach (array_values($people) as $n => $m) {
                $fb = trim($m['facebook'] ?? '');
                $insP->execute([$tid, $grp, $m['name'] ?? '', $m['position'] ?? '', ($fb === '' || $fb === 'http://www.facebook.com/') ? null : $fb, $m['image'] ?? null, $n]);
            }
        }
    }
    $pdo->commit();
}

/** All tenures, current first, then newest to oldest. Each has 'panel' and 'secretaries' lists. */
function tenures_all($withPeople = true)
{
    try {
        $pdo = db();
        tenures_seed($pdo);
        $tenures = $pdo->query("SELECT * FROM tenures ORDER BY is_current DESC, start_year DESC, id DESC")->fetchAll();
        if (!$withPeople) return $tenures;
        $people = $pdo->query("SELECT * FROM tenure_people ORDER BY sort_order, id")->fetchAll();
        $by = [];
        foreach ($people as $p) $by[$p['tenure_id']][$p['grp']][] = $p;
        foreach ($tenures as &$t) {
            $t['panel'] = $by[$t['id']]['panel'] ?? [];
            $t['secretaries'] = $by[$t['id']]['secretary'] ?? [];
        }
        return $tenures;
    } catch (\Throwable $e) {
        error_log('tenures_all: ' . $e->getMessage());
        return [];
    }
}

/**
 * Tenures as the public site shows them. Tenures with nobody in them are left out, and while the
 * current tenure is still empty the newest tenure that has people is shown as the current panel
 * instead ('is_current' is set on the one shown first; 'fallback' is true in that case).
 */
function tenures_public()
{
    $all = tenures_all();
    $out = [];
    $currentEmpty = false;
    foreach ($all as $t) {
        if (!$t['panel'] && !$t['secretaries']) {
            if ($t['is_current']) $currentEmpty = true;
            continue;
        }
        $t['fallback'] = false;
        $out[] = $t;
    }
    if ($currentEmpty && $out) {
        // tenures_all() puts the current one first, then newest to oldest
        $out[0]['is_current'] = 1;
        $out[0]['fallback'] = true;
    }
    return $out;
}

/** The newest tenure with people, shown on the homepage while the current tenure is empty; null otherwise. */
function tenure_public_fallback()
{
    $pub = tenures_public();
    return ($pub && $pub[0]['fallback']) ? $pub[0] : null;
}

/** The tenure deleted automatically when one more is added at the limit (oldest, never the current one). */
function tenure_oldest_removable(PDO $pdo)
{
    return $pdo->query("SELECT * FROM tenures WHERE is_current = 0 ORDER BY start_year ASC, id ASC LIMIT 1")->fetch() ?: null;
}

/** Deletes a tenure, its people and every photo file that only it used. Returns the number of files removed. */
/**
 * Deletes a tenure's rows and returns its photo paths. The files are NOT touched here: call
 * media_delete_files() with the result only after the transaction has committed, so a failed
 * delete never leaves rows pointing at missing photos.
 */
function tenure_delete(PDO $pdo, $tenureId)
{
    $imgs = $pdo->prepare("SELECT DISTINCT image FROM tenure_people WHERE tenure_id = ? AND image IS NOT NULL AND image <> ''");
    $imgs->execute([$tenureId]);
    $files = $imgs->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare("DELETE FROM tenure_people WHERE tenure_id = ?")->execute([$tenureId]);
    $pdo->prepare("DELETE FROM tenures WHERE id = ?")->execute([$tenureId]);
    return $files;
}

/** Deletes upload files nothing refers to any more; returns how many were removed. */
function media_delete_files(array $files)
{
    $removed = 0;
    foreach ($files as $f) {
        if (media_delete_file($f)) $removed++;
    }
    return $removed;
}

/**
 * Rewrites backend/Api/members.json from the tenure tables so the public members API and the old
 * "previous panels" page show the same data.
 */
function tenures_export_json()
{
    $path = __DIR__ . '/../Api/members.json';
    $old = json_decode((string) @file_get_contents($path), true) ?: [];
    $years = [];
    $pc = 0;
    $sc = 0;
    foreach (tenures_public() as $t) {
        $map = fn($grp) => fn($p) => ['id' => media_slug($p['name']) . '_' . $t['start_year'], 'name' => $p['name'], 'position' => $p['position'],
            'image' => (string) $p['image'], 'facebook' => $p['facebook'] ?: 'http://www.facebook.com/', 'department' => $grp];
        $years[] = [
            'year' => (int) $t['start_year'] + 1,
            'label' => ($t['is_current'] ? 'Current Panel (' : '') . $t['label'] . ($t['is_current'] ? ')' : ''),
            'panel_members' => array_map($map('Panel'), $t['panel']),
            'secretaries' => array_map($map('Secretary'), $t['secretaries']),
        ];
        $pc += count($t['panel']);
        $sc += count($t['secretaries']);
    }
    $meta = array_merge($old['metadata'] ?? [], ['total_years' => count($years), 'total_panel_members' => $pc, 'total_secretaries' => $sc, 'last_updated' => date('Y-m-d')]);
    @file_put_contents($path, json_encode(['years' => $years, 'metadata' => $meta], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}

/* ------------------------------------------------------------------ homepage stats */

/**
 * Deprecated & removed in schema_v12: application_events dropped. Safe no-op.
 */
function application_event($kind, $universityId = null, $appliedAt = null)
{
}

const HOME_STATS = [
    'members'    => ['label' => 'Members to date'],
    'shows'      => ['label' => 'Shows organized'],
    'applicants' => ['label' => 'Applicants', 'semester' => true],
    'rejections' => ['label' => 'Rejections', 'semester' => true],
];

/**
 * Homepage stats, all typed in by the top 3 admins (Admin → Homepage Stats); nothing is worked out
 * from the database. Stored in site_settings.home_stats:
 *   {"members": {"value": 520, "visible": true}, …, "semester": "Summer 2026"}
 * (application_events keeps recording applications / rejections for internal use only.)
 */
function home_stats_settings()
{
    $saved = json_decode((string) setting_get('home_stats', ''), true) ?: [];
    $out = ['semester' => trim((string) ($saved['semester'] ?? ''))];
    foreach (HOME_STATS as $key => $def) {
        $v = $saved[$key]['value'] ?? ($saved[$key]['override'] ?? 0);   // 'override' = format before 19 Oct 2026
        $out[$key] = [
            'value'   => max(0, (int) $v),
            'visible' => array_key_exists('visible', $saved[$key] ?? []) ? (bool) $saved[$key]['visible'] : true,
        ];
    }
    return $out;
}

/** What the homepage shows: [key => ['label', 'value', 'visible']]. */
function home_stats()
{
    $settings = home_stats_settings();
    $out = [];
    foreach (HOME_STATS as $key => $def) {
        $label = $def['label'] . (!empty($def['semester']) && $settings['semester'] !== '' ? ' · ' . $settings['semester'] : '');
        $out[$key] = ['label' => $label, 'value' => $settings[$key]['value'], 'visible' => $settings[$key]['visible']];
    }
    return $out;
}

/* ------------------------------------------------------------------ alerts & monitoring (Phase 4) */

/**
 * Sends a technical alert e-mail to the active Super Admin only.
 * - Rate-limited to at most one alert email per hour (3600 seconds) via cache.
 * - Strips any potential applicant personal data (phone numbers, IDs, personal emails) from message.
 * - Never displayed in any menu, page, or activity log visible to visitors or non-super admins.
 * - Silently logs to error_log on failure; never throws.
 */
function site_alert_super_admin(string $subject, ?string $message = null, bool $bypassRateLimit = false): bool
{
    if ($message === null) {
        $message = $subject;
        $subject = 'BUCuC Site Alert';
    }
    try {
        $pdo = db();
        site_ensure_schema($pdo);
        $st = $pdo->prepare("SELECT email FROM adminpanel WHERE (role = 'super_admin' OR role = 'main_admin') AND (status = 'active' OR status IS NULL) ORDER BY id LIMIT 1");
        $st->execute();
        $superEmail = $st->fetchColumn();
        if (!$superEmail || !filter_var($superEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('site_alert_super_admin: No active super_admin email found.');
            return false;
        }

        cache_ensure_dir();
        $rateFile = CACHE_DIR . '/alert_super_admin_last.json';
        if (!$bypassRateLimit && is_file($rateFile)) {
            $rateData = json_decode(@file_get_contents($rateFile), true);
            if (!empty($rateData['last_sent']) && (time() - (int) $rateData['last_sent']) < 3600) {
                // Rate limited to 1 alert per hour
                return false;
            }
        }

        // Sanitize: strip phone numbers (01XXXXXXXXX), student IDs (8 digits), and external emails
        $clean = preg_replace('/\b01[3-9]\d{8}\b/', '[REDACTED_PHONE]', $message);
        $clean = preg_replace('/\b\d{8}\b/', '[REDACTED_ID]', $clean);
        $clean = preg_replace('/[a-zA-Z0-9._%+-]+@(?!g\.bracu\.ac\.bd|bucuc\.org|localhost|test\.local)[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $clean);

        require_once __DIR__ . '/auth_mail.php';
        $safeSubject = '[BUCuC Alert] ' . strip_tags($subject);
        $bodyHtml = '<div style="font-family:monospace;white-space:pre-wrap;background:#f8f9fa;padding:12px;border-radius:6px;border:1px solid #dee2e6;font-size:13px;line-height:1.5;">'
            . htmlspecialchars($clean, ENT_QUOTES, 'UTF-8')
            . '</div>'
            . '<p style="color:#6c757d;font-size:12px;margin-top:16px;">This automated technical alert is sent to the Super Admin only. At most one alert is sent per hour.</p>';
        $html = auth_mail_layout($safeSubject, $bodyHtml);
        $text = $clean . "\n\n(Sent to Super Admin. Max 1 alert/hr.)";

        $sent = send_auth_email($superEmail, 'Super Admin', $safeSubject, $html, $text);
        if ($sent) {
            @file_put_contents($rateFile, json_encode(['last_sent' => time()]), LOCK_EX);
        }
        return (bool) $sent;
    } catch (\Throwable $e) {
        error_log('site_alert_super_admin failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Scans php_errors.log for errors that repeated >= $threshold times within the last $windowSeconds (default 1 hr).
 * Triggers site_alert_super_admin() if any repeated error is detected.
 */
function site_check_php_error_log(int $threshold = 10, int $windowSeconds = 3600): array
{
    $logPath = defined('PHP_ERROR_LOG') ? PHP_ERROR_LOG : __DIR__ . '/../logs/php_errors.log';
    if (!is_file($logPath) || !is_readable($logPath)) return [];

    $fp = @fopen($logPath, 'rb');
    if (!$fp) return [];
    $size = filesize($logPath);
    $readLen = min($size, 256 * 1024);
    if ($readLen > 0) {
        fseek($fp, $size - $readLen);
        $content = (string) fread($fp, $readLen);
    } else {
        $content = '';
    }
    fclose($fp);
    if ($content === '') return [];

    $lines = explode("\n", $content);
    $now = time();
    $cutoff = $now - $windowSeconds;
    $counts = [];
    $samples = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $m)) {
            $ts = strtotime($m[1]);
            if ($ts !== false && $ts < $cutoff) continue;
            $msg = $m[2];
        } else {
            $msg = $line;
        }

        $norm = preg_replace('/(\bin\s+).+?(\.php\b)/', '$1[file]$2', $msg);
        $norm = preg_replace('/line\s+\d+/', 'line [N]', (string) $norm);
        $norm = preg_replace('/0x[a-fA-F0-9]+/', '0x...', (string) $norm);
        $norm = preg_replace('/\d+/', '#', (string) $norm);

        $key = substr((string) $norm, 0, 150);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        if (!isset($samples[$key])) {
            $samples[$key] = substr($msg, 0, 300);
        }
    }

    $repeated = [];
    foreach ($counts as $k => $c) {
        if ($c >= $threshold) {
            $repeated[] = ['count' => $c, 'sample' => $samples[$k]];
        }
    }

    if (!empty($repeated)) {
        $body = "The following PHP error(s) repeated {$threshold}+ times in the last hour:\n\n";
        foreach ($repeated as $r) {
            $body .= "- Repeated {$r['count']} times: {$r['sample']}\n\n";
        }
        site_alert_super_admin('Repeated PHP Error in Log', $body);
    }

    return $repeated;
}
