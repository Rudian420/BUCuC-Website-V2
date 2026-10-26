<?php
/**
 * Test server router: serves images with their native MIME type
 * (no Accept-based rewriting, no Vary: Accept; WebP is selected via <picture> tags).
 *
 * For all other requests (PHP scripts, CSS, JS, fonts, etc.):
 * - Return false so the PHP built-in server handles them normally.
 */

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$docRoot = realpath(__DIR__ . '/..');
$filePath = realpath($docRoot . $uri);

if ($uri === '/') {
    return false;
}

// Test hook for maintenance page HTTP testing
if ($uri === '/__test_503') {
    require_once __DIR__ . '/../backend/Database/db.php';
    site_unavailable(503);
    return true;
}

// Sitemap and robots handler for test server
if ($uri === '/sitemap.xml') {
    $sitemapPath = $docRoot . '/sitemap.xml';
    if (is_file($sitemapPath)) {
        $content = file_get_contents($sitemapPath);
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://127.0.0.1:8099';
        $content = str_replace(['https://bucuc.online', 'https://bucuc.org'], $base, $content);
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Length: ' . strlen($content));
        echo $content;
        return true;
    }
}

if ($uri === '/robots.txt') {
    $robotsPath = $docRoot . '/robots.txt';
    if (is_file($robotsPath)) {
        $content = file_get_contents($robotsPath);
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://127.0.0.1:8099';
        $content = str_replace(['https://bucuc.online', 'https://bucuc.org'], $base, $content);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: ' . strlen($content));
        echo $content;
        return true;
    }
}

// Block internal protected directories and sensitive extensions (mirroring Apache .htaccess rules)
if (preg_match('#^/backend/(backups|cache|logs|Database|config|vendor)#i', $uri) ||
    preg_match('#\.(sql|gz|zip|log|token|csv|xlsx|md|phar|ini|py|sh)$#i', $uri)) {
    http_response_code(403);
    echo "Access denied (protected by .htaccess)\n";
    return true;
}

// Resolve static assets served from /frontend when requested at root
if ((!$filePath || !is_file($filePath)) && is_file($docRoot . '/frontend' . $uri)) {
    $filePath = realpath($docRoot . '/frontend' . $uri);
}

if ($filePath && strpos($filePath, $docRoot) === 0 && is_file($filePath)) {
    if (preg_match('/\.(jpe?g|png)$/i', $filePath, $m)) {
        header('Cache-Control: max-age=31536000, public, immutable');
        $mime = strtolower($m[1]) === 'png' ? 'image/png' : 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    if (preg_match('/\.webp$/i', $filePath)) {
        header('Cache-Control: max-age=31536000, public, immutable');
        header('Content-Type: image/webp');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    if (preg_match('/\.css$/i', $filePath)) {
        header('Content-Type: text/css; charset=utf-8');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    if (preg_match('/\.js$/i', $filePath)) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    if (preg_match('/\.woff2$/i', $filePath)) {
        header('Content-Type: font/woff2');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    if (preg_match('/\.woff$/i', $filePath)) {
        header('Content-Type: font/woff');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    if (preg_match('/\.svg$/i', $filePath)) {
        header('Content-Type: image/svg+xml');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    if (preg_match('/\.webmanifest$/i', $filePath)) {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        return true;
    }
    return false;
}

// Missing files: mirror Apache's ErrorDocument 404 /frontend/404.php
require_once __DIR__ . '/../backend/includes/site.php';
site_not_found();
return true;
