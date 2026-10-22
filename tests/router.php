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
}

return false;
