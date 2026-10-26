// Phase 8, Part 1: GoatCounter Privacy Analytics Verification
// Tests:
// - Present when GOATCOUNTER_CODE is set on public pages
// - Asynchronous loading, privacy-friendly, respects Do Not Track
// - Completely absent when code is empty or unset
// - Completely absent on private/hidden/admin/login/reset/checkin/404/503 pages
// - Completely absent when an authenticated admin session is active (even on public pages)
// - Zero tracking cookies created
const T = require('../config');
const puppeteer = require('puppeteer-core');
const http = require('http');
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

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            executablePath: T.CHROME,
            headless: 'new',
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        });

        // =============================================================
        // 1. Script Presence on Public Pages when GOATCOUNTER_CODE is set
        // =============================================================
        const publicPages = [
            { name: 'Homepage (/)', url: BASE },
            { name: 'Past Events', url: FRONTEND + 'past_events.php' },
            { name: 'Performances', url: FRONTEND + 'performances.php' },
        ];

        for (const p of publicPages) {
            const res = await httpGet(p.url);
            ok(`${p.name} returns HTTP 200`, res.status === 200);

            const html = res.body;
            const hasGc = html.includes('data-goatcounter="https://bucuc.goatcounter.com/count"') &&
                          html.includes('src="https://gc.zgo.at/count.js"');
            ok(`${p.name} includes GoatCounter snippet when site code is set`, hasGc);

            const isAsync = /<script\s+[^>]*async[^>]*src="https:\/\/gc\.zgo\.at\/count\.js"/.test(html) ||
                            /<script\s+[^>]*src="https:\/\/gc\.zgo\.at\/count\.js"[^>]*async/.test(html);
            ok(`${p.name} GoatCounter script tag loads asynchronously`, isAsync);

            // Ensure snippet does not override Do Not Track (no allow_dnt: true)
            ok(`${p.name} respects browser Do Not Track by default`, !html.includes('"allow_dnt":true') && !html.includes("'allow_dnt':true"));
        }

        // =============================================================
        // 2. Script Omission when GOATCOUNTER_CODE is Empty or Unset
        // =============================================================
        const emptyCodeScript = `
            define('APP_URL', 'http://127.0.0.1:8099');
            define('GOATCOUNTER_CODE', '');
            require_once 'backend/Database/config.php';
            require_once 'backend/includes/site.php';
            echo json_encode(['rendered' => site_render_analytics()]);
        `;
        const emptyCodeRaw = execFileSync(T.PHP, ['-r', emptyCodeScript], { cwd: T.ROOT }).toString().trim();
        const emptyCodeData = JSON.parse(emptyCodeRaw);
        ok('site_render_analytics() returns empty string when GOATCOUNTER_CODE is empty', emptyCodeData.rendered === '');

        const unsetCodeScript = `
            define('APP_URL', 'http://127.0.0.1:8099');
            require_once 'backend/includes/site.php';
            // GOATCOUNTER_CODE is not defined in this runtime
            echo json_encode(['rendered' => site_render_analytics()]);
        `;
        const unsetCodeRaw = execFileSync(T.PHP, ['-r', unsetCodeScript], { cwd: T.ROOT }).toString().trim();
        const unsetCodeData = JSON.parse(unsetCodeRaw);
        ok('site_render_analytics() returns empty string when GOATCOUNTER_CODE is unset/default', unsetCodeData.rendered === '');

        // =============================================================
        // 3. Script Absence on Non-Public, Protected & Error Pages
        // =============================================================
        const nonPublicPages = [
            'admin-login.php',
            'forgot-password.php',
            'reset-password.php',
            'recover.php',
            'checkin.php',
            '404.php',
            'sponsors.php',
            'admin_dashboard.php',
            'super_admin_dashboard.php',
        ];

        for (const pg of nonPublicPages) {
            const res = await httpGet(FRONTEND + pg);
            const html = res.body;
            const hasGc = html.includes('goatcounter.com') || html.includes('gc.zgo.at');
            ok(`GoatCounter script is completely absent on ${pg}`, !hasGc);
        }

        // 503 Maintenance page
        const res503 = await httpGet('http://127.0.0.1:8099/__test_503');
        const hasGc503 = res503.body.includes('goatcounter.com') || res503.body.includes('gc.zgo.at');
        ok('GoatCounter script is completely absent on 503 maintenance page', !hasGc503);

        // =============================================================
        // 4. Tracking Suppression when Admin Session is Active
        // =============================================================
        // A. Log in as admin in browser context
        const page = await browser.newPage();
        await page.goto(FRONTEND + 'admin-login.php', { waitUntil: 'domcontentloaded' });
        await page.type('#adminEmail', 'super@test.local');
        await page.type('#adminPassword', 'superpass123');
        await Promise.all([
            page.click('button[type="submit"]'),
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        ]);

        // Navigate to public homepage with active admin session
        await page.goto(BASE, { waitUntil: 'domcontentloaded' });
        const homeContentAdmin = await page.content();
        const hasGcOnHomeAdmin = homeContentAdmin.includes('goatcounter.com') || homeContentAdmin.includes('gc.zgo.at');
        ok('GoatCounter script is suppressed on homepage when admin is logged in', !hasGcOnHomeAdmin);

        // Navigate to public past_events.php with active admin session
        await page.goto(FRONTEND + 'past_events.php', { waitUntil: 'domcontentloaded' });
        const eventsContentAdmin = await page.content();
        const hasGcOnEventsAdmin = eventsContentAdmin.includes('goatcounter.com') || eventsContentAdmin.includes('gc.zgo.at');
        ok('GoatCounter script is suppressed on past events when admin is logged in', !hasGcOnEventsAdmin);

        // B. Confirm in fresh visitor context (no admin session), script is present
        const incognitoCtx = await browser.createBrowserContext();
        const visitorPage = await incognitoCtx.newPage();
        await visitorPage.goto(BASE, { waitUntil: 'domcontentloaded' });
        const homeContentVisitor = await visitorPage.content();
        const hasGcOnHomeVisitor = homeContentVisitor.includes('goatcounter.com') && homeContentVisitor.includes('gc.zgo.at');
        ok('GoatCounter script is present for normal visitors without admin session', hasGcOnHomeVisitor);

        // =============================================================
        // 5. Zero Tracking Cookies
        // =============================================================
        const visitorCookies = await visitorPage.cookies();
        const hasTrackingCookie = visitorCookies.some(c => /goat|counter|track|_ga|_gid/i.test(c.name));
        ok('Zero analytics/tracking cookies set for visitor', !hasTrackingCookie, JSON.stringify(visitorCookies.map(c => c.name)));

        await page.close();
        await visitorPage.close();
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
