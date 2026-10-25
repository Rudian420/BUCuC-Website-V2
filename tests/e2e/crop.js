// Built-in crop and resize on Website Media. Uses generated photos in ../cropimg.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const REPO = T.ROOT_FWD + '/frontend/';
const IMG = T.CROPIMG;
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 200) : ''}`);
const dims = rel => execFileSync(T.PHP, ['-r', `$i=getimagesize('${REPO}${rel}'); echo $i[0],'x',$i[1],' ',$i['mime'];`]).toString();
const wait = ms => new Promise(r => setTimeout(r, ms));

(async () => {
    const browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 60000 });
    const created = [];
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.setViewport({ width: 1280, height: 900 });
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', 'super@test.local'); await page.type('#adminPassword', 'superpass123');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        const tid = sql('SELECT id FROM tenures WHERE is_current=1');
        const url = BASE + `media.php?tab=tenures&t=${tid}`;
        const panelInput = 'form:has(input[name=grp][value=panel]) input[name=photo]';
        const box = sel => page.$eval(sel, i => { const f = i.closest('form'); const b = f.querySelector('.crop-preview'); return { hidden: b.hidden, dim: b.querySelector('.cp-dim').textContent, meta: b.querySelector('.cp-meta').textContent, warn: b.querySelector('.cp-warn').hidden ? '' : b.querySelector('.cp-warn').textContent, msg: f.querySelector('.check-msg').textContent, file: i.files[0] ? [i.files[0].name, i.files[0].type, i.files[0].size] : null }; });
        const modalOpen = () => page.$eval('.crop-modal', m => !m.hidden);
        const waitReady = sel => page.waitForFunction(s => { const t = document.querySelector(s).closest('form').querySelector('.check-msg').textContent; return t.startsWith('Ready') || t.startsWith('Not') || t.startsWith('This'); }, { timeout: 20000 }, sel);
        async function addPerson(name, file, expectCrop) {
            await page.goto(url);
            await page.type('form:has(input[name=grp][value=panel]) input[name=name]', name);
            await page.type('form:has(input[name=grp][value=panel]) input[name=position]', 'Probe');
            await (await page.$(panelInput)).uploadFile(path.join(IMG, file));
            if (expectCrop) {
                await page.waitForSelector('.crop-modal:not([hidden]) .cropper-container', { timeout: 10000 });
                await wait(400);
            }
        }
        async function submitPanel() {
            await Promise.all([page.waitForNavigation(), page.$eval(panelInput, i => i.closest('form').querySelector('button[type=submit]').click())]);
            return page.content();
        }
        const lastImage = name => sql(`SELECT image FROM tenure_people WHERE name='${name}' ORDER BY id DESC LIMIT 1`);

        // 1. wrong ratio (3:2 photo for a 4:5 slot) → crop window, result scaled to the max
        await addPerson('Crop Wide', 'wide.jpg', true);
        ok('wrong ratio opens the crop window with the rule', await modalOpen() && (await page.$eval('.crop-rule', e => e.textContent)).includes('4:5'));
        ok('crop window shows the result size live', /Result: \d+ × \d+ px/.test(await page.$eval('.crop-size', e => e.textContent)), await page.$eval('.crop-size', e => e.textContent));
        await page.click('[data-act="in"]'); await wait(200);
        await page.click('[data-act="use"]');
        await waitReady(panelInput);
        let b = await box(panelInput);
        ok('cropped photo previewed with its final size (800 × 1000 after scaling)', !b.hidden && b.dim === '800 × 1000 px', JSON.stringify(b));
        const [cw, ch] = b.dim.match(/\d+/g).map(Number);
        ok('crop keeps 4:5 and stays within 800 × 1000', Math.abs(cw / ch - 0.8) / 0.8 <= 0.02 && cw <= 800 && ch <= 1000, b.dim);
        ok('cropped photo saved as JPEG, input holds the new file', b.file && b.file[1] === 'image/jpeg' && b.meta.includes('cropped'), JSON.stringify(b.file) + ' ' + b.meta);
        let html = await submitPanel(); console.error('ALERT1', await page.evaluate(() => [...document.querySelectorAll('.alert')].map(a => a.textContent.trim()).join(' | ').slice(0, 300)), page.url());
        let img = lastImage('Crop Wide'); created.push(img);
        ok('server accepts the cropped photo', !!img && (await page.$('.alert-danger')) === null, img);
        ok('stored file has the previewed size', img && dims(img).startsWith(`${cw}x${ch}`), img && dims(img));

        // 2. right ratio but too big → no crop window, scaled down automatically
        await addPerson('Crop Big', 'bigpanel.jpg', false);
        await waitReady(panelInput);
        b = await box(panelInput);
        ok('right shape, too big: no crop window, scaled to 800 × 1000', !(await modalOpen()) && b.dim === '800 × 1000 px' && b.meta.includes('scaled down from 1600 × 2000'), JSON.stringify(b));
        await submitPanel();
        img = lastImage('Crop Big'); created.push(img);
        ok('server accepts it at 800 × 1000', img && dims(img).startsWith('800x1000'), img && dims(img));

        // 3. already right → uploaded as it is, Crop button offered
        await addPerson('Crop Ok', 'okpanel.jpg', false);
        await waitReady(panelInput);
        b = await box(panelInput);
        ok('already right: no crop window, uploaded as it is', !(await modalOpen()) && b.dim === '400 × 500 px' && b.meta.includes('uploaded as it is') && b.file[2] === fs.statSync(path.join(IMG, 'okpanel.jpg')).size, JSON.stringify(b));
        ok('…with a Crop button to adjust anyway', !!(await page.$eval(panelInput, i => !!i.closest('form').querySelector('.cp-crop'))));
        await page.$eval(panelInput, i => i.closest('form').querySelector('.cp-crop').click());
        await page.waitForSelector('.crop-modal:not([hidden]) .cropper-container');
        await wait(300);
        await page.click('[data-act="cancel"]');
        b = await box(panelInput);
        ok('cancelling an optional crop keeps the photo', !(await modalOpen()) && !b.hidden && b.file && b.file[2] === fs.statSync(path.join(IMG, 'okpanel.jpg')).size);
        await submitPanel();
        img = lastImage('Crop Ok'); created.push(img);
        ok('server accepts the untouched photo (not enlarged)', img && dims(img).startsWith('400x500'), img && dims(img));

        // 4. very small → warning, still allowed, never enlarged
        await addPerson('Crop Tiny', 'tiny.jpg', false);
        await waitReady(panelInput);
        b = await box(panelInput);
        ok('very small photo: blurry warning, kept at 200 × 250', b.dim === '200 × 250 px' && b.warn.includes('may look blurry'), JSON.stringify(b));
        await submitPanel();
        img = lastImage('Crop Tiny'); created.push(img);
        ok('…and it can still be uploaded', img && dims(img).startsWith('200x250'), img && dims(img));

        // 5. wrong ratio, cancel → nothing to upload
        await addPerson('Crop Cancel', 'wide.jpg', true);
        await page.click('[data-act="cancel"]');
        b = await box(panelInput);
        ok('cancelling a required crop clears the photo with a message', b.file === null && b.hidden && b.msg.includes('must be cropped to 4:5'), JSON.stringify(b));
        await page.keyboard.press('Escape');

        // 6. event: 4:3 PNG → crop to 3:2 → WebP at most 1500 × 1000
        await page.goto(BASE + 'media.php?tab=events');
        await page.type('input[name=title]', 'Crop Event'); await page.type('input[name=event_date]', '01052026');
        await (await page.$('input[name=photo][data-rule=events]')).uploadFile(path.join(IMG, 'event.png'));
        await page.waitForSelector('.crop-modal:not([hidden]) .cropper-container'); await wait(400);
        ok('event crop locked to 3:2', (await page.$eval('.crop-rule', e => e.textContent)).includes('3:2'));
        await page.click('[data-act="use"]');
        await waitReady('input[name=photo][data-rule=events]');
        b = await box('input[name=photo][data-rule=events]');
        ok('PNG event photo becomes WebP at 1500 × 1000', b.dim === '1500 × 1000 px' && b.file[1] === 'image/webp', JSON.stringify(b));
        await Promise.all([page.waitForNavigation(), page.$eval('input[name=photo][data-rule=events]', i => i.closest('form').querySelector('button[type=submit]').click())]);
        img = sql("SELECT image FROM site_events WHERE title='Crop Event'"); created.push(img);
        ok('server accepts the WebP event photo', img.endsWith('.webp') && dims(img).startsWith('1500x1000 image/webp'), img && dims(img));
        ok('no JavaScript errors', errors.length === 0, errors.join(' | '));

        // 7. phone: touch, crop window fits the screen
        const m = await browser.newPage();
        await m.setCookie(...(await page.cookies()));
        await m.emulate({ viewport: { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' });
        await m.goto(url);
        await (await m.$(panelInput)).uploadFile(path.join(IMG, 'wide.jpg'));
        await m.waitForSelector('.crop-modal:not([hidden]) .cropper-container'); await wait(500);
        const lay = await m.evaluate(() => { const r = document.querySelector('.crop-box').getBoundingClientRect(); const u = document.querySelector('[data-act="use"]').getBoundingClientRect(); return { l: r.left, r: r.right, b: r.bottom, ub: u.bottom, uh: u.height, sw: document.documentElement.scrollWidth, vw: innerWidth, vh: innerHeight }; });
        ok('phone: crop window fits the screen, buttons reachable and big enough', lay.l >= 0 && lay.r <= lay.vw && lay.ub <= lay.vh && lay.uh >= 38, JSON.stringify(lay));
        await m.screenshot({ path: path.join(T.RUNTIME, 'shot_crop_mobile.png') });
        // drag the photo with a finger: the crop result changes
        const before = await m.evaluate(() => ['.cropper-canvas', '.cropper-crop-box'].map(q => { const e = document.querySelector(q); return e.style.transform + e.style.left + e.style.top; }).join('|'));
        const st = await m.$eval('.cropper-face', e => { const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
        const cdp = await m.target().createCDPSession();
        const touch = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] });
        await touch('touchStart', st.x, st.y); for (let i = 1; i <= 8; i++) await touch('touchMove', st.x + i * 10, st.y); await touch('touchEnd');
        await wait(300);
        const after = await m.evaluate(() => ['.cropper-canvas', '.cropper-crop-box'].map(q => { const e = document.querySelector(q); return e.style.transform + e.style.left + e.style.top; }).join('|'));
        ok('phone: dragging with a finger moves the photo', before !== after, `${before} → ${after}`);
        // two-finger pinch zooms
        const z0 = await m.evaluate(() => document.querySelector('.cropper-canvas').getBoundingClientRect().width);
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: st.x - 20, y: st.y, id: 1 }, { x: st.x + 20, y: st.y, id: 2 }] });
        for (let i = 1; i <= 8; i++) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: st.x - 20 - i * 8, y: st.y, id: 1 }, { x: st.x + 20 + i * 8, y: st.y, id: 2 }] });
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await wait(300);
        const z1 = await m.evaluate(() => document.querySelector('.cropper-canvas').getBoundingClientRect().width);
        ok('phone: pinching zooms in', z1 > z0 * 1.05, `${Math.round(z0)} → ${Math.round(z1)}`);
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        // clean up: rows + files
        sql("DELETE FROM tenure_people WHERE name LIKE 'Crop %'; DELETE FROM site_events WHERE title='Crop Event'");
        for (const f of created) if (f) try { fs.unlinkSync(REPO + f); } catch (e) { }
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
