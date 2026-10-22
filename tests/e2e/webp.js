// Phase 3: WebP generation, fallback serving, transparency, orientation, cleanup, <1MB limit
const T = require('../config');
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const PHP = T.PHP;
const php = code => execFileSync(PHP, ['-d', 'extension=gd', '-d', 'extension=zip', '-r', code]).toString();

const BASE = 'http://127.0.0.1:8099/frontend/';
const FRONTEND = path.join(T.ROOT, 'frontend');
const IMAGES_DIR = path.join(FRONTEND, 'images');
const TEST_DIR = path.join(T.RUNTIME, 'webp_test');

const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 230) : ''}`);
const wait = ms => new Promise(r => setTimeout(r, ms));

function dims(absPath) {
    if (!fs.existsSync(absPath)) return null;
    const out = php(`$i = @getimagesize('${absPath.replace(/\\/g, '/')}'); echo $i ? ($i[0] . 'x' . $i[1] . ' ' . $i['mime']) : 'none';`);
    return out.trim();
}

function getAlphaAt(absPath, x, y) {
    if (!fs.existsSync(absPath)) return -1;
    const ext = path.extname(absPath).toLowerCase();
    const fn = (ext === '.webp') ? 'imagecreatefromwebp' : ((ext === '.png') ? 'imagecreatefrompng' : 'imagecreatefromjpeg');
    const out = php(`
        $im = @${fn}('${absPath.replace(/\\/g, '/')}');
        if (!$im) { echo -1; exit; }
        $rgba = imagecolorat($im, ${x}, ${y});
        $a = ($rgba >> 24) & 0x7F;
        imagedestroy($im);
        echo $a;
    `);
    return parseInt(out.trim(), 10);
}

function walkFiles(dir) {
    const list = [];
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, e.name);
        if (e.isDirectory()) list.push(...walkFiles(full));
        else list.push(full);
    }
    return list;
}

(async () => {
    fs.mkdirSync(TEST_DIR, { recursive: true });

    // -------------------------------------------------------------
    // Helper images setup
    // -------------------------------------------------------------
    const transPng = path.join(TEST_DIR, 'transparent_logo.png');
    php(`
        $im = imagecreatetruecolor(400, 500);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 399, 499, $trans);
        $solid = imagecolorallocatealpha($im, 20, 120, 220, 0);
        imagefilledellipse($im, 200, 250, 250, 300, $solid);
        imagepng($im, '${transPng.replace(/\\/g, '/')}');
        imagedestroy($im);
    `);

    const panelJpg = path.join(TEST_DIR, 'panel_sample.jpg');
    php(`
        $im = imagecreatetruecolor(400, 500);
        for ($y = 0; $y < 500; $y += 25) {
            imagefilledrectangle($im, 0, $y, 399, $y + 24, imagecolorallocate($im, 50, ($y % 200) + 30, 150));
        }
        imagejpeg($im, '${panelJpg.replace(/\\/g, '/')}', 90);
        imagedestroy($im);
    `);

    const eventJpg = path.join(TEST_DIR, 'event_sample.jpg');
    php(`
        $im = imagecreatetruecolor(900, 600);
        for ($y = 0; $y < 600; $y += 30) {
            imagefilledrectangle($im, 0, $y, 899, $y + 29, imagecolorallocate($im, ($y % 180) + 50, 80, 120));
        }
        imagejpeg($im, '${eventJpg.replace(/\\/g, '/')}', 90);
        imagedestroy($im);
    `);

    const exifJpg = path.join(TEST_DIR, 'exif_rotated.jpg');
    php(`
        $w = 400; $h = 200;
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, intdiv($h, 2), imagecolorallocate($im, 255, 50, 50));
        imagefilledrectangle($im, 0, intdiv($h, 2), $w, $h, imagecolorallocate($im, 50, 50, 255));
        ob_start();
        imagejpeg($im, null, 90);
        $raw = ob_get_clean();
        imagedestroy($im);
        $orientation = 6;
        $exifData = "Exif\\x00\\x00" .
                    "II\\x2a\\x00\\x08\\x00\\x00\\x00" .
                    "\\x01\\x00" .
                    "\\x12\\x01\\x03\\x00\\x01\\x00\\x00\\x00" . pack('v', $orientation) . "\\x00\\x00" .
                    "\\x00\\x00\\x00\\x00";
        $app1 = "\\xFF\\xE1" . pack('n', strlen($exifData) + 2) . $exifData;
        $output = substr($raw, 0, 2) . $app1 . substr($raw, 2);
        file_put_contents('${exifJpg.replace(/\\/g, '/')}', $output);
    `);

    const sponsorPng = path.join(TEST_DIR, 'sponsor_logo.png');
    php(`
        $im = imagecreatetruecolor(1200, 200);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 1199, 199, $trans);
        $solid = imagecolorallocatealpha($im, 240, 180, 0, 0);
        imagefilledellipse($im, 600, 100, 400, 150, $solid);
        imagepng($im, '${sponsorPng.replace(/\\/g, '/')}');
        imagedestroy($im);
    `);

    // -------------------------------------------------------------
    // Check 1: Image size audit across entire frontend/images/
    // -------------------------------------------------------------
    const allImages = walkFiles(IMAGES_DIR);
    const over1Mb = allImages.filter(f => fs.statSync(f).size > 1048576);
    ok('no image in frontend/images/ exceeds 1 MB', over1Mb.length === 0, over1Mb.map(f => path.basename(f) + ' (' + fs.statSync(f).size + ' B)').join(', '));

    const secondWebp = path.join(IMAGES_DIR, '2nd.webp');
    const secondJpg = path.join(IMAGES_DIR, '2nd.JPG');
    ok('2nd.JPG has companion 2nd.webp', fs.existsSync(secondWebp));
    if (fs.existsSync(secondWebp)) {
        const sz = fs.statSync(secondWebp).size;
        ok('2nd.webp is strictly under 1 MB', sz > 0 && sz <= 1048576, `size: ${Math.round(sz / 1024)} KB`);
    }

    const fallbacks = allImages.filter(f => /\.(jpe?g|png)$/i.test(f));
    const missingWebp = fallbacks.filter(f => !fs.existsSync(f.replace(/\.(jpe?g|png)$/i, '.webp')));
    ok('every JPEG and PNG has a companion WebP', missingWebp.length === 0, missingWebp.map(f => path.basename(f)).join(', '));

    // -------------------------------------------------------------
    // Check 2: Explicit URLs & Content-Type (No Accept rewrite, No Vary: Accept)
    // -------------------------------------------------------------
    const url2ndJpg = BASE + 'images/2nd.JPG';
    const url2ndWebp = BASE + 'images/2nd.webp';
    try {
        const resFallback = await fetch(url2ndJpg, {
            headers: { 'Accept': 'image/webp,image/jpeg,*/*' }
        });
        ok('request to .JPG returns status 200', resFallback.status === 200, `status: ${resFallback.status}`);
        const ctFallback = resFallback.headers.get('content-type') || '';
        ok('.JPG always returns image/jpeg Content-Type regardless of Accept header', ctFallback.includes('image/jpeg'), `got: ${ctFallback}`);
        const varyFallback = resFallback.headers.get('vary') || '';
        ok('.JPG response does NOT include Vary: Accept header', !varyFallback.toLowerCase().includes('accept'), `vary: ${varyFallback}`);

        const resWebp = await fetch(url2ndWebp, {
            headers: { 'Accept': '*/*' }
        });
        ok('request to .webp returns status 200', resWebp.status === 200, `status: ${resWebp.status}`);
        const ctWebp = resWebp.headers.get('content-type') || '';
        ok('.webp always returns image/webp Content-Type', ctWebp.includes('image/webp'), `got: ${ctWebp}`);
        const varyWebp = resWebp.headers.get('vary') || '';
        ok('.webp response does NOT include Vary: Accept header', !varyWebp.toLowerCase().includes('accept'), `vary: ${varyWebp}`);
        const webpBuf = await resWebp.arrayBuffer();
        ok('.webp payload matches 2nd.webp filesize on disk', webpBuf.byteLength === fs.statSync(secondWebp).size, `${webpBuf.byteLength} vs ${fs.statSync(secondWebp).size}`);
    } catch (e) {
        ok('HTTP image requests succeed', false, e.message);
    }

    // -------------------------------------------------------------
    // Check 2b: Repository-wide EXIF GPS audit (Condition 6)
    // -------------------------------------------------------------
    const jpegsInRepo = allImages.filter(f => /\.(jpe?g)$/i.test(f));
    const repoGpsResult = php(`
        $dir = '${IMAGES_DIR.replace(/\\/g, '/')}';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $bad = [];
        foreach ($it as $file) {
            if ($file->isFile() && preg_match('/\\.(jpe?g)$/i', $file->getFilename())) {
                $e = @exif_read_data($file->getPathname(), 'GPS');
                if ($e && (!empty($e['GPSLatitude']) || !empty($e['GPSLongitude']) || !empty($e['GPS']))) {
                    $bad[] = $file->getFilename();
                }
            }
        }
        echo json_encode($bad);
    `).trim();
    const badGps = JSON.parse(repoGpsResult || '[]');
    ok(`repository EXIF audit: 0 images have GPS location tags (${jpegsInRepo.length} JPEGs scanned)`, badGps.length === 0, badGps.join(', '));

    // -------------------------------------------------------------
    // Check 3: Transparency preservation
    // -------------------------------------------------------------
    const testTransCopy = path.join(TEST_DIR, 'test_trans_optimize.png');
    fs.copyFileSync(transPng, testTransCopy);
    php(`
        require_once '${T.ROOT_FWD}/backend/includes/site.php';
        media_optimize_and_webp('${testTransCopy.replace(/\\/g, '/')}');
    `);
    const testTransWebp = testTransCopy.replace(/\.png$/, '.webp');
    ok('media_optimize_and_webp generated .webp for transparent PNG', fs.existsSync(testTransWebp));
    const alphaPngCorner = getAlphaAt(testTransCopy, 10, 10);
    const alphaPngCenter = getAlphaAt(testTransCopy, 200, 250);
    ok('transparency preserved in optimized PNG fallback', alphaPngCorner === 127 && alphaPngCenter === 0, `corner: ${alphaPngCorner}, center: ${alphaPngCenter}`);
    const alphaWebpCorner = getAlphaAt(testTransWebp, 10, 10);
    const alphaWebpCenter = getAlphaAt(testTransWebp, 200, 250);
    ok('transparency preserved in companion WebP', alphaWebpCorner === 127 && alphaWebpCenter === 0, `corner: ${alphaWebpCorner}, center: ${alphaWebpCenter}`);

    // -------------------------------------------------------------
    // Check 4: EXIF Orientation correction
    // -------------------------------------------------------------
    const exifCheck = php(`$e = @exif_read_data('${exifJpg.replace(/\\/g, '/')}'); echo $e['Orientation'] ?? 'none';`);
    ok('test image has EXIF Orientation 6 (90° CW)', exifCheck.trim() === '6', `got: ${exifCheck.trim()}`);
    const testExifCopy = path.join(TEST_DIR, 'test_exif_optimize.jpg');
    fs.copyFileSync(exifJpg, testExifCopy);
    php(`
        require_once '${T.ROOT_FWD}/backend/includes/site.php';
        media_optimize_and_webp('${testExifCopy.replace(/\\/g, '/')}');
    `);
    const testExifWebp = testExifCopy.replace(/\.jpg$/, '.webp');
    ok('media_optimize_and_webp created WebP for EXIF test image', fs.existsSync(testExifWebp));
    const exifDimsJpg = dims(testExifCopy);
    const exifDimsWebp = dims(testExifWebp);
    ok('EXIF orientation corrected on JPEG fallback (dimensions swapped to 200x400)', exifDimsJpg && exifDimsJpg.startsWith('200x400'), `got: ${exifDimsJpg}`);
    ok('EXIF orientation corrected on companion WebP (dimensions 200x400)', exifDimsWebp && exifDimsWebp.startsWith('200x400'), `got: ${exifDimsWebp}`);

    // -------------------------------------------------------------
    // Check 4b: EXIF GPS location data stripped on upload/re-encode
    // -------------------------------------------------------------
    const gpsJpg = path.join(TEST_DIR, 'gps_location_sample.jpg');
    php(`
        $w = 100; $h = 100;
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 80, 140, 200));
        ob_start();
        imagejpeg($im, null, 90);
        $raw = ob_get_clean();
        imagedestroy($im);
        $exifData = "Exif\\x00\\x00" .
                    "II\\x2a\\x00\\x08\\x00\\x00\\x00" .
                    "\\x02\\x00" .
                    "\\x12\\x01\\x03\\x00\\x01\\x00\\x00\\x00\\x01\\x00\\x00\\x00" .
                    "\\x25\\x88\\x04\\x00\\x01\\x00\\x00\\x00\\x26\\x00\\x00\\x00" .
                    "\\x00\\x00\\x00\\x00" .
                    "\\x01\\x00" .
                    "\\x02\\x00\\x05\\x00\\x03\\x00\\x00\\x00\\x38\\x00\\x00\\x00" .
                    "\\x00\\x00\\x00\\x00" .
                    pack('VVVVVV', 23, 1, 46, 1, 37, 1);
        $app1 = "\\xFF\\xE1" . pack('n', strlen($exifData) + 2) . $exifData;
        file_put_contents('${gpsJpg.replace(/\\/g, '/')}', substr($raw, 0, 2) . $app1 . substr($raw, 2));
    `);
    const hasGpsBefore = php(`$e = @exif_read_data('${gpsJpg.replace(/\\/g, '/')}', 'GPS'); echo !empty($e['GPSLatitude']) ? '1' : '0';`).trim();
    ok('test image has EXIF GPS location metadata before optimization', hasGpsBefore === '1');

    php(`
        require_once '${T.ROOT_FWD}/backend/includes/site.php';
        media_optimize_and_webp('${gpsJpg.replace(/\\/g, '/')}');
    `);
    const gpsWebp = gpsJpg.replace(/\.jpg$/, '.webp');
    const hasGpsAfterJpg = php(`$e = @exif_read_data('${gpsJpg.replace(/\\/g, '/')}', 'GPS'); echo (!empty($e['GPSLatitude']) || !empty($e['GPS']) || (isset($e['SectionsFound']) && strpos($e['SectionsFound'], 'GPS') !== false)) ? '1' : '0';`).trim();
    const hasAnyExifWebp = php(`$e = @exif_read_data('${gpsWebp.replace(/\\/g, '/')}'); echo $e !== false ? '1' : '0';`).trim();

    ok('uploaded photo strips EXIF GPS location data on fallback JPEG', hasGpsAfterJpg === '0', `hasGps: ${hasGpsAfterJpg}`);
    ok('uploaded photo strips EXIF metadata on companion WebP', hasAnyExifWebp === '0');

    // -------------------------------------------------------------
    // Check 5: <picture> tags rollout & layout stability (Condition 7)
    // -------------------------------------------------------------
    const browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 60000 });
    try {
        const page = await browser.newPage();
        page.on('dialog', d => d.accept());
        await page.setViewport({ width: 1280, height: 900 });

        // Public index.php checks
        await page.goto(BASE + 'index.php', { waitUntil: 'load' });
        const pictureTypes = await page.evaluate(() => {
            const types = {
                brand: document.querySelector('.brand picture'),
                heroDisc: document.querySelector('.disc picture, .vlabel picture'),
                paperLogo: document.querySelector('.paper-brand picture, picture:has(.paper-logo)'),
                carouselMoments: document.querySelector('.moments .carousel-item picture'),
                advisors: document.querySelector('.person-img picture'),
                presidentialPanel: document.querySelector('.pmc-image-wrap picture'),
                secretariesSwiper: document.querySelector('.sb-swiper picture'),
                footerBrand: document.querySelector('.foot-brand picture')
            };
            const res = {};
            for (const [k, el] of Object.entries(types)) {
                if (!el) {
                    res[k] = { found: false };
                    continue;
                }
                const source = el.querySelector('source[type="image/webp"]');
                const img = el.querySelector('img');
                const src = img ? (img.getAttribute('src') || '') : '';
                const srcset = source ? (source.getAttribute('srcset') || '') : '';
                res[k] = {
                    found: true,
                    hasWebpSource: !!source && /\.webp(\?.*)?$/i.test(srcset),
                    hasFallbackImg: !!img && /\.(jpe?g|png)(\?.*)?$/i.test(src),
                    differentUrls: src !== '' && srcset !== '' && src !== srcset,
                    hasAlt: img ? img.hasAttribute('alt') : false,
                    src,
                    srcset
                };
            }
            const allPics = Array.from(document.querySelectorAll('picture'));
            const allPicsValid = allPics.length > 0 && allPics.every(p => {
                const s = p.querySelector('source[type="image/webp"]');
                const i = p.querySelector('img');
                const src = i ? (i.getAttribute('src') || '') : '';
                const srcset = s ? (s.getAttribute('srcset') || '') : '';
                return s && i && /\.webp(\?.*)?$/i.test(srcset) && /\.(jpe?g|png)(\?.*)?$/i.test(src) && src !== srcset;
            });
            return { res, allPicsCount: allPics.length, allPicsValid };
        });

        ok('homepage has <picture> tags for all major sections', pictureTypes.allPicsCount >= 8, `found ${pictureTypes.allPicsCount} picture tags`);
        ok('all homepage <picture> elements have valid WebP source and fallback img with distinct URLs', pictureTypes.allPicsValid);
        for (const [k, info] of Object.entries(pictureTypes.res)) {
            ok(`image type "${k}" renders valid <picture> with WebP source and fallback img`, info.found && info.hasWebpSource && info.hasFallbackImg && info.differentUrls, JSON.stringify(info));
        }

        // Public past_events.php checks
        await page.goto(BASE + 'past_events.php', { waitUntil: 'load' });
        const pastPicStats = await page.evaluate(() => {
            const pics = Array.from(document.querySelectorAll('.event-detail-card picture, .event-image picture'));
            return {
                count: pics.length,
                allValid: pics.length > 0 && pics.every(p => {
                    const s = p.querySelector('source[type="image/webp"]');
                    const i = p.querySelector('img');
                    const src = i ? (i.getAttribute('src') || '') : '';
                    const srcset = s ? (s.getAttribute('srcset') || '') : '';
                    return s && i && /\.webp(\?.*)?$/i.test(srcset) && /\.(jpe?g|png)(\?.*)?$/i.test(src) && src !== srcset;
                })
            };
        });
        ok('past_events.php renders <picture> elements with WebP sources and fallback imgs', pastPicStats.count > 0 && pastPicStats.allValid, `${pastPicStats.count} event pictures found`);

        // Layout unchanged at 390, 768, and 1366 px
        for (const w of [390, 768, 1366]) {
            await page.setViewport({ width: w, height: w < 800 ? 844 : 900, isMobile: w < 800, hasTouch: w < 800 });
            await page.goto(BASE + 'index.php', { waitUntil: 'load' });
            const layoutIndex = await page.evaluate(() => {
                const broken = [...document.images].filter(i => i.complete && i.naturalWidth === 0 && i.getAttribute('src') && !i.closest('[hidden]'));
                const pics = [...document.querySelectorAll('picture')];
                const picVisible = pics.filter(p => {
                    const r = p.getBoundingClientRect();
                    return r.width > 0 && r.height > 0;
                });
                return {
                    scrollWidth: document.documentElement.scrollWidth,
                    brokenCount: broken.length,
                    picsVisibleCount: picVisible.length
                };
            });
            ok(`index.php @${w}px: layout intact (no horizontal overflow)`, layoutIndex.scrollWidth <= w + 1, `scrollWidth: ${layoutIndex.scrollWidth}`);
            ok(`index.php @${w}px: no broken images`, layoutIndex.brokenCount === 0);
            ok(`index.php @${w}px: picture elements have positive dimensions`, layoutIndex.picsVisibleCount > 0, `${layoutIndex.picsVisibleCount} visible pictures`);

            await page.goto(BASE + 'past_events.php', { waitUntil: 'load' });
            const layoutPast = await page.evaluate(() => {
                const broken = [...document.images].filter(i => i.complete && i.naturalWidth === 0 && i.getAttribute('src') && !i.closest('[hidden]'));
                const pics = [...document.querySelectorAll('picture')];
                const picVisible = pics.filter(p => {
                    const r = p.getBoundingClientRect();
                    return r.width > 0 && r.height > 0;
                });
                return {
                    scrollWidth: document.documentElement.scrollWidth,
                    brokenCount: broken.length,
                    picsVisibleCount: picVisible.length
                };
            });
            ok(`past_events.php @${w}px: layout intact (no horizontal overflow)`, layoutPast.scrollWidth <= w + 1, `scrollWidth: ${layoutPast.scrollWidth}`);
            ok(`past_events.php @${w}px: no broken images`, layoutPast.brokenCount === 0);
            ok(`past_events.php @${w}px: event picture elements have positive dimensions`, layoutPast.picsVisibleCount > 0, `${layoutPast.picsVisibleCount} visible pictures`);
        }

        // -------------------------------------------------------------
        // Check 6: Admin Panel Uploads & Deletions (Puppeteer)
        // -------------------------------------------------------------
        await page.setViewport({ width: 1280, height: 900 });

        // Login as Super Admin
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', 'super@test.local');
        await page.type('#adminPassword', 'superpass123');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

        // Upload 1: Panel Person in media.php
        const tid = sql('SELECT id FROM tenures WHERE is_current=1');
        await page.goto(BASE + `media.php?tab=tenures&t=${tid}`);
        await page.type('form:has(input[name=grp][value=panel]) input[name=name]', 'WebP Specialist');
        await page.type('form:has(input[name=grp][value=panel]) input[name=position]', 'Image Engineer');
        const panelInput = await page.$('form:has(input[name=grp][value=panel]) input[name=photo]');
        await panelInput.uploadFile(panelJpg);
        await page.waitForFunction(() => {
            const el = document.querySelector('form:has(input[name=grp][value=panel]) .check-msg');
            return el && /^(Ready|Not|This)/.test(el.textContent);
        }, { timeout: 10000 });
        await Promise.all([
            page.waitForNavigation(),
            page.$eval('form:has(input[name=grp][value=panel]) button[type=submit]', b => b.click())
        ]);
        const personImgRel = sql("SELECT image FROM tenure_people WHERE name='WebP Specialist' LIMIT 1");
        ok('panel person created in database', !!personImgRel, personImgRel);
        const personImgAbs = path.join(FRONTEND, personImgRel);
        const personWebpAbs = personImgAbs.replace(/\.(jpe?g|png)$/i, '.webp');
        ok('uploaded panel person saved fallback image on disk', fs.existsSync(personImgAbs));
        ok('uploaded panel person automatically generated companion WebP on disk', fs.existsSync(personWebpAbs));
        ok('both uploaded panel files are <= 1 MB', fs.statSync(personImgAbs).size <= 1048576 && fs.statSync(personWebpAbs).size <= 1048576);

        // Upload 2: Event in media.php
        await page.goto(BASE + 'media.php?tab=events');
        await page.type('form[action*="tab=events"] input[name=title]', 'WebP Grand Launch');
        await page.type('form[action*="tab=events"] input[name=event_date]', '2026-06-15');
        await page.type('form[action*="tab=events"] input[name=place]', 'BU Auditorium');
        const eventInput = await page.$('form[action*="tab=events"] input[name=photo]');
        await eventInput.uploadFile(eventJpg);
        await page.waitForFunction(() => {
            const el = document.querySelector('form[action*="tab=events"] .check-msg');
            return el && /^(Ready|Not|This)/.test(el.textContent);
        }, { timeout: 10000 });
        await Promise.all([
            page.waitForNavigation(),
            page.$eval('form[action*="tab=events"] button[type=submit]', b => b.click())
        ]);
        const eventImgRel = sql("SELECT image FROM site_events WHERE title='WebP Grand Launch' LIMIT 1");
        ok('past event created in database', !!eventImgRel, eventImgRel);
        const eventImgAbs = path.join(FRONTEND, eventImgRel);
        const eventWebpAbs = eventImgAbs.replace(/\.(jpe?g|png)$/i, '.webp');
        ok('uploaded event saved fallback image on disk', fs.existsSync(eventImgAbs));
        ok('uploaded event automatically generated companion WebP on disk', fs.existsSync(eventWebpAbs));
        ok('both uploaded event files are <= 1 MB', fs.statSync(eventImgAbs).size <= 1048576 && fs.statSync(eventWebpAbs).size <= 1048576);

        // Upload 3: Sponsor Banner in sponsors.php
        await page.goto(BASE + 'sponsors.php?new=1&spot=home_hero');
        await page.type('#advertiser', 'WebP Sponsor Inc');
        await page.type('#link', 'https://webp.example.com');
        await page.$eval('#starts_on', e => e.value = '2026-01-01');
        await page.$eval('#ends_on', e => e.value = '2026-12-31');
        const sponsorInput = await page.$('input[name=image]');
        await sponsorInput.uploadFile(sponsorPng);
        const opened = await page.waitForSelector('.crop-modal:not([hidden]) .cropper-container', { timeout: 3000 }).catch(() => null);
        if (opened) { await wait(400); await page.click('[data-act="use"]'); }
        await page.waitForFunction(() => {
            const el = document.querySelector('input[name=image]').parentNode.querySelector('.check-msg');
            return el && /^(Ready|Not|This)/.test(el.textContent);
        }, { timeout: 10000 });
        await Promise.all([
            page.waitForNavigation(),
            page.$eval('#adForm button[type=submit]', b => b.click())
        ]);
        const adImgRel = sql("SELECT image FROM ads WHERE advertiser='WebP Sponsor Inc' LIMIT 1");
        ok('sponsor ad created in database', !!adImgRel, adImgRel);
        const adImgAbs = path.join(FRONTEND, adImgRel);
        const adWebpAbs = adImgAbs.replace(/\.(jpe?g|png)$/i, '.webp');
        ok('uploaded sponsor ad saved fallback PNG on disk', fs.existsSync(adImgAbs));
        ok('uploaded sponsor ad automatically generated companion WebP on disk', fs.existsSync(adWebpAbs));
        const adWebpAlphaCorner = getAlphaAt(adWebpAbs, 10, 10);
        const adWebpAlphaCenter = getAlphaAt(adWebpAbs, 600, 100);
        ok('transparency preserved in sponsor ad WebP upload', adWebpAlphaCorner === 127 && adWebpAlphaCenter === 0, `corner: ${adWebpAlphaCorner}, center: ${adWebpAlphaCenter}`);

        // -------------------------------------------------------------
        // Check 7: WebP deleted with original image
        // -------------------------------------------------------------
        // Delete event
        const evId = sql("SELECT id FROM site_events WHERE title='WebP Grand Launch' LIMIT 1");
        await page.goto(BASE + 'media.php?tab=events');
        await Promise.all([
            page.waitForNavigation(),
            page.$eval(`form:has(input[name=action][value=event_remove]):has(input[name=id][value="${evId}"]) button[type=submit]`, b => b.click())
        ]);
        ok('event deleted: fallback image unlinked from disk', !fs.existsSync(eventImgAbs));
        ok('event deleted: companion WebP unlinked from disk', !fs.existsSync(eventWebpAbs));

        // Delete panel person
        const personId = sql("SELECT id FROM tenure_people WHERE name='WebP Specialist' LIMIT 1");
        await page.goto(BASE + `media.php?tab=tenures&t=${tid}`);
        await Promise.all([
            page.waitForNavigation(),
            page.$eval(`form:has(input[name=action][value=person_remove]):has(input[name=person_id][value="${personId}"]) button[type=submit]`, b => b.click())
        ]);
        ok('panel person deleted: fallback image unlinked from disk', !fs.existsSync(personImgAbs));
        ok('panel person deleted: companion WebP unlinked from disk', !fs.existsSync(personWebpAbs));

        // Delete sponsor ad
        const adId = sql("SELECT id FROM ads WHERE advertiser='WebP Sponsor Inc' LIMIT 1");
        await page.goto(BASE + 'sponsors.php');
        await Promise.all([
            page.waitForNavigation(),
            page.$eval(`form:has(input[name=action][value=delete_ad]):has(input[name=id][value="${adId}"]) button[type=submit]`, b => b.click())
        ]);
        ok('sponsor ad deleted: fallback image unlinked from disk', !fs.existsSync(adImgAbs));
        ok('sponsor ad deleted: companion WebP unlinked from disk', !fs.existsSync(adWebpAbs));

        // Tenure deletion with photos
        await page.goto(BASE + 'media.php?tab=tenures');
        await page.type('#newTenureForm input[name=label]', '2099-00');
        await Promise.all([
            page.waitForNavigation(),
            page.$eval('#newTenureForm button[type=submit]', b => b.click())
        ]);
        const newTid = sql("SELECT id FROM tenures WHERE label='2099-00' LIMIT 1");
        await page.goto(BASE + `media.php?tab=tenures&t=${newTid}`);
        await page.type('form:has(input[name=grp][value=panel]) input[name=name]', 'Temporary Member');
        await page.type('form:has(input[name=grp][value=panel]) input[name=position]', 'Tester');
        const tInput = await page.$('form:has(input[name=grp][value=panel]) input[name=photo]');
        await tInput.uploadFile(panelJpg);
        await page.waitForFunction(() => {
            const el = document.querySelector('form:has(input[name=grp][value=panel]) .check-msg');
            return el && /^(Ready|Not|This)/.test(el.textContent);
        }, { timeout: 10000 });
        await Promise.all([
            page.waitForNavigation(),
            page.$eval('form:has(input[name=grp][value=panel]) button[type=submit]', b => b.click())
        ]);
        const tImgRel = sql("SELECT image FROM tenure_people WHERE name='Temporary Member' LIMIT 1");
        const tImgAbs = path.join(FRONTEND, tImgRel);
        const tWebpAbs = tImgAbs.replace(/\.(jpe?g|png)$/i, '.webp');
        ok('tenure person uploaded image and webp exist', fs.existsSync(tImgAbs) && fs.existsSync(tWebpAbs));

        // Now delete the tenure
        await page.goto(BASE + `media.php?tab=tenures&t=${newTid}`);
        await Promise.all([
            page.waitForNavigation(),
            page.$eval(`form:has(input[name=action][value=tenure_delete]):has(input[name=tenure_id][value="${newTid}"]) button[type=submit]`, b => b.click())
        ]);
        ok('tenure deleted: fallback image unlinked from disk', !fs.existsSync(tImgAbs));
        ok('tenure deleted: companion WebP unlinked from disk', !fs.existsSync(tWebpAbs));

    } finally {
        await browser.close();
    }

    // Cleanup test fixtures
    try {
        for (const f of fs.readdirSync(TEST_DIR)) fs.unlinkSync(path.join(TEST_DIR, f));
        fs.rmdirSync(TEST_DIR);
    } catch (e) { }

    // Print summary
    for (const r of results) console.log(r);
    const passed = results.filter(r => r.startsWith('PASS')).length;
    console.log(`${passed}/${results.length} passed`);
    process.exit(passed === results.length ? 0 : 1);
})().catch(e => {
    console.error(e);
    for (const r of results) console.log(r);
    const passed = results.filter(r => r.startsWith('PASS')).length;
    console.log(`${passed}/${results.length + 1} passed`);
    process.exit(1);
});
