// Apply form token: survives an expired session and an admin login/logout in another tab; forged / missing tokens refused.
const T = require('../config');   // paths, ports and tool locations (tests/config.js)
const puppeteer = require('puppeteer-core');
const { execFileSync } = require('child_process');
const sql = q => execFileSync(T.MYSQL, ['-h127.0.0.1', `-P${T.DB_PORT}`, '-uroot', '-prootpw', '-N', '-B', T.DB_NAME, '-e', q]).toString().replace(/\r/g, '').trim();
const B = 'http://127.0.0.1:8099/frontend/';
const out = [];
async function fill(p, id) {
  await p.bringToFront(); 
  const f = { 'signup-name': 'Repro Person', 'signup-id': id, 'signup-main-email': `r${id}@example.com`, 'signup-gsuite-email': `r${id}@g.example.edu`, 'signup-department': 'CSE', 'signup-phone': '01700000000', 'signup-facebook': 'facebook.com/repro' };
  for (const [k, v] of Object.entries(f)) await p.$eval('#' + k, (e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }, v);
  await p.$eval('#signup-dob', e => { e.value = '2004-01-01'; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.select('#signup-semester', '2nd'); await p.select('#signup-gender', 'Other');
  await p.select('#signup-first-dept', 'HR'); await p.select('#signup-second-dept', 'PR');
}
const submit = async p => { await p.bringToFront(); return Promise.all([p.waitForNavigation({ timeout: 15000 }).catch(() => {}), p.click('#submitBtn')]); };
const saved = id => sql(`SELECT COUNT(*) FROM pending_applications WHERE university_id='${id}'`) === '1';
(async () => {
  const b = await puppeteer.launch({ executablePath: T.CHROME, headless: true, protocolTimeout: 30000 });
  sql("DELETE FROM pending_applications WHERE university_id LIKE '2999956%'; DELETE FROM signup_attempts");
  let p = await (await b.createBrowserContext()).newPage();
  await p.goto(B + 'index.php'); await fill(p, '29999561'); await submit(p);
  (x=>{out.push(x);})(['normal submit saves', saved('29999561')]);
  // session cookie deleted while the form is open (expired / cleaned-up session)
  p = await (await b.createBrowserContext()).newPage();
  await p.goto(B + 'index.php'); await fill(p, '29999562');
  await p.deleteCookie(...(await p.cookies()));
  await submit(p);
  (x=>{out.push(x);})(['submit after the session is gone still saves', saved('29999562')]);
  // admin logs in and out in another tab of the same browser while the form is open
  const ctx = await b.createBrowserContext();
  p = await ctx.newPage(); await p.goto(B + 'index.php'); await fill(p, '29999563');
  const a = await ctx.newPage(); await a.goto(B + 'admin-login.php');
  await a.type('#adminEmail', 'super@test.local'); await a.type('#adminPassword', 'superpass123');
  await Promise.all([a.waitForNavigation(), a.click('button[type=submit]')]);
  await a.goto(B + 'logout.php'); await Promise.all([a.waitForNavigation(), a.click('form[action="logout.php"] button')]); await a.close();
  await submit(p);
  (x=>{out.push(x);})(['submit after an admin login + logout in another tab still saves', saved('29999563')]);
  // forged / missing token
  p = await (await b.createBrowserContext()).newPage();
  await p.goto(B + 'index.php'); await fill(p, '29999564');
  await p.$eval('input[name=form_token]', e => e.value = '1792000000.' + 'a'.repeat(64)); await submit(p);
  const msg = await p.$eval('#applyBanner', e => e.textContent.trim()).catch(() => '');
  const kept = await p.$eval('#signup-name', e => e.value);
  (x=>{out.push(x);})(['forged token refused, answers kept', !saved('29999564') && msg.includes('open too long') && kept === 'Repro Person', msg]);
  await fill(p, '29999565'); console.error('btn', await p.$eval('#submitBtn', b => JSON.stringify({vis: b.offsetParent !== null, hidden: b.closest('[hidden]') ? b.closest('[hidden]').id : null, r: b.getBoundingClientRect().height}))); await p.$eval('#submitBtn', b => b.click()); await p.waitForNavigation({ timeout: 15000 }).catch(() => {});
  (x=>{out.push(x);})(['…and pressing Submit again on the refilled form saves', saved('29999565')]);
  p = await (await b.createBrowserContext()).newPage();
  await p.goto(B + 'index.php'); await fill(p, '29999566');
  await p.$eval('input[name=form_token]', e => e.remove()); await submit(p);
  (x=>{out.push(x);})(['no token refused', !saved('29999566')]);
  for (const [n, ok, x] of out) console.log((ok ? 'PASS  ' : 'FAIL  ') + n + (x ? '  — ' + x : ''));
  console.log(`
${out.filter(x => x[1]).length}/${out.length} passed`);
  sql("DELETE FROM pending_applications WHERE university_id LIKE '2999956%' OR university_id='29999555'; DELETE FROM signup_attempts");
  await b.close();
})();
