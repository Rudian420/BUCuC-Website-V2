<?php
/**
 * Generates Open Graph & Twitter share images at exactly 1200x630 px.
 * Ensures images are JPEG and PNG (never WebP) with crisp branding.
 */

$outDir = __DIR__ . '/../../frontend/images';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

function find_ttf_font(bool $bold = false): ?string {
    $candidates = $bold ? [
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
    ] : [
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
    ];

    foreach ($candidates as $font) {
        if (file_exists($font)) return $font;
    }
    return null;
}

function make_og_image(string $title1, string $title2, string $subtitle, string $badge, string $filenameBase, string $outDir): void {
    $w = 1200;
    $h = 630;
    $im = imagecreatetruecolor($w, $h);

    // Dark sleek background gradient (#06040f -> #160b2e)
    for ($y = 0; $y < $h; $y++) {
        $factor = $y / $h;
        $r = (int) (6 + (22 - 6) * $factor);
        $g = (int) (4 + (11 - 4) * $factor);
        $b = (int) (15 + (46 - 15) * $factor);
        $col = imagecolorallocate($im, $r, $g, $b);
        imageline($im, 0, $y, $w - 1, $y, $col);
    }

    // Top accent border (orange/gold accent line)
    $accentGold = imagecolorallocate($im, 255, 207, 125);
    $accentOrange = imagecolorallocate($im, 231, 111, 44);
    $white = imagecolorallocate($im, 243, 240, 255);
    $muted = imagecolorallocate($im, 170, 163, 210);
    $badgeBg = imagecolorallocate($im, 35, 20, 65);

    imagefilledrectangle($im, 0, 0, $w - 1, 6, $accentOrange);

    // Subtle decorative inner frame
    $frameCol = imagecolorallocatealpha($im, 255, 255, 255, 118);
    imagerectangle($im, 40, 40, $w - 41, $h - 41, $frameCol);

    // Overlay club logo
    $logoFile = __DIR__ . '/../../frontend/images/logopng.png';
    if (file_exists($logoFile)) {
        $logo = @imagecreatefrompng($logoFile);
        if ($logo) {
            imagealphablending($im, true);
            imagesavealpha($im, true);
            $logoW = imagesx($logo);
            $logoH = imagesy($logo);
            $destSize = 280;
            $destX = 90;
            $destY = (int) (($h - $destSize) / 2);
            imagecopyresampled($im, $logo, $destX, $destY, 0, 0, $destSize, $destSize, $logoW, $logoH);
            imagedestroy($logo);
        }
    }

    $boldFont = find_ttf_font(true);
    $regFont = find_ttf_font(false);

    $textX = 420;

    if ($boldFont) {
        // Badge
        imagefilledrectangle($im, $textX, 155, $textX + 220, 190, $badgeBg);
        imagerectangle($im, $textX, 155, $textX + 220, 190, $accentGold);
        imagettftext($im, 12, 0, $textX + 16, 179, $accentGold, $boldFont, strtoupper($badge));

        // Main titles
        imagettftext($im, 40, 0, $textX, 260, $white, $boldFont, $title1);
        imagettftext($im, 40, 0, $textX, 325, $accentGold, $boldFont, $title2);

        // Subtitle
        if ($regFont) {
            imagettftext($im, 20, 0, $textX, 395, $muted, $regFont, $subtitle);
            imagettftext($im, 15, 0, $textX, 445, $accentOrange, $boldFont, "bracu.ac.bd · Celebrate Culture · Since 2001");
        }
    } else {
        // Fallback using built-in bitmap fonts
        imagestring($im, 5, $textX, 170, $badge, $accentGold);
        imagestring($im, 5, $textX, 240, $title1 . ' ' . $title2, $white);
        imagestring($im, 4, $textX, 320, $subtitle, $muted);
    }

    // Save as JPEG (quality 92) and PNG (for Open Graph and Twitter sharing)
    // plus a companion WebP on disk to satisfy static file audit
    $jpgPath = $outDir . '/' . $filenameBase . '.jpg';
    $pngPath = $outDir . '/' . $filenameBase . '.png';
    $webpPath = $outDir . '/' . $filenameBase . '.webp';

    imagejpeg($im, $jpgPath, 92);
    imagepng($im, $pngPath, 8);
    if (function_exists('imagewebp')) {
        imagewebp($im, $webpPath, 85);
        echo "Generated $webpPath (1200x630 companion)\n";
    }
    imagedestroy($im);

    echo "Generated $jpgPath (1200x630)\n";
    echo "Generated $pngPath (1200x630)\n";
}

make_og_image(
    'BRAC University',
    'Cultural Club',
    'Music · Dance · Drama · Creative Arts',
    'Official Website',
    'og-share',
    $outDir
);

make_og_image(
    'Past Events & Stories',
    'BUCuC Archives',
    'Highlights of Festivals, Concerts & Showcases',
    'Event Highlights',
    'og-events',
    $outDir
);

make_og_image(
    'Performance Gallery',
    'Video Showcases',
    'Live Musicales, Stage Acts & Productions',
    'Video Gallery',
    'og-performances',
    $outDir
);
