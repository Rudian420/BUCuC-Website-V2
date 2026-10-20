<?php
// Errors are never shown to visitors: they go to backend/logs/php_errors.log. Every page loads
// this file first (directly or through backend/includes/auth.php), so this applies site-wide.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
// Security headers (the root .htaccess sets them too, for files PHP doesn't serve)
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);

/** Plain "Site temporarily unavailable" page (or JSON for API calls); the details only go to the log. */
function site_unavailable($status = 503)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Retry-After: 300');
    }
    $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false
        || isset($_GET['action'])
        || (bool) preg_grep('/^content-type:\s*application\/json/i', headers_list());   // JSON APIs set this first
    if ($wantsJson) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'The site is temporarily unavailable. Please try again in a few minutes.']);
    } else {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta name="robots" content="noindex"><title>Site temporarily unavailable - BUCuC</title></head>'
            . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#06040f;color:#f3f0ff;font:16px/1.6 system-ui,sans-serif;padding:24px;box-sizing:border-box">'
            . '<div style="max-width:440px;text-align:center"><h1 style="font-size:26px;margin:0 0 10px">Site temporarily unavailable</h1>'
            . '<p style="color:#aaa3d2;margin:0">We\'re having a technical problem. Please try again in a few minutes.</p>'
            . '<p style="color:#aaa3d2;margin:12px 0 0;font-size:14px">BRAC University Cultural Club</p></div></body></html>';
    }
    exit();
}

if (PHP_SAPI !== 'cli') {
    // Anything uncaught: log it, show the plain page
    set_exception_handler(function ($e) {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        site_unavailable(500);
    });
}

require_once __DIR__ . '/config.php';

// The club runs on Dhaka time. MySQL sessions use the same offset so NOW(), TIMESTAMP columns
// and PHP's date() agree (lockdown times, reset-link expiry, login locks).
date_default_timezone_set('Asia/Dhaka');
class Database {
    private $host;
    private $dbname;
    private $user;
    private $pass;
    private $pdo;

    public function __construct() {
        $this->host = DB_HOST;
        $this->dbname = DB_NAME;
        $this->user = DB_USER;
        $this->pass = DB_PASS;

        $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . date('P') . "'",
        ];

        try {
            $this->pdo = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            // Details (driver missing, wrong password, server down) go to the log only
            error_log('Database connection failed: ' . $e->getMessage());
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
                exit(1);
            }
            site_unavailable(503);
        }
    }

    public function createConnection() {
        return $this->pdo;
    }
}
?>
