#!/usr/bin/env node
/**
 * Runs the browser test suites against a throwaway copy of the site.
 *
 *   node tests/run.js                 every suite
 *   node tests/run.js roles events    only these
 *   node tests/run.js --list          list the suites
 *   node tests/run.js --serve         local preview: test database + site kept running (add --fresh to reset the data)
 *
 * What it does (all local, nothing real is touched):
 *   - starts its own MariaDB (XAMPP's binaries) on port 3307 with its data folder in tests/runtime/db;
 *     it refuses to use any other server, and never touches XAMPP's MySQL on 3306 or the live database
 *   - starts the site on 127.0.0.1:8099 with tests/test_prepend.php (test database, e-mail written to a
 *     file instead of sent, fake Google Sheet / Apps Script / YouTube on 127.0.0.1:8098)
 *   - before each suite: fresh database `bucuc_test` from backend/Database/schema.sql + tests/seed/seed.php
 *     (made-up data) + five test admins; after each suite: members.json restored, test uploads removed
 *   - prints a summary; exit code 1 if anything failed. Logs: tests/runtime/logs/<suite>.txt
 * First time: cd tests && npm install   (puppeteer-core; uses the installed Google Chrome)
 */
const T = require('./config');
const fs = require('fs');
const path = require('path');
const net = require('net');
const { spawn, spawnSync, execFileSync } = require('child_process');

const SUITES = {
    auth: 'Admin login, CSRF, lockout, password reset, sessions, activity log',
    roles: 'Five roles, permissions, Website Media uploads, lockdown',
    run: 'Homepage, Apply form, shortlist / accept workflow, exports',
    apply: 'Apply form token (expired session, admin login in another tab)',
    fixes: 'Tenures, member levels, promotions, image size rules',
    priorities: 'Lockdown for visitors and admins, homepage stats, notices',
    followup3: 'Sender accounts, Gmail + G-Suite, manual homepage stats',
    theme: 'Site colours: palette, preview, history, reset',
    audit2: 'Audit fixes: parallel notice batches, hidden Super Admin, forms, Excel, logout',
    crop: 'Built-in crop and resize',
    gallery: 'Performance gallery (fake YouTube)',
    events: 'Past events: editing and deleting, imported stories',
    attendance: 'Attendance: sessions, checklist, QR check-in, reports',
    handover: 'Handover notes: sections, versions, locking, reminders',
    ads: 'Sponsor banners (Super Admin only)',
};
const ADMINS = [
    ['Super Tester', 'super@test.local', 'superpass123', 'super_admin'],
    ['Admin Tester', 'admin@test.local', 'adminpass123', 'hr_admin'],
    ['Panel Tester', 'panel@test.local', 'panelpass123', 'panel_admin'],
    ['Miap Tester', 'miap@test.local', 'miappass123', 'miap_admin'],
    ['Sec Tester', 'sec@test.local', 'secpass1234', 'secretary_admin'],
];
const PHP_EXT = ['-d', 'extension=zip', '-d', 'extension=gd'];
const DATADIR = path.join(T.RUNTIME, 'db');
const LOGS = path.join(T.RUNTIME, 'logs');
const children = [];
let startedDb = false;

const say = m => console.log(m);
const die = m => { console.error('\n✗ ' + m); cleanup(); process.exit(2); };
const sleep = ms => new Promise(r => setTimeout(r, ms));
const mysql = (args, input) => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, '--default-character-set=utf8mb4', ...args], { input, stdio: ['pipe', 'pipe', 'pipe'] }).toString().replace(/\r/g, '').trim();
const portOpen = port => new Promise(res => { const s = net.connect(port, '127.0.0.1'); s.once('connect', () => { s.destroy(); res(true); }); s.once('error', () => res(false)); });
const norm = p => path.resolve(p).replace(/\\/g, '/').replace(/\/$/, '').toLowerCase();

function preflight() {
    for (const [what, p] of [['PHP', T.PHP], ['mysql', T.MYSQL], ['mysqld', T.MYSQLD], ['mysql_install_db', T.MYSQL_INSTALL_DB], ['Chrome', T.CHROME]]) {
        if (!fs.existsSync(p)) die(`${what} not found at ${p}. Set ${what === 'Chrome' ? 'CHROME_PATH' : 'XAMPP_DIR'} (see tests/config.js).`);
    }
    try { require.resolve('puppeteer-core', { paths: [T.TESTS] }); } catch (e) { die('puppeteer-core is missing: run  cd tests && npm install'); }
    for (const d of [T.RUNTIME, LOGS, T.SESSIONS, T.CROPIMG]) fs.mkdirSync(d, { recursive: true });
}

/** Our own MariaDB: created once in tests/runtime/db, started on 3307, and checked to really be ours. */
async function startDb() {
    if (await portOpen(T.DB_PORT)) {
        let dir = '';
        try { dir = mysql(['-N', '-e', 'SELECT @@datadir']); } catch (e) { }
        if (norm(dir) !== norm(DATADIR)) die(`Port ${T.DB_PORT} is used by another database server (${dir || 'unknown'}). Stop it first; the tests only use their own server.`);
        say(`• test database already running (${DATADIR})`);
        return;
    }
    if (!fs.existsSync(path.join(DATADIR, 'mysql'))) {
        say('• creating the test database folder (first run)…');
        execFileSync(T.MYSQL_INSTALL_DB, [`--datadir=${DATADIR}`, `--password=${T.DB_ROOT_PASSWORD}`, `--port=${T.DB_PORT}`], { stdio: 'ignore' });
    }
    const p = spawn(T.MYSQLD, [`--defaults-file=${path.join(DATADIR, 'my.ini')}`, `--port=${T.DB_PORT}`, '--bind-address=127.0.0.1', '--console'], { stdio: ['ignore', 'ignore', fs.openSync(path.join(LOGS, 'mysqld.txt'), 'w')] });
    children.push(p);
    startedDb = true;
    for (let i = 0; i < 60 && !(await portOpen(T.DB_PORT)); i++) await sleep(500);
    for (let i = 0; i < 20; i++) { try { mysql(['-e', 'SELECT 1']); break; } catch (e) { await sleep(500); } }
    if (norm(mysql(['-N', '-e', 'SELECT @@datadir'])) !== norm(DATADIR)) die('The database that answered is not the test database.');
    say(`• test database started on port ${T.DB_PORT}`);
}

async function startPhp(port, args, name) {
    if (await portOpen(port)) die(`Port ${port} is already in use (another site or test server?). Stop it first.`);
    const p = spawn(T.PHP, [...args, '-S', `127.0.0.1:${port}`], { cwd: T.ROOT, stdio: ['ignore', 'ignore', fs.openSync(path.join(LOGS, `${name}.txt`), 'w')] });
    children.push(p);
    for (let i = 0; i < 40 && !(await portOpen(port)); i++) await sleep(250);
    if (!(await portOpen(port))) die(`The ${name} server did not start (see ${path.join(LOGS, name + '.txt')}).`);
}

/** Fresh test database: schema.sql + made-up seed + five test admins. */
function resetDb() {
    mysql(['-e', `DROP DATABASE IF EXISTS ${T.DB_NAME}; CREATE DATABASE ${T.DB_NAME} CHARACTER SET utf8mb4`]);
    mysql([T.DB_NAME], fs.readFileSync(path.join(T.ROOT, 'backend', 'Database', 'schema.sql')));
    mysql([T.DB_NAME], execFileSync(T.PHP, [path.join(T.TESTS, 'seed', 'seed.php')]));
    for (const f of fs.readdirSync(path.join(T.ROOT, 'backend', 'logs'))) if (f.startsWith('.schema_v')) fs.unlinkSync(path.join(T.ROOT, 'backend', 'logs', f));
    for (const [name, email, pw, role] of ADMINS) {
        execFileSync(T.PHP, [...PHP_EXT, '-d', `auto_prepend_file=${T.PREPEND}`, path.join(T.ROOT, 'backend', 'scripts', 'create_admin.php'), name, email, pw, role], { stdio: 'ignore' });
    }
    for (const f of [T.CAPTURE, T.GAS_LOG]) try { fs.unlinkSync(f); } catch (e) { }
}

/** Files the suites may create: uploads under frontend/images and frontend/video. */
function listUploads() {
    const out = new Set();
    const walk = d => { if (!fs.existsSync(d)) return; for (const e of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, e.name); e.isDirectory() ? walk(p) : out.add(p); } };
    walk(path.join(T.ROOT, 'frontend', 'images'));
    walk(path.join(T.ROOT, 'frontend', 'video'));
    return out;
}

function cleanup() {
    for (const p of children.reverse()) {
        if (startedDb && p.spawnfile === T.MYSQLD) {
            try { execFileSync(path.join(path.dirname(T.MYSQL), 'mysqladmin.exe'), ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', `-p${T.DB_ROOT_PASSWORD}`, 'shutdown'], { stdio: 'ignore' }); } catch (e) { try { p.kill(); } catch (e2) { } }
        } else {
            try { p.kill(); } catch (e) { }
        }
    }
    children.length = 0;
    for (const f of fs.existsSync(path.join(T.ROOT, 'backend', 'logs')) ? fs.readdirSync(path.join(T.ROOT, 'backend', 'logs')) : []) {
        if (f.startsWith('.schema_v')) try { fs.unlinkSync(path.join(T.ROOT, 'backend', 'logs', f)); } catch (e) { }   // let the real site re-check its tables
    }
}

(async () => {
    const args = process.argv.slice(2);
    if (args.includes('--list')) { for (const [k, v] of Object.entries(SUITES)) say(`${k.padEnd(11)} ${v}`); return; }
    if (args.includes('--serve')) {
        // Local preview: the test database (made-up data) + the site, kept running until Ctrl+C
        preflight();
        process.on('SIGINT', () => { say('\nStopping…'); cleanup(); process.exit(0); });
        execFileSync(T.PHP, [...PHP_EXT, path.join(T.FIXTURES, 'make_images.php'), T.CROPIMG], { stdio: 'ignore' });
        await startDb();
        if (args.includes('--fresh') || !mysql(['-N', '-e', `SHOW DATABASES LIKE '${T.DB_NAME}'`])) resetDb();
        await startPhp(T.FIXTURE_PORT, ['-t', T.FIXTURES], 'fixtures');
        await startPhp(T.SITE_PORT, [...PHP_EXT, '-d', `auto_prepend_file=${T.PREPEND}`, '-d', `session.save_path=${T.SESSIONS}`,
            '-d', 'upload_max_filesize=16M', '-d', 'post_max_size=20M', '-d', `error_log=${path.join(LOGS, 'php_server.txt')}`, '-t', T.ROOT], 'site');
        say(`\nLocal preview running (made-up data, e-mail written to a file, nothing real touched):\n  site   ${T.BASE}index.php\n  admin  ${T.BASE}admin-login.php   super@test.local / superpass123\nPress Ctrl+C to stop.`);
        return;   // the servers keep the process alive
    }
    const chosen = args.filter(a => !a.startsWith('--'));
    const unknown = chosen.filter(s => !SUITES[s]);
    if (unknown.length) die(`Unknown suite(s): ${unknown.join(', ')}. Use --list.`);
    const suites = chosen.length ? chosen : Object.keys(SUITES);

    preflight();
    process.on('SIGINT', () => { say('\nStopping…'); cleanup(); process.exit(130); });
    const membersJson = path.join(T.ROOT, 'backend', 'Api', 'members.json');
    const membersBackup = fs.readFileSync(membersJson);
    const uploadsBefore = listUploads();
    execFileSync(T.PHP, [...PHP_EXT, path.join(T.FIXTURES, 'make_images.php'), T.CROPIMG], { stdio: 'ignore' });

    await startDb();
    await startPhp(T.FIXTURE_PORT, ['-t', T.FIXTURES], 'fixtures');
    await startPhp(T.SITE_PORT, [...PHP_EXT, '-d', `auto_prepend_file=${T.PREPEND}`, '-d', `session.save_path=${T.SESSIONS}`,
        '-d', 'upload_max_filesize=16M', '-d', 'post_max_size=20M', '-d', `error_log=${path.join(LOGS, 'php_server.txt')}`, '-t', T.ROOT], 'site');
    say(`• site on ${T.BASE}\n`);

    const results = [];
    for (const s of suites) {
        resetDb();
        const t0 = Date.now();
        process.stdout.write(`  ${s.padEnd(11)} … `);
        const r = spawnSync(process.execPath, [path.join(T.TESTS, 'e2e', `${s}.js`)], { cwd: T.TESTS, encoding: 'utf8', timeout: 15 * 60 * 1000, maxBuffer: 64 * 1024 * 1024 });
        const out = (r.stdout || '') + (r.stderr ? '\n--- stderr ---\n' + r.stderr : '');
        fs.writeFileSync(path.join(LOGS, `${s}.txt`), out);
        const m = out.match(/(\d+)\/(\d+) passed\s*$/m);
        const fails = out.split('\n').filter(l => /^FAIL\b/.test(l));
        const okRun = m && m[1] === m[2] && !fails.length;
        results.push({ s, pass: m ? +m[1] : 0, total: m ? +m[2] : 0, fails, ok: okRun, secs: Math.round((Date.now() - t0) / 1000) });
        say(okRun ? `✓ ${m[1]}/${m[2]} (${results.at(-1).secs}s)` : `✗ ${m ? m[1] + '/' + m[2] : 'did not finish'} (${results.at(-1).secs}s)`);
        for (const f of fails.slice(0, 8)) say('      ' + f.slice(0, 200));
        // put things back for the next suite
        fs.writeFileSync(membersJson, membersBackup);
        for (const f of listUploads()) if (!uploadsBefore.has(f)) try { fs.unlinkSync(f); } catch (e) { }
    }
    cleanup();

    const bad = results.filter(r => !r.ok);
    say(`\n${results.length - bad.length}/${results.length} suites passed · ${results.reduce((a, r) => a + r.pass, 0)}/${results.reduce((a, r) => a + r.total, 0)} checks · logs in ${LOGS}`);
    process.exit(bad.length ? 1 : 0);
})().catch(e => { console.error(e); cleanup(); process.exit(2); });
