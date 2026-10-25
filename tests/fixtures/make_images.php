<?php
/**
 * Test images for the crop tool and event checks (tests/run.js runs this once into tests/runtime/cropimg).
 * Usage: php -d extension=gd make_images.php <folder>
 */
$dir = rtrim($argv[1] ?? __DIR__ . '/../runtime/cropimg', '/\\');
if (!is_dir($dir)) mkdir($dir, 0777, true);
function make($w, $h, $file, $type)
{
    $i = imagecreatetruecolor($w, $h);
    $step = max(1, intdiv($h, 20));
    for ($y = 0; $y < $h; $y += $step) {
        imagefilledrectangle($i, 0, $y, $w, $y + $step, imagecolorallocate($i, ($y * 7) % 255, 120, 200 - ($y % 200)));
    }
    imagefilledellipse($i, intdiv($w, 2), intdiv($h, 2), intdiv(min($w, $h), 2), intdiv(min($w, $h), 2), imagecolorallocate($i, 255, 220, 0));
    $type === 'png' ? imagepng($i, $file) : imagejpeg($i, $file, 92);
}
make(3000, 2000, "$dir/wide.jpg", 'jpg');       // 3:2 photo for a 4:5 slot: the crop window must open
make(1600, 2000, "$dir/bigpanel.jpg", 'jpg');   // right shape, too big: scaled down
make(400, 500, "$dir/okpanel.jpg", 'jpg');      // already right
make(200, 250, "$dir/tiny.jpg", 'jpg');         // "may look blurry"
make(4000, 3000, "$dir/event.png", 'png');      // PNG event photo: cropped to 3:2, saved as WebP
echo "test images in $dir\n";
