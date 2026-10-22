<?php
/**
 * Shrinks the site's photos in place (same file names, so no page needs changing).
 *   php -d extension=gd backend/scripts/compress_images.php --backup "E:/BUCuC_image_originals" [--dry-run] [folder]
 * folder defaults to frontend/images. Every original is first copied to --backup (same sub-folders;
 * an existing backup is never overwritten), so keep that folder OUTSIDE the website until the
 * compressed photos have been checked. A file is only replaced when the result is at least 10% smaller.
 *   JPEG: turned upright (phone photos store rotation separately), longest side at most 1000 px for
 *         panel/advisor portraits and 1920 px for everything else, quality 80, progressive.
 *   PNG:  same size limits, maximum lossless compression.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }
if (!function_exists('imagecreatefromjpeg')) exit("Run with: php -d extension=gd backend/scripts/compress_images.php ...\n");

$opts = getopt('', ['backup:', 'dry-run'], $rest);
$backup = rtrim(str_replace('\\', '/', $opts['backup'] ?? ''), '/');
$dry = isset($opts['dry-run']);
$root = str_replace('\\', '/', dirname(__DIR__, 2));
$dir = rtrim(str_replace('\\', '/', $argv[$rest] ?? $root . '/frontend/images'), '/');
if (!$backup) exit("--backup <folder outside the website> is required\n");
if (strpos($backup . '/', $root . '/') === 0) exit("The backup folder must be outside the website folder ($root)\n");

$before = $after = $changed = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;
    // favicons/app icons are already tiny and exact
    if (preg_match('~/(favicon-\d+|apple-touch-icon|icon-\d+)\.png$~', $path)) continue;
    $size = filesize($path);
    $before += $size;
    $max = preg_match('~/(Panel_[^/]+|Advisor)/~', $path) ? 1000 : 1920;

    $im = $ext === 'png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
    if (!$im) { echo "skip (unreadable) $path\n"; $after += $size; continue; }
    if ($ext !== 'png' && function_exists('exif_read_data')) {
        $o = (int) ((@exif_read_data($path)['Orientation']) ?? 1);
        if ($o === 3) $im = imagerotate($im, 180, 0);
        elseif ($o === 6) $im = imagerotate($im, -90, 0);
        elseif ($o === 8) $im = imagerotate($im, 90, 0);
    }
    $w = imagesx($im); $h = imagesy($im);
    $scale = min(1, $max / max($w, $h));
    if ($scale < 1) {
        $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        if ($ext === 'png') { imagealphablending($dst, false); imagesavealpha($dst, true); }
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $im = $dst;
    }
    ob_start();
    if ($ext === 'png') { imagesavealpha($im, true); imagepng($im, null, 9); }
    else { imageinterlace($im, true); imagejpeg($im, null, 80); }
    $data = ob_get_clean();

    if (strlen($data) > $size * 0.9) { $after += $size; continue; }
    $rel = substr($path, strlen($root) + 1);
    printf("%7.0f KB -> %6.0f KB  %s\n", $size / 1024, strlen($data) / 1024, $rel);
    $after += strlen($data);
    $changed++;
    if ($dry) continue;
    $copy = $backup . '/' . $rel;
    if (!is_dir(dirname($copy))) mkdir(dirname($copy), 0775, true);
    if (!is_file($copy) && !copy($path, $copy)) exit("Could not back up $rel, stopped (nothing else changed)\n");
    file_put_contents($path, $data);
}
printf("\n%d files %s. Total %.1f MB -> %.1f MB%s\n", $changed, $dry ? 'would change' : 'changed', $before / 1048576, $after / 1048576,
    $dry ? '' : "\nOriginals: $backup");
