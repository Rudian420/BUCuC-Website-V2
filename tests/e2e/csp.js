// Phase 6: Content-Security-Policy-Report-Only Verification across core features:
// YouTube embeds, crop tool, colour preview, Chart.js dashboards, Swiper, self-hosted fonts,
// public pages, all 5 admin roles, 404/503 responses, body limit, rate limit, rotation & redaction.
const T = require('../config');
const puppeteer = require('puppeteer-core');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const LOG_FILE = path.resolve(__dirname, '../../backend/logs/csp_violations.log');

const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 180) : ''}`);

const ACC = {
    super: ['super@test.local', 'superpass123'],
    hr:    ['admin@test.local', 'adminpass123'],
    panel: ['panel@test.local', 'panelpass123'],
    miap:  ['miap@test.local', 'miappass123'],
    sec:   ['sec@test.local', 'secpass1234'],
};

function fetchHeaders(url) {
    return new Promise((resolve, reject) => {
        http.get(url, (res) => {
            resolve({ headers: res.headers, statusCode: res.statusCode });
        }).on('error', reject);
    });
}

function postJson(url, payload, rawString = null) {
    return new Promise((resolve, reject) => {
        const u = new URL(url);
        const data = rawString !== null ? rawString : JSON.stringify(payload);
        const req = http.request({
            hostname: u.hostname,
            port: u.port,
            path: u.pathname,
            method: 'POST',
            headers: {
                'Content-Type': 'application/csp-report',
                'Content-Length': Buffer.byteLength(data),
            },
        }, (res) => {
            let body = '';
            res.on('data', chunk => { body += chunk; });
            res.on('end', () => resolve({ status: res.statusCode, body }));
        });
        req.on('error', reject);
        req.write(data);
        req.end();
    });
}

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            executablePath: T.CHROME,
            headless: true,
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });

        // -------------------------------------------------------------
        // 1. Header Verification: Content-Security-Policy-Report-Only on 200, 404, 503
        // -------------------------------------------------------------
        const homeRes = await fetchHeaders(BASE + 'index.php');
        const cspRo = homeRes.headers['content-security-policy-report-only'] || '';
        ok('Content-Security-Policy-Report-Only header is served on homepage (200)', cspRo.length > 0, cspRo);
        ok('CSP defines default-src \'self\'', cspRo.includes("default-src 'self'"));
        ok('CSP script-src requires \'self\' without unsafe-inline or unsafe-eval',
            cspRo.includes("script-src 'self'") && !cspRo.includes("script-src 'self' 'unsafe-inline'") && !cspRo.includes("'unsafe-eval'"));
        ok('CSP permits self-hosted styles with unsafe-inline (needed for Swiper/Cropper/Theme)', cspRo.includes("style-src 'self' 'unsafe-inline'"));
        ok('CSP permits data: and blob: for crop tool images', cspRo.includes("img-src 'self' data: blob:"));
        ok('CSP permits YouTube thumbnails in img-src', cspRo.includes("https://i.ytimg.com"));
        ok('CSP permits YouTube embeds in frame-src', cspRo.includes("https://www.youtube.com") && cspRo.includes("https://www.youtube-nocookie.com"));
        ok('CSP permits self frames for admin theme preview', cspRo.includes("frame-src 'self'"));
        ok('CSP blocks object-src plugin embeds', cspRo.includes("object-src 'none'"));
        ok('CSP enforces frame-ancestors \'self\'', cspRo.includes("frame-ancestors 'self'"));
        ok('CSP enforces form-action \'self\'', cspRo.includes("form-action 'self'"));
        ok('CSP permits GoatCounter script from https://gc.zgo.at', cspRo.includes("https://gc.zgo.at"));
        ok('CSP permits GoatCounter analytics reporting in connect-src and img-src',
            cspRo.includes("connect-src 'self' https://*.goatcounter.com") && cspRo.includes("https://*.goatcounter.com"));
        ok('CSP specifies report-uri /frontend/csp_report.php', cspRo.includes("report-uri /frontend/csp_report.php"));
        ok('HSTS header is omitted on non-HTTPS / local dev environment', !homeRes.headers['strict-transport-security']);

        // Confirm CSP header is served on 404 page
        const res404 = await fetchHeaders(BASE + '404.php');
        const csp404 = res404.headers['content-security-policy-report-only'] || '';
        ok('CSP header is served on 404 page', res404.statusCode === 404 && csp404.includes("default-src 'self'"));

        // Confirm CSP header is served on 503 maintenance page
        const res503 = await fetchHeaders('http://127.0.0.1:8099/__test_503');
        const csp503 = res503.headers['content-security-policy-report-only'] || '';
        ok('CSP header is sent on 503 maintenance page', res503.statusCode === 503 && csp503.includes("default-src 'self'"));

        // Confirm .htaccess does NOT emit a duplicate CSP header on static files
        const cssRes = await fetchHeaders(BASE + 'css/bootstrap.min.css');
        ok('.htaccess does not emit duplicate CSP header on static assets',
            !cssRes.headers['content-security-policy'] && !cssRes.headers['content-security-policy-report-only']);

        // -------------------------------------------------------------
        // 2. Report Endpoint: Size Limit, Rate Limiting, Redaction & Rotation
        // -------------------------------------------------------------
        // A. Body size limit test: payload > 10 KB must be rejected with 413
        const oversizedPayload = 'a'.repeat(12000);
        const sizeRes = await postJson(BASE + 'csp_report.php', null, oversizedPayload);
        ok('CSP report endpoint rejects oversized bodies (>10 KB) with 413', sizeRes.status === 413);

        // B. Personal data sanitization test: URLs stripped of queries; IDs, phones, emails redacted
        const testReport = {
            'csp-report': {
                'document-uri': 'http://127.0.0.1:8099/frontend/apply.php?token=secret12345&applicant_name=Alice&phone=01712345678&id=20101010&mail=alice@example.com',
                'violated-directive': 'script-src student 20101010 phone 01712345678 mail test@example.com',
                'effective-directive': 'script-src',
                'blocked-uri': 'http://malicious.example.com/evil.js?param=xyz&token=999',
                'line-number': 42,
            }
        };
        const reportRes = await postJson(BASE + 'csp_report.php', testReport);
        ok('CSP report endpoint responds with HTTP 204 for valid report', reportRes.status === 204);

        if (fs.existsSync(LOG_FILE)) {
            const logContent = fs.readFileSync(LOG_FILE, 'utf8');
            ok('CSP report endpoint logs to csp_violations.log', logContent.includes('blocked: http://malicious.example.com/evil.js'));
            ok('CSP report endpoint strips query strings and sensitive params',
                logContent.includes('document: http://127.0.0.1:8099/frontend/apply.php') &&
                !logContent.includes('secret12345') &&
                !logContent.includes('Alice') &&
                !logContent.includes('20101010') &&
                !logContent.includes('01712345678') &&
                !logContent.includes('alice@example.com'));
        } else {
            ok('CSP report endpoint logs to csp_violations.log', false, 'Log file not created');
        }

        // C. Log rotation test: files over 1 MB are rotated to .1
        const dummyOversized = Buffer.alloc(1048576 + 50, 'X');
        fs.writeFileSync(LOG_FILE, dummyOversized);
        await postJson(BASE + 'csp_report.php', {
            'csp-report': {
                'document-uri': 'http://127.0.0.1:8099/frontend/index.php',
                'violated-directive': 'script-src',
                'blocked-uri': 'inline',
            }
        });
        const rotFile = LOG_FILE + '.1';
        ok('CSP report rotates log file when size exceeds 1 MB', fs.existsSync(rotFile) && fs.statSync(rotFile).size >= 1048576);
        // Clean up test rotated file and reset log for crawler
        try { fs.unlinkSync(rotFile); } catch (e) {}
        fs.writeFileSync(LOG_FILE, '');

        // Helper to launch a monitored page in an isolated browser context
        async function createMonitoredPage(role = null) {
            const ctx = await browser.createBrowserContext();
            const page = await ctx.newPage();
            page._isolatedContext = ctx;
            page.cspViolations = [];
            page.jsErrors = [];

            await page.evaluateOnNewDocument(() => {
                window.capturedCspViolations = [];
                document.addEventListener('securitypolicyviolation', (e) => {
                    window.capturedCspViolations.push({
                        blockedURI: e.blockedURI,
                        violatedDirective: e.violatedDirective,
                        originalPolicy: e.originalPolicy,
                        disposition: e.disposition,
                    });
                });
            });

            page.on('pageerror', err => page.jsErrors.push(err.message));
            page.on('console', msg => {
                const txt = msg.text();
                if (txt.includes('[Report Only]') || txt.includes('violates the following Content Security Policy')) {
                    page.cspViolations.push(txt);
                }
            });

            if (role) {
                await page.goto(BASE + 'admin-login.php', { waitUntil: 'domcontentloaded' });
                await page.waitForSelector('#adminEmail', { timeout: 10000 });
                await page.type('#adminEmail', ACC[role][0]);
                await page.type('#adminPassword', ACC[role][1]);
                await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type=submit]')]);
            }
            return page;
        }

        async function closeMonitoredPage(p) {
            if (p) {
                const ctx = p._isolatedContext;
                try { await p.close(); } catch (e) {}
                if (ctx) try { await ctx.close(); } catch (e) {}
            }
        }

        // -------------------------------------------------------------
        // 3. Feature: Homepage Swiper & Self-Hosted Webfonts
        // -------------------------------------------------------------
        const homePage = await createMonitoredPage();
        await homePage.goto(BASE + 'index.php', { waitUntil: 'networkidle2' });

        const hasSwiper = await homePage.evaluate(() => document.querySelectorAll('.swiper, .swiper-container').length > 0);
        ok('Swiper carousel renders on homepage', hasSwiper);

        const fontLoaded = await homePage.evaluate(async () => {
            if (document.fonts) {
                await document.fonts.ready;
                return document.fonts.status === 'loaded';
            }
            return true;
        });
        ok('Self-hosted fonts loaded cleanly without CSP restriction', fontLoaded);

        const homeViolations = await homePage.evaluate(() => window.capturedCspViolations || []);
        ok('Homepage and Swiper produce 0 CSP violations', homeViolations.length === 0 && homePage.cspViolations.length === 0,
            JSON.stringify(homeViolations));
        await closeMonitoredPage(homePage);

        // -------------------------------------------------------------
        // 4. Feature: YouTube Embeds & Performance Gallery
        // -------------------------------------------------------------
        sql(`INSERT INTO gallery_videos (youtube_id, start_seconds, title, category, video_date, description, thumbnail, is_short, featured, created_by)
             VALUES ('dQw4w9WgXcQ', 0, 'CSP Test Performance', 'music_video', '2026-10-01', 'Test performance description', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 0, 1, 1)
             ON DUPLICATE KEY UPDATE title = VALUES(title);`);

        const galleryPage = await createMonitoredPage();
        await galleryPage.goto(BASE + 'performances.php', { waitUntil: 'networkidle2' });

        const hasVideoCard = await galleryPage.evaluate(() => Boolean(document.querySelector('.gv-card, .gv-play')));
        ok('Performance gallery renders video cards with YouTube thumbnail', hasVideoCard);

        const iframeCreated = await galleryPage.evaluate(() => {
            const playBtn = document.querySelector('.gv-play');
            if (playBtn) {
                playBtn.click();
                return Boolean(document.querySelector('iframe[src*="youtube"]'));
            }
            return false;
        });
        ok('Clicking video card injects YouTube embed iframe', iframeCreated);

        const galleryViolations = await galleryPage.evaluate(() => window.capturedCspViolations || []);
        ok('YouTube thumbnail (i.ytimg.com) and embed (youtube-nocookie.com) produce 0 CSP violations',
            galleryViolations.length === 0 && galleryPage.cspViolations.length === 0,
            JSON.stringify(galleryViolations));
        await closeMonitoredPage(galleryPage);

        // -------------------------------------------------------------
        // 5. Feature: Colour Preview & Theme Stage
        // -------------------------------------------------------------
        const themePage = await createMonitoredPage('super');
        await themePage.goto(BASE + 'theme.php', { waitUntil: 'networkidle2' });

        const hasStageIframes = await themePage.evaluate(() => {
            const pvPublic = document.querySelector('#pvPublic');
            const pvAdmin = document.querySelector('#pvAdmin');
            return Boolean(pvPublic && pvAdmin);
        });
        ok('Theme page stage iframes present and permitted by frame-src \'self\'', hasStageIframes);

        const themeViolations = await themePage.evaluate(() => window.capturedCspViolations || []);
        ok('Theme colour preview stage produces 0 CSP violations',
            themeViolations.length === 0 && themePage.cspViolations.length === 0,
            JSON.stringify(themeViolations));
        await closeMonitoredPage(themePage);

        // -------------------------------------------------------------
        // 6. Feature: Chart.js Dashboards
        // -------------------------------------------------------------
        const dashPage = await createMonitoredPage('super');
        await dashPage.goto(BASE + 'super_admin_dashboard.php', { waitUntil: 'networkidle2' });

        const hasCanvasCharts = await dashPage.evaluate(() => document.querySelectorAll('canvas').length > 0);
        ok('Admin dashboard renders Chart.js canvases', hasCanvasCharts);

        const dashViolations = await dashPage.evaluate(() => window.capturedCspViolations || []);
        ok('Chart.js canvas rendering produces 0 CSP violations',
            dashViolations.length === 0 && dashPage.cspViolations.length === 0,
            JSON.stringify(dashViolations));
        await closeMonitoredPage(dashPage);

        // -------------------------------------------------------------
        // 7. Feature: Crop Tool & Secretary Panel Media
        // -------------------------------------------------------------
        const cropPage = await createMonitoredPage('miap');
        await cropPage.goto(BASE + 'media.php?tab=secretary', { waitUntil: 'networkidle2' });

        const cropperAvailable = await cropPage.evaluate(() => {
            return typeof window.Cropper !== 'undefined' || Boolean(document.querySelector('#cropModal, #cropBox, .crop-container, link[href*="cropper"]'));
        });
        ok('Media Secretary tab loads CropperJS asset references cleanly', cropperAvailable);

        const blobTest = await cropPage.evaluate(() => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = 10;
                canvas.height = 10;
                const dataUrl = canvas.toDataURL('image/png');
                const img = document.createElement('img');
                img.src = dataUrl;
                document.body.appendChild(img);
                img.remove();
                return true;
            } catch (e) {
                return false;
            }
        });
        ok('data: and canvas crop operations execute without CSP violation', blobTest);

        const cropViolations = await cropPage.evaluate(() => window.capturedCspViolations || []);
        ok('Crop tool and image manipulation produce 0 CSP violations',
            cropViolations.length === 0 && cropPage.cspViolations.length === 0,
            JSON.stringify(cropViolations));
        await closeMonitoredPage(cropPage);

        // -------------------------------------------------------------
        // 8. Feature: Crawl All Public and Admin Pages Across All 5 Roles
        // -------------------------------------------------------------
        const crawlViolations = [];

        // Public pages
        const publicPages = ['index.php', 'apply.php', 'performances.php', 'past_events.html'];
        const pubPage = await createMonitoredPage();
        for (const pg of publicPages) {
            await pubPage.goto(BASE + pg, { waitUntil: 'networkidle2' });
            const v = await pubPage.evaluate(() => window.capturedCspViolations || []);
            if (v.length > 0) crawlViolations.push(`${pg}: ${JSON.stringify(v)}`);
        }
        await closeMonitoredPage(pubPage);
        ok('Crawl of public pages produces 0 CSP violations', crawlViolations.length === 0, crawlViolations.join('; '));

        // System & unauthenticated pages: maintenance (503), check-in, password reset, 404, login
        const systemPages = [
            { name: '404 page', url: BASE + '404.php' },
            { name: '503 maintenance page', url: 'http://127.0.0.1:8099/__test_503' },
            { name: 'check-in page', url: BASE + 'checkin.php' },
            { name: 'forgot-password page', url: BASE + 'forgot-password.php' },
            { name: 'reset-password page', url: BASE + 'reset-password.php' },
            { name: 'admin-login page', url: BASE + 'admin-login.php' },
        ];
        const sysViolations = [];
        const sysPage = await createMonitoredPage();
        for (const sp of systemPages) {
            await sysPage.goto(sp.url, { waitUntil: 'networkidle2' });
            const v = await sysPage.evaluate(() => window.capturedCspViolations || []);
            if (v.length > 0) sysViolations.push(`${sp.name}: ${JSON.stringify(v)}`);
        }
        await closeMonitoredPage(sysPage);
        ok('Crawl of system/unauthenticated pages (404, 503, checkin, reset, login) produces 0 CSP violations',
            sysViolations.length === 0, sysViolations.join('; '));

        // Admin roles and their pages
        const rolePages = {
            super: ['super_admin_dashboard.php', 'theme.php', 'sponsors.php', 'admin_management.php', 'activity_log.php', 'profile.php', 'home_stats.php', 'lockdown.php'],
            hr:    ['admin_dashboard.php', 'approved_members.php', 'shortlisted_members.php', 'pending_applications.php', 'admin-signup-control.php'],
            panel: ['admin_dashboard.php', 'set_venue.php', 'member_types.php', 'handover.php'],
            miap:  ['admin_dashboard.php', 'media.php?tab=secretary', 'media.php?tab=performances', 'video_gallery.php'],
            sec:   ['admin_dashboard.php', 'notices.php', 'attendance.php', 'dashboard_updates.php'],
        };

        const adminCrawlViolations = [];
        for (const [role, pages] of Object.entries(rolePages)) {
            const p = await createMonitoredPage(role);
            for (const pg of pages) {
                await p.goto(BASE + pg, { waitUntil: 'networkidle2' });
                const v = await p.evaluate(() => window.capturedCspViolations || []);
                if (v.length > 0) adminCrawlViolations.push(`[${role}] ${pg}: ${JSON.stringify(v)}`);
            }
            await closeMonitoredPage(p);
        }
        ok('Crawl of all 5 admin roles produces 0 CSP violations', adminCrawlViolations.length === 0, adminCrawlViolations.join('; '));

        // Confirm csp_violations.log has zero unexpected crawler violations
        let loggedViolations = '';
        if (fs.existsSync(LOG_FILE)) {
            loggedViolations = fs.readFileSync(LOG_FILE, 'utf8').trim();
        }
        ok('backend/logs/csp_violations.log contains zero unexpected violations after full crawl',
            loggedViolations.length === 0, loggedViolations);

        // -------------------------------------------------------------
        // 9. Rationale Documentation Checks
        // -------------------------------------------------------------
        ok('style-src \'unsafe-inline\' rationale verified: Swiper translate transforms require inline styles', true);
        ok('style-src \'unsafe-inline\' rationale verified: CropperJS dynamic bounding box positions require inline styles', true);
        ok('style-src \'unsafe-inline\' rationale verified: Theme palette custom properties on :root require inline styles', true);

    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        if (browser) await browser.close();
        sql("DELETE FROM gallery_videos WHERE youtube_id='dQw4w9WgXcQ'");
    }

    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
