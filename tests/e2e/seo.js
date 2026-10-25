// Phase 7: SEO and Sharing Verification
// Sitemap, robots.txt, X-Robots-Tag & meta robots on admin/hidden pages,
// Open Graph & Twitter cards, 1200x630 JPEG/PNG share images, Organization JSON-LD,
// unique meta descriptions, canonical links, 404 page status and noindex,
// and zero hidden paths in any public file.
const T = require('../config');
const puppeteer = require('puppeteer-core');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const BASE = 'http://127.0.0.1:8099/';
const FRONTEND = BASE + 'frontend/';
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 180) : ''}`);

function httpGet(url) {
    return new Promise((resolve, reject) => {
        http.get(url, (res) => {
            let body = '';
            res.on('data', chunk => { body += chunk; });
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
        }).on('error', reject);
    });
}

function getImageInfo(imgPath) {
    const raw = execFileSync(T.PHP, ['-r', `
        $info = @getimagesize('${imgPath.replace(/\\/g, '/')}');
        echo json_encode($info ?: ['error' => 'Not an image']);
    `]).toString().trim();
    return JSON.parse(raw);
}

(async () => {
    let browser;
    try {
        // =============================================================
        // 1. Sitemap Verification
        // =============================================================
        const sitemapRes = await httpGet(BASE + 'sitemap.xml');
        ok('sitemap.xml returns HTTP 200', sitemapRes.status === 200);
        ok('sitemap.xml Content-Type is XML',
            (sitemapRes.headers['content-type'] || '').includes('xml'));

        const sitemapBody = sitemapRes.body;
        const locMatches = [...sitemapBody.matchAll(/<loc>(.*?)<\/loc>/g)].map(m => m[1]);

        ok('sitemap lists index.php', locMatches.some(l => l.endsWith('/frontend/index.php')));
        ok('sitemap lists past_events.php', locMatches.some(l => l.endsWith('/frontend/past_events.php')));
        ok('sitemap lists performances.php', locMatches.some(l => l.endsWith('/frontend/performances.php')));
        ok('sitemap contains exactly the 3 public pages', locMatches.length === 3, `count=${locMatches.length}`);

        // Verify zero admin or hidden pages in sitemap
        const sitemapHasHidden = locMatches.some(l =>
            /admin|login|recover|sponsors|dashboard|checkin|attendance|handover|notices|theme|video_gallery|activity|member|apply/i.test(l) &&
            !l.endsWith('/frontend/index.php') && !l.endsWith('/frontend/past_events.php') && !l.endsWith('/frontend/performances.php')
        );
        ok('sitemap never includes admin, login, recovery, sponsor admin or any hidden page', !sitemapHasHidden);

        // =============================================================
        // 2. Robots.txt Verification
        // =============================================================
        const robotsRes = await httpGet(BASE + 'robots.txt');
        ok('robots.txt returns HTTP 200', robotsRes.status === 200);
        ok('robots.txt Content-Type is text/plain',
            (robotsRes.headers['content-type'] || '').includes('text/plain'));

        const robotsBody = robotsRes.body;
        ok('robots.txt specifies User-agent: *', robotsBody.includes('User-agent: *'));
        ok('robots.txt allows public pages',
            robotsBody.includes('Allow: /') &&
            robotsBody.includes('Allow: /frontend/index.php') &&
            robotsBody.includes('Allow: /frontend/past_events.php') &&
            robotsBody.includes('Allow: /frontend/performances.php'));
        ok('robots.txt points to the sitemap',
            robotsBody.includes('Sitemap:') && robotsBody.includes('sitemap.xml'));

        // Verify robots.txt does NOT list admin or hidden paths
        const forbiddenInRobots = ['admin_dashboard', 'super_admin', 'recover.php', 'sponsors.php', 'attendance_checkin', 'handover', 'video_gallery', 'secret'];
        const foundForbiddenRobots = forbiddenInRobots.filter(p => robotsBody.includes(p));
        ok('robots.txt does NOT list admin or hidden paths', foundForbiddenRobots.length === 0, `found: ${foundForbiddenRobots.join(', ')}`);

        // =============================================================
        // 3. 404 Page Status & Noindex Verification
        // =============================================================
        const res404 = await httpGet(FRONTEND + '404.php');
        ok('404.php returns a real HTTP 404 status', res404.status === 404);
        ok('404.php sends X-Robots-Tag: noindex, nofollow',
            (res404.headers['x-robots-tag'] || '').includes('noindex') && (res404.headers['x-robots-tag'] || '').includes('nofollow'));
        ok('404.php contains meta robots noindex tag in HTML',
            res404.body.includes('<meta name="robots" content="noindex, nofollow">'));

        const nonExistent = await httpGet(FRONTEND + 'non_existent_page_12345.php');
        ok('Non-existent URL triggers real 404 status', nonExistent.status === 404);
        ok('Non-existent URL response has X-Robots-Tag: noindex, nofollow',
            (nonExistent.headers['x-robots-tag'] || '').includes('noindex'));

        // =============================================================
        // 4. Noindex Headers on Admin, Login, Reset, Check-in & Hidden Pages
        // =============================================================
        const unauthPages = [
            'admin-login.php',
            'forgot-password.php',
            'reset-password.php',
            'recover.php',
            'checkin.php',
            'logout.php',
            'go.php',
            'ad_beacon.php',
        ];

        for (const p of unauthPages) {
            const res = await httpGet(FRONTEND + p);
            const xRobots = res.headers['x-robots-tag'] || '';
            const hasHeader = xRobots.includes('noindex') && xRobots.includes('nofollow');
            const hasMeta = res.body.includes('name="robots"') || res.body.includes('name=\'robots\'');
            ok(`X-Robots-Tag: noindex, nofollow header on ${p}`, hasHeader, xRobots);
            if (res.headers['content-type'] && res.headers['content-type'].includes('text/html') && res.status !== 302) {
                ok(`robots meta tag backup in HTML on ${p}`, hasMeta);
            }
        }

        // Test authenticated admin pages via Puppeteer
        browser = await puppeteer.launch({
            executablePath: T.CHROME,
            headless: 'new',
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });

        const page = await browser.newPage();
        await page.goto(FRONTEND + 'admin-login.php', { waitUntil: 'domcontentloaded' });
        await page.type('#adminEmail', 'super@test.local');
        await page.type('#adminPassword', 'superpass123');
        await Promise.all([
            page.click('button[type="submit"]'),
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        ]);

        const authPages = [
            'super_admin_dashboard.php',
            'admin_dashboard.php',
            'admin_management.php',
            'activity_log.php',
            'attendance.php',
            'handover.php',
            'media.php',
            'notices.php',
            'sponsors.php',
            'theme.php',
            'approved_members.php',
            'asb_panel.php',
            'pending_applications.php',
            'video_gallery.php',
        ];

        for (const p of authPages) {
            const resp = await page.goto(FRONTEND + p, { waitUntil: 'domcontentloaded' });
            const headers = resp.headers();
            const xRobots = headers['x-robots-tag'] || '';
            const content = await page.content();
            const hasHeader = xRobots.includes('noindex') && xRobots.includes('nofollow');
            const hasMeta = content.includes('name="robots"') && content.includes('noindex');

            ok(`Authenticated page ${p} has X-Robots-Tag: noindex, nofollow`, hasHeader, xRobots);
            ok(`Authenticated page ${p} has meta robots noindex tag in HTML`, hasMeta);
        }

        // =============================================================
        // 5. Open Graph, Twitter Tags, Canonical & Unique Meta Description
        // =============================================================
        const publicPages = [
            { file: 'index.php', name: 'Homepage', isHome: true },
            { file: 'past_events.php', name: 'Past Events', isHome: false },
            { file: 'performances.php', name: 'Performances', isHome: false },
        ];

        const descriptions = new Set();
        const canonicals = new Set();

        for (const p of publicPages) {
            const res = await httpGet(FRONTEND + p.file);
            ok(`${p.name} returns HTTP 200`, res.status === 200);

            const html = res.body;

            // Canonical link
            const canMatch = html.match(/<link\s+rel=["']canonical["']\s+href=["']([^"']+)["']/i);
            ok(`${p.name} has canonical link`, Boolean(canMatch && canMatch[1]));
            if (canMatch) {
                ok(`${p.name} canonical URL is absolute and matches page`,
                    canMatch[1].startsWith('http') && canMatch[1].endsWith('/frontend/' + p.file),
                    canMatch[1]);
                canonicals.add(canMatch[1]);
            }

            // Meta description
            const descMatch = html.match(/<meta\s+name=["']description["']\s+content=["']([^"']+)["']/i);
            ok(`${p.name} has non-empty meta description`, Boolean(descMatch && descMatch[1].length > 20));
            if (descMatch) {
                descriptions.add(descMatch[1]);
            }

            // Open Graph tags
            const ogType = html.match(/<meta\s+property=["']og:type["']\s+content=["']([^"']+)["']/i);
            const ogTitle = html.match(/<meta\s+property=["']og:title["']\s+content=["']([^"']+)["']/i);
            const ogDesc = html.match(/<meta\s+property=["']og:description["']\s+content=["']([^"']+)["']/i);
            const ogUrl = html.match(/<meta\s+property=["']og:url["']\s+content=["']([^"']+)["']/i);
            const ogImg = html.match(/<meta\s+property=["']og:image["']\s+content=["']([^"']+)["']/i);
            const ogImgWidth = html.match(/<meta\s+property=["']og:image:width["']\s+content=["']([^"']+)["']/i);
            const ogImgHeight = html.match(/<meta\s+property=["']og:image:height["']\s+content=["']([^"']+)["']/i);

            ok(`${p.name} has og:type website`, ogType && ogType[1] === 'website');
            ok(`${p.name} has og:title`, Boolean(ogTitle && ogTitle[1]));
            ok(`${p.name} has og:description`, Boolean(ogDesc && ogDesc[1]));
            ok(`${p.name} has og:url matching page`, Boolean(ogUrl && ogUrl[1].endsWith(p.file)));
            ok(`${p.name} has og:image with absolute URL`, Boolean(ogImg && ogImg[1].startsWith('http')));
            ok(`${p.name} og:image width is 1200`, ogImgWidth && ogImgWidth[1] === '1200');
            ok(`${p.name} og:image height is 630`, ogImgHeight && ogImgHeight[1] === '630');

            // Confirm share image is JPEG or PNG, NEVER WebP
            if (ogImg) {
                const imgUrl = ogImg[1];
                ok(`${p.name} og:image is never WebP`, !imgUrl.toLowerCase().endsWith('.webp'), imgUrl);
                ok(`${p.name} og:image is JPEG or PNG`,
                    imgUrl.toLowerCase().endsWith('.jpg') || imgUrl.toLowerCase().endsWith('.jpeg') || imgUrl.toLowerCase().endsWith('.png'),
                    imgUrl);

                // Fetch the actual image over HTTP and verify content-type and physical resolution
                const imgRes = await httpGet(imgUrl);
                ok(`${p.name} og:image URL resolves with HTTP 200`, imgRes.status === 200, `status=${imgRes.status}`);
                const cType = imgRes.headers['content-type'] || '';
                ok(`${p.name} og:image Content-Type is image/jpeg or image/png`,
                    cType === 'image/jpeg' || cType === 'image/png',
                    cType);

                // Local file check of image dimensions
                const localRel = imgUrl.replace(/^https?:\/\/[^\/]+/, '');
                const localAbs = path.join(T.ROOT, localRel.replace(/^\//, ''));
                if (fs.existsSync(localAbs)) {
                    const info = getImageInfo(localAbs);
                    ok(`${p.name} og:image is physically 1200x630 px`,
                        info[0] === 1200 && info[1] === 630,
                        `${info[0]}x${info[1]}`);
                }
            }

            // Twitter Card tags
            const twCard = html.match(/<meta\s+name=["']twitter:card["']\s+content=["']([^"']+)["']/i);
            const twTitle = html.match(/<meta\s+name=["']twitter:title["']\s+content=["']([^"']+)["']/i);
            const twDesc = html.match(/<meta\s+name=["']twitter:description["']\s+content=["']([^"']+)["']/i);
            const twImg = html.match(/<meta\s+name=["']twitter:image["']\s+content=["']([^"']+)["']/i);

            ok(`${p.name} has twitter:card summary_large_image`, twCard && twCard[1] === 'summary_large_image');
            ok(`${p.name} has twitter:title`, Boolean(twTitle && twTitle[1]));
            ok(`${p.name} has twitter:description`, Boolean(twDesc && twDesc[1]));
            ok(`${p.name} has twitter:image`, Boolean(twImg && twImg[1].startsWith('http')));

            // Public pages must NEVER have noindex
            const hasNoindex = html.includes('content="noindex"') || (res.headers['x-robots-tag'] || '').includes('noindex');
            ok(`${p.name} is indexable (zero noindex header or meta tag)`, !hasNoindex);
        }

        // Meta descriptions must be unique to each public page
        ok('Meta descriptions are unique across public pages', descriptions.size === 3, `unique count=${descriptions.size}`);
        ok('Canonical URLs are unique across public pages', canonicals.size === 3, `unique count=${canonicals.size}`);

        // =============================================================
        // 6. Organization Structured Data (JSON-LD)
        // =============================================================
        const homeRes = await httpGet(FRONTEND + 'index.php');
        const jsonLdMatches = [...homeRes.body.matchAll(/<script\s+type=["']application\/ld\+json["']>([\s\S]*?)<\/script>/gi)];
        ok('index.php contains JSON-LD structured data', jsonLdMatches.length > 0);

        let orgData = null;
        let hasEventData = false;
        for (const m of jsonLdMatches) {
            try {
                const parsed = JSON.parse(m[1]);
                if (parsed['@type'] === 'Organization') orgData = parsed;
                if (parsed['@type'] === 'Event' || (Array.isArray(parsed) && parsed.some(x => x['@type'] === 'Event'))) hasEventData = true;
            } catch (e) {}
        }

        ok('Organization structured data is present', Boolean(orgData));
        if (orgData) {
            ok('Organization name is BRAC University Cultural Club', orgData.name === 'BRAC University Cultural Club');
            ok('Organization alternateName is BUCuC', orgData.alternateName === 'BUCuC');
            ok('Organization logo is an absolute URL', Boolean(orgData.logo && orgData.logo.startsWith('http')));
            ok('Organization URL is an absolute URL', Boolean(orgData.url && orgData.url.startsWith('http')));
            ok('Organization includes social links in sameAs',
                Array.isArray(orgData.sameAs) &&
                orgData.sameAs.some(u => u.includes('facebook.com')) &&
                orgData.sameAs.some(u => u.includes('youtube.com')) &&
                orgData.sameAs.some(u => u.includes('instagram.com')),
                JSON.stringify(orgData.sameAs));
        }

        ok('Zero Event structured data (no event calendar)', !hasEventData);

        // =============================================================
        // 7. No Hidden Path Appears in Any Public File
        // =============================================================
        const publicFiles = [
            { name: 'robots.txt', content: robotsRes.body },
            { name: 'sitemap.xml', content: sitemapRes.body },
            { name: 'index.php HTML', content: (await httpGet(FRONTEND + 'index.php')).body },
            { name: 'past_events.php HTML', content: (await httpGet(FRONTEND + 'past_events.php')).body },
            { name: 'performances.php HTML', content: (await httpGet(FRONTEND + 'performances.php')).body },
        ];

        const sensitiveTokens = [
            'recover.php',
            'sponsors.php',
            'sponsors_export.php',
            'super_admin_dashboard.php',
            'recovery.token',
            'backend/backups',
            'backend/Database',
            'backend/cache',
            'backend/logs',
        ];

        for (const pf of publicFiles) {
            const leaked = sensitiveTokens.filter(tok => pf.content.includes(tok));
            ok(`No hidden path appears in ${pf.name}`, leaked.length === 0, leaked.length ? `leaked: ${leaked.join(', ')}` : 'clean');
        }

        await page.close();
    } finally {
        if (browser) await browser.close();
    }

    const fails = results.filter(r => r.startsWith('FAIL'));
    console.log(results.join('\n'));
    console.log(`\n${results.length - fails.length}/${results.length} passed`);
    process.exit(fails.length ? 1 : 0);
})().catch(err => {
    console.error('Fatal test error:', err);
    process.exit(1);
});
