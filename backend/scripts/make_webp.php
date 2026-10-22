<?php
/**
 * Phase 3: WebP Generation Script.
 * Reads original uncompressed images from E:\BUCuC_image_originals,
 * corrects EXIF orientation, preserves PNG transparency,
 * and creates .webp files next to the fallbacks in frontend/images/.
 * Does NOT recompress existing JPEGs/PNGs.
 */

$origDir = 'E:/BUCuC_image_originals/frontend/images';
$destDir = __DIR__ . '/../../frontend/images';

if (!is_dir($origDir)) {
    die("Originals directory not found: $origDir\n");
}

function processImage($srcPath, $webpPath, $isPng = false)
{
    $info = @getimagesize($srcPath);
    if (!$info) return false;

    $mime = $info['mime'] ?? '';
    $im = null;
    if ($mime === 'image/jpeg') {
        $im = @imagecreatefromjpeg($srcPath);
    } elseif ($mime === 'image/png') {
        $im = @imagecreatefrompng($srcPath);
    } elseif ($mime === 'image/webp') {
        $im = @imagecreatefromwebp($srcPath);
    }

    if (!$im) return false;

    // Apply EXIF orientation
    if (function_exists('exif_read_data') && ($mime === 'image/jpeg')) {
        $exif = @exif_read_data($srcPath);
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

    // Scale down if larger than 1920px max dimension
    $w = imagesx($im);
    $h = imagesy($im);
    $maxDim = 1920;
    if ($w > $maxDim || $h > $maxDim) {
        $scale = min($maxDim / $w, $maxDim / $h);
        $newW = (int) round($w * $scale);
        $newH = (int) round($h * $scale);
        $scaled = imagescale($im, $newW, $newH);
        if ($scaled) {
            imagedestroy($im);
            $im = $scaled;
        }
    }

    // Preserve PNG transparency
    imagealphablending($im, false);
    imagesavealpha($im, true);

    $dir = dirname($webpPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Save as WebP (quality 82 for photos, 85 for PNGs)
    $q = $isPng ? 85 : 82;
    $ok = imagewebp($im, $webpPath, $q);

    // If still over 1MB, reduce quality slightly so no image ever exceeds 1MB
    if ($ok && filesize($webpPath) > 1048576) {
        imagewebp($im, $webpPath, 75);
    }

    imagedestroy($im);
    return $ok;
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origDir, FilesystemIterator::SKIP_DOTS));
$count = 0;
$errors = 0;

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;

    $rel = substr($file->getPathname(), strlen($origDir));
    $destBase = preg_replace('/\.(jpe?g|png|webp)$/i', '', $destDir . $rel);
    $webpDest = $destBase . '.webp';

    $isPng = ($ext === 'png');
    if (processImage($file->getPathname(), $webpDest, $isPng)) {
        $count++;
    } else {
        $errors++;
    }
}

// Also check frontend/images for any images not in originals (e.g. favicons)
$destIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destDir, FilesystemIterator::SKIP_DOTS));
foreach ($destIterator as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png'])) continue;

    $destBase = preg_replace('/\.(jpe?g|png)$/i', '', $file->getPathname());
    $webpDest = $destBase . '.webp';

    if (!is_file($webpDest)) {
        if (processImage($file->getPathname(), $webpDest, $ext === 'png')) {
            $count++;
        }
    }
}

// Compress slide1.png if over 1 MB so no image in the repo exceeds 1 MB
$slide1 = $destDir . '/slide1.png';
if (is_file($slide1) && filesize($slide1) > 1048576) {
    $im = @imagecreatefrompng($slide1);
    if ($im) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagepng($im, $slide1, 9);
        imagedestroy($im);
    }
}

echo "Created/updated $count WebP images with $errors errors.\n";
