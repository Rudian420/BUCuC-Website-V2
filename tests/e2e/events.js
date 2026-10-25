// Past events: every event (incl. the imported hand-written ones) editable and deletable in Website Media.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '--default-character-set=utf8mb4', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const BASE = 'http://127.0.0.1:8099/frontend/';
const REPO = T.ROOT_FWD + '/frontend/';
const IMG = T.CROPIMG;
const results = [];
const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${extra ? '  — ' + String(extra).slice(0, 220) : ''}`);
const ACC = { super: ['super@test.local', 'superpass123'], miap: ['miap@test.local', 'miappass123'], sec: ['sec@test.local', 'secpass1234'] };
let browser;
async function as(role) {
    const page = await (await browser.createBrowserContext()).newPage();
    page.errors = [];
    page.on('pageerror', e => page.errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.setViewport({ width: 1280, height: 900 });
    if (role) {
        await page.goto(BASE + 'admin-login.php');
        await page.type('#adminEmail', ACC[role][0]); await page.type('#adminPassword', ACC[role][1]);
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    return page;
}
const alertOf = page => page.$eval('.alert', e => e.textContent.trim()).catch(() => '');
const submitForm = page => Promise.all([page.waitForNavigation(), page.$eval('#eventForm form', f => f.querySelector('button[type=submit]').click())]);
const setVal = (page, sel, v) => page.$eval(sel, (e, v) => { e.value = v; }, v);
const evId = anchor => sql(`SELECT id FROM site_events WHERE anchor='${anchor}'`);

(async () => {
    browser = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 60000 });
    const created = [];
    try {
        const S = await as('super');
        const V = await as(null);

        // ---------- import
        await S.goto(BASE + 'media.php?tab=events');   // the first page that lists events imports the older ones
        ok('12 older events imported once', sql("SELECT COUNT(*) FROM site_events WHERE anchor IS NOT NULL") === '12');
        const listed = await S.$$eval('.ev-row', rs => rs.length);
        ok('Website Media lists every event, older ones included', listed === 12, listed);
        ok('each event has View, Edit and Remove', (await S.$$('.ev-row a[href*="edit="]')).length === 12 && (await S.$$('.ev-row input[name=action][value=event_remove]')).length === 12);

        // ---------- old links still land on the right story
        await V.goto(BASE + 'past_events.php', { waitUntil: 'networkidle2' });
        const ids = await V.$$eval('.event-detail-card', cs => cs.map(c => c.id));
        ok('Past Events page keeps the old anchors, newest first', ids.slice(0, 3).join(',') === 'event-6,event-7,event-8' && ids.includes('qawali-night') && ids.length === 12, ids.join(','));
        const broken = await V.evaluate(async () => {
            const imgs = [...document.querySelectorAll('.event-detail-card img.event-image')];
            for (const i of imgs) { i.loading = 'eager'; i.scrollIntoView(); }
            await new Promise(r => setTimeout(r, 2500));
            return imgs.filter(i => !i.complete || i.naturalWidth === 0 || i.src.includes('placeholder')).map(i => i.getAttribute('src'));
        });
        ok('every event photo loads', broken.length === 0, broken.join(', '));
        ok('photo framing kept (bootcamp, handover, Rythm Revive 24.0)', await V.$eval('#event-6 img', i => i.classList.contains('event-image-bootcamp')) && await V.$eval('#event-10 img', i => i.classList.contains('event-image-qwe')));
        ok('bold and italic kept', (await V.$$eval('#event-10 .event-description strong', s => s.length)) >= 5 && (await V.$$eval('#event-10 .event-description em', s => s.length)) >= 1);
        ok('number tiles kept', (await V.$$eval('#event-7 .stat-card', s => s.map(x => x.textContent.replace(/\s+/g, ' ').trim()))).join(';') === '400+ Attendees;17 Performances;3 Band Performance;4 Hours');
        await V.goto(BASE + 'index.php');
        const home = await V.$$eval('.events-grid a.event-card', as => as.map(a => a.getAttribute('href')));
        ok('homepage cards: the 5 newest, linking to the stories', home.slice(0, 5).join(',') === 'past_events.php#event-6,past_events.php#event-7,past_events.php#event-8,past_events.php#shrabon-chonde,past_events.php#country-music', home.join(','));

        // ---------- edit an older event (text, organizer, tiles), keep its photo
        const e7 = evId('event-7');
        await S.goto(BASE + `media.php?tab=events&edit=${e7}`);
        ok('edit form prefilled with all the details', (await S.$eval('#eventForm input[name=title]', i => i.value)) === 'Rhythm Revive 25.1' && (await S.$eval('#eventForm textarea[name=stats]', t => t.value)).includes('400+ | Attendees') && (await S.$eval('#eventForm input[name=organizer]', i => i.value)) === 'By BUCuC');
        await setVal(S, '#eventForm input[name=title]', 'Rhythm Revive 25.1 (edited)');
        await setVal(S, '#eventForm textarea[name=stats]', '450+ | Attendees\n18 | **Performances**');
        await setVal(S, '#eventForm textarea[name=highlights]', 'First **bold** highlight\nSecond *italic* one');
        await setVal(S, '#eventForm input[name=organizer]', 'By BUCuC & friends');
        await submitForm(S);
        ok('older event saved', (await alertOf(S)).includes('was saved'), await alertOf(S));
        ok('photo and anchor unchanged', sql(`SELECT CONCAT(image,'|',anchor) FROM site_events WHERE id=${e7}`) === 'images/slide8.jpg|event-7');
        await V.goto(BASE + 'past_events.php#event-7');
        const card = await V.$eval('#event-7', c => ({ t: c.querySelector('.event-title-large').textContent, meta: c.querySelector('.event-meta').textContent, hl: c.querySelector('.highlights-list').innerHTML, stats: [...c.querySelectorAll('.stat-card')].map(x => x.textContent.replace(/\s+/g, ' ').trim()) }));
        ok('public page shows the edit', card.t === 'Rhythm Revive 25.1 (edited)' && card.meta.includes('By BUCuC & friends') && card.hl.includes('<strong>bold</strong>') && card.hl.includes('<em>italic</em>') && card.stats.join(';') === '450+ Attendees;18 **Performances**', JSON.stringify(card));
        ok('edit logged with what changed', sql("SELECT details FROM admin_activity_log WHERE action='event_updated' ORDER BY id DESC LIMIT 1").includes('title, organizer, highlights, number tiles'));

        // ---------- bad tile line refused, typed values kept
        await S.goto(BASE + `media.php?tab=events&edit=${e7}`);
        await setVal(S, '#eventForm textarea[name=stats]', '450+ Attendees');
        await setVal(S, '#eventForm input[name=title]', 'Kept typed title');
        await submitForm(S);
        ok('a tile line without "|" is refused, form keeps what was typed', (await alertOf(S)).includes('number | label') && (await S.$eval('#eventForm input[name=title]', i => i.value)) === 'Kept typed title' && sql(`SELECT title FROM site_events WHERE id=${e7}`) === 'Rhythm Revive 25.1 (edited)', await alertOf(S));
        ok('future date refused', await (async () => {
            await S.goto(BASE + `media.php?tab=events&edit=${e7}`);
            await setVal(S, '#eventForm input[name=event_date]', '2099-01-01');
            await S.$eval('#eventForm input[name=event_date]', i => i.removeAttribute('max'));
            await submitForm(S);
            return (await alertOf(S)).includes('cannot be in the future');
        })());

        // ---------- replace the photo of an older event: original file kept
        await S.goto(BASE + `media.php?tab=events&edit=${e7}`);
        await (await S.$('#eventForm input[name=photo]')).uploadFile(path.join(IMG, 'event.png'));
        await S.waitForSelector('.crop-modal:not([hidden]) .cropper-container');
        await new Promise(r => setTimeout(r, 400));
        await S.click('[data-act="use"]');
        await S.waitForFunction(() => document.querySelector('#eventForm .check-msg').textContent.startsWith('Ready'), { timeout: 20000 });
        await submitForm(S);
        const newImg = sql(`SELECT CONCAT(image,'|',IFNULL(image_class,'-')) FROM site_events WHERE id=${e7}`);
        created.push(newImg.split('|')[0]);
        ok('new photo saved (via the crop tool), the original slide8.jpg kept', newImg.startsWith('images/events/') && fs.existsSync(REPO + 'images/slide8.jpg'), newImg);
        const e6 = evId('event-6');
        await S.goto(BASE + `media.php?tab=events&edit=${e6}`);
        await (await S.$('#eventForm input[name=photo]')).uploadFile(path.join(IMG, 'event.png'));
        await S.waitForSelector('.crop-modal:not([hidden]) .cropper-container'); await new Promise(r => setTimeout(r, 400));
        await S.click('[data-act="use"]');
        await S.waitForFunction(() => document.querySelector('#eventForm .check-msg').textContent.startsWith('Ready'), { timeout: 20000 });
        await submitForm(S);
        ok('replacing a photo drops the old special framing', sql(`SELECT IFNULL(image_class,'none') FROM site_events WHERE id=${e6}`) === 'none');
        created.push(sql(`SELECT image FROM site_events WHERE id=${e6}`));

        // ---------- uploaded photo replaced again: the uploaded file is deleted
        const firstUpload = sql(`SELECT image FROM site_events WHERE id=${e7}`);
        await S.goto(BASE + `media.php?tab=events&edit=${e7}`);
        await (await S.$('#eventForm input[name=photo]')).uploadFile(path.join(IMG, 'event.png'));
        await S.waitForSelector('.crop-modal:not([hidden]) .cropper-container'); await new Promise(r => setTimeout(r, 400));
        await S.click('[data-act="use"]');
        await S.waitForFunction(() => document.querySelector('#eventForm .check-msg').textContent.startsWith('Ready'), { timeout: 20000 });
        await submitForm(S);
        created.push(sql(`SELECT image FROM site_events WHERE id=${e7}`));
        ok('replacing an uploaded photo deletes the old uploaded file', !fs.existsSync(REPO + firstUpload), firstUpload);

        // ---------- delete an older event: row gone, original photo kept (carousel uses slide5.jpg)
        const cm = evId('country-music');
        await S.goto(BASE + 'media.php?tab=events');
        await Promise.all([S.waitForNavigation(), S.$eval(`#e${cm} form`, f => f.querySelector('button').click())]);
        ok('older event deleted, its original photo kept', sql(`SELECT COUNT(*) FROM site_events WHERE id=${cm}`) === '0' && fs.existsSync(REPO + 'images/slide5.jpg') && (await alertOf(S)).endsWith('was removed.'), await alertOf(S));
        await V.goto(BASE + 'past_events.php');
        ok('…and it is gone from the public page', !(await V.$('#country-music')));
        await V.goto(BASE + 'index.php');
        ok('…and the homepage moves up the next event', (await V.$$eval('.events-grid a.event-card', as => as.map(a => a.getAttribute('href')))).includes('past_events.php#event-9'));
        // reloading never brings a deleted older event back
        await V.goto(BASE + 'past_events.php');
        ok('deleted older events are not imported again', sql("SELECT COUNT(*) FROM site_events WHERE anchor='country-music'") === '0');

        // ---------- delete an event with an uploaded photo: file deleted too
        const upImg = sql(`SELECT image FROM site_events WHERE id=${e7}`);
        await S.goto(BASE + 'media.php?tab=events');
        await Promise.all([S.waitForNavigation(), S.$eval(`#e${e7} form`, f => f.querySelector('button').click())]);
        ok('event with an uploaded photo: photo file deleted with it', !fs.existsSync(REPO + upImg) && (await alertOf(S)).includes('photo file deleted'), await alertOf(S));

        // ---------- add a new event with tiles, then edit it
        await S.goto(BASE + 'media.php?tab=events');
        await setVal(S, '#eventForm input[name=title]', 'Probe Event');
        await setVal(S, '#eventForm input[name=event_date]', '2026-01-15');
        await setVal(S, '#eventForm textarea[name=stats]', '99 | Probes');
        await (await S.$('#eventForm input[name=photo]')).uploadFile(path.join(IMG, 'event.png'));
        await S.waitForSelector('.crop-modal:not([hidden]) .cropper-container'); await new Promise(r => setTimeout(r, 400));
        await S.click('[data-act="use"]');
        await S.waitForFunction(() => document.querySelector('#eventForm .check-msg').textContent.startsWith('Ready'), { timeout: 20000 });
        await submitForm(S);
        const pid = sql("SELECT id FROM site_events WHERE title='Probe Event'");
        created.push(sql(`SELECT image FROM site_events WHERE id=${pid}`));
        ok('new event added with organizer default and tiles', sql(`SELECT CONCAT(IFNULL(organizer,'-'),'|',stats) FROM site_events WHERE id=${pid}`) === 'By BUCuC|99 | Probes');
        await V.goto(BASE + 'past_events.php');
        ok('new event uses ev-{id} on the public page', !!(await V.$(`#ev-${pid}`)));

        // ---------- permissions
        const MI = await as('miap'), SE = await as('sec');
        await MI.goto(BASE + `media.php?tab=events&edit=${pid}`);
        ok('MIAP (upload_events) can edit events', !!(await MI.$('#eventForm input[name=action][value=event_update]')));
        const forged = await SE.evaluate(async id => {
            const fd = new FormData(); fd.append('action', 'event_update'); fd.append('id', id); fd.append('title', 'Hacked'); fd.append('event_date', '2026-01-01');
            fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            return (await fetch('media.php', { method: 'POST', body: fd })).url;
        }, pid);
        ok('Secretary Admin cannot edit events (forged request)', sql(`SELECT title FROM site_events WHERE id=${pid}`) === 'Probe Event' && forged.includes('permission'), forged);

        for (const [k, p] of Object.entries({ S, V, MI, SE })) if (p.errors.length) ok(`no JS errors (${k})`, false, p.errors.join(' | '));
    } catch (e) {
        ok('HARNESS', false, e.stack);
    } finally {
        for (const f of created) if (f && f.startsWith('images/events/')) try { fs.unlinkSync(REPO + f); } catch (e) { }
        await browser.close();
    }
    console.log(results.join('\n'));
    const fails = results.filter(x => x.startsWith('FAIL')).length;
    console.log(`\n${results.length - fails}/${results.length} passed`);
})();
