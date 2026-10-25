<?php
/**
 * Builds the favicons from the club logo (frontend/images/logopng.png). Run again after the logo changes:
 *   php -d extension=gd backend/scripts/make_favicons.php
 * Writes frontend/images/favicon-16.png, favicon-32.png, apple-touch-icon.png (180), icon-192.png,
 * icon-512.png, and favicon.ico (16 + 32 + 48) in the site root, where browsers look for it by default.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

$root = dirname(__DIR__, 2);
$src = imagecreatefrompng($root . '/frontend/images/logopng.png');
if (!$src) exit("Cannot read frontend/images/logopng.png\n");

/** Square icon of $size px: the logo on white, $pad of the size as margin (Apple/Android crop the corners). */
function icon($src, $size, $pad)
{
    $im = imagecreatetruecolor($size, $size);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $inner = (int) round($size * (1 - 2 * $pad));
    $off = (int) (($size - $inner) / 2);
    imagecopyresampled($im, $src, $off, $off, 0, 0, $inner, $inner, imagesx($src), imagesy($src));
    return $im;
}
function png_bytes($im)
{
    ob_start();
    imagepng($im, null, 9);
    return ob_get_clean();
}

$img = $root . '/frontend/images/';
$files = ['favicon-16.png' => [16, 0], 'favicon-32.png' => [32, 0], 'apple-touch-icon.png' => [180, 0.08], 'icon-192.png' => [192, 0.1], 'icon-512.png' => [512, 0.1]];
foreach ($files as $name => [$size, $pad]) {
    file_put_contents($img . $name, png_bytes(icon($src, $size, $pad)));
    echo "frontend/images/$name\n";
}

// favicon.ico with PNG-compressed entries (supported by every current browser)
$entries = [];
foreach ([16, 32, 48] as $s) $entries[$s] = png_bytes(icon($src, $s, 0));
$ico = pack('vvv', 0, 1, count($entries));
$offset = 6 + 16 * count($entries);
foreach ($entries as $s => $data) {
    $ico .= pack('CCCCvvVV', $s, $s, 0, 0, 1, 32, strlen($data), $offset);
    $offset += strlen($data);
}
$ico .= implode('', $entries);
file_put_contents($root . '/favicon.ico', $ico);
echo "favicon.ico\n";
