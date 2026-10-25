# BUCuC website: to-do list

Things that couldn't be finished or checked on my local copy. Each item says **how to do it** and **how to test it**.
Tick the box (`[x]`) when done. New items get added at the bottom of the right section as work continues.

Last updated: 23 Oct 2026 (after Phase 6 security headers rollout)

---

## ⚡ Master Action Plan: What You Have To Do on the Live Server

Follow these 7 sequential stages when you are ready to deploy to Hostinger:

### Stage 1: Before Uploading (Local PC)
- [ ] **1.1. Review local commits**: 8 local commits are ready (`eb3252f` through `66763e9`). Run `git log -n 8 --oneline` to review. Do not push until you check git status.
- [ ] **1.2. Verify ignored secrets**: Ensure `backend/Database/config.local.php`, `backend/config/*.local.php`, `restore.sql`, and `Member_Applications_*.csv` are NOT staged (they are git-ignored).
- [ ] **1.3. Generate your backup encryption keypair** (instruction.md §21.3):
  ```bash
  # Run in terminal on your local PC (keeps private key on your computer ONLY):
  openssl req -x509 -nodes -newkey rsa:2048 -keyout backup_private.key -out backup_public.crt -days 1825 -subj "/CN=BUCuC Backup"
  ```
  Keep `backup_private.key` safe on your PC (never upload it to the server). You will upload `backup_public.crt` only.

### Stage 2: Uploading to Hostinger (Files & Secrets)
- [ ] **2.1. Back up the live site first** (instruction.md §15):
  Export current live database via phpMyAdmin (`u106340611_bucuc`) and download ZIP of `frontend/images`, `frontend/video`, `backend/Api/members.json`.
- [ ] **2.2. Create private backups folder outside web root**:
  In Hostinger File Manager, create `/home/<user>/backups` (one level above `public_html`).
- [ ] **2.3. Upload site files**:
  Deploy the repository files to `public_html/`. Ensure `.htaccess` (root) and `.htaccess` in subdirectories are uploaded.
- [ ] **2.4. Upload secrets by hand** (never committed to git):
  1. `backend/Database/config.local.php` (live database credentials `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
  2. `backend/config/auth_email_config.local.php` (`AUTH_SMTP_PASSWORD` for club.bucuc@g.bracu.ac.bd).
  3. `backend/config/mail_accounts.local.php` (`HR_SMTP_PASSWORD` for hr.bucuc@gmail.com).
  4. `backend/config/backup_public.crt` (the public certificate generated in Step 1.3).
  5. `backend/config/backup.local.php`:
     ```php
     <?php
     define('BACKUP_DIR', '/home/<user>/backups');
     define('BACKUP_ENCRYPTION_CERT', __DIR__ . '/backup_public.crt');
     // Optional: Cloudflare R2 / Backblaze B2 S3 credentials
     // Optional: healthchecks.io heartbeat pings
     ```
  6. `backend/config/config.local.php` (optional: set retention threshold e.g. `define('UNREVIEWED_APPLICANT_RETENTION_MONTHS', 6);`).
- [ ] **2.5. Set folder permissions**:
  In Hostinger File Manager: directories `755`, PHP/asset files `644`. Ensure write permission on `backend/logs`, `backend/cache`, `frontend/images`, `frontend/video`.

### Stage 3: Database Migration & Admin Roles
- [ ] **3.0. CRITICAL: Back Up Live Database First**:
  Before running any migration, go to phpMyAdmin → select `u106340611_bucuc` → **Export** → **Quick** → **SQL** → Download backup. Never run migrations on an unbacked-up live database!
- [ ] **3.1. Run Database Upgrade**:
  In phpMyAdmin → select `u106340611_bucuc` → SQL tab → paste and run `backend/Database/upgrade.sql`. (Or `upgrade_roles.sql` with your Super Admin email set). Note: `site_ensure_schema()` in `backend/includes/site.php` is safe to run on any database; it automatically creates `failed_sheet_deletions` and drops legacy scaffolding tables.
- [ ] **3.2. Assign Admin Roles**:
  Log in as Super Admin (`https://<your-domain>/frontend/admin-login.php`) → Admin Management → assign roles (Panel, HR, MIAP, Secretary) to existing accounts.

### Stage 4: Automated Background Jobs (Cron on Hostinger)
- [ ] **4.1. Configure Unified Cron Dispatcher**:
  In Hostinger **hPanel → Advanced → Cron Jobs**, add a cron job running every minute (or every 5 minutes):
  ```bash
  * * * * * php /home/<user>/public_html/backend/scripts/cron.php
  ```
  This single dispatcher automatically manages:
  - Email notices processing (every minute)
  - Daily database and user uploads backup (daily at 03:00)
  - Daily backup freshness and size health check (daily at 04:00)
  - Unreviewed applicant retention purge (daily at 04:30)
  - Handover notes reminders (daily at 09:00)
  - Google Sheet deletion retries (every run, retries any failed deletions recorded in `failed_sheet_deletions`)
  - Error log scanning and stealth alerting

### Stage 5: External Services (Cloudflare, Google Sheets, UptimeRobot)
- [ ] **5.1. Cloudflare Cache Rules** (instruction.md §19.2):
  In your Cloudflare dashboard for `bucuc.org`, create the 3 rules:
  1. *Bypass Dynamic & Admin*: URI Path matches `/backend/*`, `/frontend/admin*`, `/frontend/dashboard*`, etc. → Cache bypass.
  2. *Cache Static Assets*: Extensions `.css, .js, .woff2, .webp, .jpg, .png, .svg` → Edge TTL 1 year.
  3. *Revalidate Public HTML*: Static HTML/homepage → Revalidate with origin.
- [ ] **5.2. Google Sheets Apps Script Deletion Webhook & Shared Secret** (instruction.md §23.6):
  To protect against unauthorized webhook tampering and ensure rejected applicants are deleted from the Google Sheet:
  1. Open your member Google Sheet → click **Extensions → Apps Script**.
  2. In the left sidebar, click the gear icon (**Project Settings**) → scroll to **Script Properties** → click **Add script property**.
  3. Set Property: `WEBHOOK_SECRET`, Value: `<your-random-secret-string>` (save properties).
  4. In `backend/config/config.local.php` on the server, add:
     ```php
     define('MEMBER_SHEET_SECRET', '<your-random-secret-string>');
     ```
     *(The committed default is empty string `''`; leaving it empty logs a warning on deletion and webhook calls).*
  5. In Apps Script, incrementally merge the secret check, `action === 'delete'`, and `action === 'update'` handlers into your existing `doPost(e)` (see instruction.md §23.6 for the merge snippet). Do NOT overwrite existing member-add logic.
  6. Deploy: **Deploy → Manage deployments → Edit (pencil icon) → Version: New version → Deploy**.
  7. Confirm `MEMBER_SHEET_GAS_URL` in `frontend/includes/member_board.php` or `backend/config/config.local.php` matches the Web App URL.
- [ ] **5.3. Uptime Monitoring** (instruction.md §22):
  In UptimeRobot (free tier), add monitor for `https://bucuc.org/frontend/index.php` (5 min interval, alert contact = Super Admin email).
- [ ] **5.4. Cloudflare Script Injections & CSP Compatibility Checks**:
  Cloudflare injects scripts into HTML pages on default settings. These can trigger CSP violations in the browser and in `backend/logs/csp_violations.log`:
  1. **Rocket Loader**:
     - *Issue:* Rewrites `<script>` tags to `type="text/rocketscript"`, injects `rocket-loader.min.js`, and defers script execution. This mutates execution order, breaks Swiper / Cropper, and triggers `script-src` CSP violation reports.
     - *How to disable:* In Cloudflare dashboard → **Speed → Optimization → Content Optimization → Rocket Loader** → toggle **Off**.
  2. **Cloudflare Web Analytics / Insights**:
     - *Issue:* Injects `https://static.cloudflareinsights.com/beacon.min.js` before `</body>`, triggering `script-src` violations unless whitelisted.
     - *How to check / disable:* In Cloudflare dashboard → **Analytics & Logs → Web Analytics** → disable automatic injection for this zone, or if you want it, add `https://static.cloudflareinsights.com` to `script-src` and `connect-src` in `site_send_csp_header()`.
  3. **Email Address Obfuscation**:
     - *Issue:* Replaces mailto: links and email addresses in HTML with `/cdn-cgi/scripts/.../email-decode.min.js`.
     - *How to disable:* In Cloudflare dashboard → **Scrape Shield → Email Address Obfuscation** → toggle **Off** (BUCuC emails are already server-rendered and protected).
  4. *Live Verification:* Open `https://bucuc.org/frontend/index.php` in Chrome with DevTools Console open. Verify zero `[Report Only]` CSP violation messages appear. Check `backend/logs/csp_violations.log` to confirm no reports from `cloudflare` or `/cdn-cgi/` exist.

### Stage 6: Live Smoke Tests & Scratch Database Restore
- [ ] **6.1. Test Manual Backup & Encryption**:
  Run in Hostinger SSH: `php backend/scripts/backup.php`
  Verify encrypted archives `backup_YYYY-MM-DD.sql.gz.enc` and `files_YYYY-MM-DD.zip.enc` exist in `/home/<user>/backups`.
- [ ] **6.2. Test Decryption & Scratch Database Restore** (instruction.md §21.4):
  Download backup to your PC, decrypt using `php backend/scripts/decrypt.php backup_*.sql.gz.enc backup_private.key`, import into a local scratch database `bucuc_scratch`, and confirm row counts match. Spot-check restored images open.
- [ ] **6.3. Test Applicant Rejection & Zero Personal Data**:
  Submit a test application on `index.php`. In Admin → Applications, reject the applicant (confirm dialog appears). Verify database has 0 occurrences of the test applicant's name, ID, phone, or email. Check that anonymous rejection count incremented.
- [ ] **6.4. Test Password Reset & Email Notices**:
  Test Admin Forgot Password (sent from `club.bucuc@g.bracu.ac.bd`) and test sending a sample notice (B1 and B9 below).

### Stage 7: Hardening (Enforce CSP & Enable HSTS)
- [ ] **7.1. Check CSP Report-Only in Browser Console**:
  Open DevTools Console while browsing the live site. Verify zero violation warnings occur across YouTube embeds, crop tool, theme preview, and Chart.js.
- [ ] **7.2. Confirm Universal HTTPS**:
  Verify every public and admin page loads securely with valid SSL and no mixed-content warnings.
- [ ] **7.3. Enforce CSP & Enable HSTS** (instruction.md §25):
  In `backend/Database/db.php` and `.htaccess`, switch `Content-Security-Policy-Report-Only` to `Content-Security-Policy`, and add `Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"`.

---

## P. Production readiness (phases)

### [x] P1. Tests in the repo
Done 20 Oct 2026: the browser suites live in `tests/` (instruction.md §18). Run `cd tests && npm install` once, then `node tests/run.js` before every upload: it uses its own throwaway database and made-up data, never the real one.
- **Do:** commit and push the new `tests/` folder (its `runtime/` and `node_modules/` are git-ignored).
- **Test:** `node tests/run.js` ends with "15/15 suites passed".

### [ ] Q1. UI polish pass: upload and check (21 Oct 2026)
Every page was checked as every role at phone, tablet and desktop width (instruction.md §13.1m). The changed code and the **compressed photos** are only on this PC.
- **Do:** upload the changed files (see the commit), including the whole `frontend/images` folder, `favicon.ico` (site root), `frontend/404.php`, `frontend/site.webmanifest`, `frontend/css/admin-ui.css`, `frontend/js/ui.js`, `backend/includes/admin_menu.php`, `tests/.htaccess` and the root `.htaccess`.
- **Test:** on your phone open the homepage, the panel and secretary photos and an admin page: photos look sharp and upright, the ☰ Menu opens and closes. Open `https://<your domain>/frontend/nothing-here` → the BUCuC "Page Not Found" page with "Back to the homepage". Open `https://<your domain>/tests/run.js` → refused (403/404). The browser tab shows the BUCuC logo.

### [ ] Q2. Keep or delete the photo originals
The 129 photos were shrunk from 143 MB to 26 MB (same names, so nothing else changed). The untouched originals are in `E:\BUCuC_image_originals` (outside the website).
- **Do:** once Q1 looks right, keep that folder somewhere safe (e.g. Google Drive) or delete it. To undo one photo, copy it back from there.
- **Test:** nothing to test.

### [ ] Q3. Three photos are not used anywhere
`frontend/images/slide1.png` (1.8 MB), `frontend/images/1st.JPG` and `frontend/images/bucuc.jpg` are not used by any page or database row.
- **Do:** tell me to delete them, or keep them.
- **Test:** after deleting, `node tests/run.js uiqa` still passes (it checks for broken images).

### [ ] Q4. Click the Facebook and LinkedIn links once yourself
Automatic link checkers are blocked by Facebook (answer 400) and LinkedIn (answer 999), so these weren't verified: the footer Facebook page and BUCuC Archive links, and the advisor's LinkedIn link. Every other link and image works.
- **Do / Test:** on the live homepage click each one; each must open the right page.

### [x] Q5. Privacy and Terms links in the footer
Done 23 Oct 2026: Placeholder Privacy and Terms links have been completely removed from `frontend/footer.php` and `frontend/index.php`.

### [x] P2. Caching and speed (21 Oct 2026)
Done 21 Oct 2026: self-hosted fonts and vendor assets, never-cache headers on dynamic/admin pages, immediate lockdown safety, server-side homepage cache with admin auto-invalidation, ad view beacon with batched writes, gzip compression and expiry headers in `.htaccess`, and step-by-step Cloudflare rules in `instruction.md` §19.
- **Do:** in your Cloudflare dashboard, add the 3 Cache Rules documented in `instruction.md` §19.2 (Bypass Admin/Forms, Cache Static Assets 1 year, Revalidate Public HTML).
- **Test:** all 17 test suites pass; `backend/cache/` contains `home_data.json` after loading the homepage; ad views count properly via beacon and batch writes; `.htaccess` serves gzip and 1-year expires headers for static files.

### [x] P3. Image compression and WebP with fallback (22 Oct 2026)
Done 22 Oct 2026: 214 .webp companion images generated alongside fallbacks in `frontend/images/` without recompressing existing JPEGs/PNGs (same base names, zero DB schema changes). Zero images in the entire repository exceed 1 MB (`2nd.JPG` produced `2nd.webp` at 169 KB). All images in `frontend/images/` scanned and verified 100% free of GPS/EXIF metadata tags. Full `<picture>` tag and `image-set()` rollout across the entire site (homepage brand, hero disc, about logo, carousel moments, advisors, presidential panel, secretaries swiper, footer brand, past events, ad banners, admin previews, and CSS backgrounds). No Accept-based URL rewriting or `Vary: Accept` required: modern browsers fetch `.webp` directly and legacy browsers fetch the JPEG/PNG fallback via native `<picture>` source selection. Compatible with Cloudflare Free tier out of the box. Tested by new `webp` test suite (65+ checks green).
- **Do:**
  1. Upload all generated `.webp` files in `frontend/images/` to Hostinger alongside existing files (same folders, same base names).
  2. Upload `.htaccess` (root) and `frontend/images/.htaccess` (static headers, no Accept rewrites).
  3. Upload updated PHP files (`backend/includes/site.php`, `backend/includes/ads.php`, `frontend/index.php`, `frontend/past_events.php`, `frontend/media.php`, `frontend/sponsors.php`, etc.).
- **Test (Live checks on Hostinger via curl / browser):**
  1. **Direct WebP request (real path, returns WebP without Vary: Accept):**
     ```bash
     curl -sI "https://<your-domain>/frontend/images/2nd.webp"
     ```
     * **Expected HTTP Status:** `200 OK`
     * **Expected Content-Type:** `image/webp`
     * **Expected Vary Header:** No `Vary: Accept`
  2. **Direct fallback request (real path, returns JPEG without Vary: Accept):**
     ```bash
     curl -sI -H "Accept: image/webp,image/*,*/*" "https://<your-domain>/frontend/images/2nd.JPG"
     ```
     * **Expected HTTP Status:** `200 OK`
     * **Expected Content-Type:** `image/jpeg` (always JPEG, even when client sends `Accept: image/webp`)
     * **Expected Vary Header:** No `Vary: Accept`
  3. **Uppercase extension file (`2nd.JPG` vs `2nd.webp`):**
     ```bash
     curl -sI "https://<your-domain>/frontend/images/2nd.JPG"
     ```
     * **Expected Content-Type:** `image/jpeg`
     ```bash
     curl -sI "https://<your-domain>/frontend/images/2nd.webp"
     ```
     * **Expected Content-Type:** `image/webp`
  4. **Transparent PNG logo (PNG fallback vs WebP companion):**
     ```bash
     curl -sI "https://<your-domain>/frontend/images/logo.png"
     ```
     * **Expected Content-Type:** `image/png`
     ```bash
     curl -sI "https://<your-domain>/frontend/images/logo.webp"
     ```
     * **Expected Content-Type:** `image/webp`
  5. **Upload a photo in Website Media & verify stripped EXIF:**
     Log in to Admin → Website Media → upload a photo taken from a smartphone with GPS enabled. Download the uploaded file and inspect properties: GPS coordinates (Latitude/Longitude) are completely absent.

### [x] P4. Backups, scratch database restore, error alerts, notice cron and uptime monitoring (23 Oct 2026)
Done 23 Oct 2026 (including all Phase 4 follow-ups pass 2):
- Automated daily backup script (`backend/scripts/backup.php` + `backend/includes/backup.php`) streaming database dump to `.sql.gz` and archiving user files (`frontend/images/`, `frontend/video/`, `backend/Api/members.json`, `backend/Database/schema_marker.txt`) to `.zip`, with 14-day retention pruning. `recovery.token` is deliberately excluded.
- Measured physical OS peak memory (`PeakWorkingSet64`): OpenSSL CMS measured `321.53 MB` (Encrypt) / `342.12 MB` (Decrypt) on a 150 MB archive. Switched to Pure-PHP Hybrid Streaming Envelope (`BUCENC01`: RSA-OAEP session key + 64 KB AES-256-CTR chunks + HMAC-SHA256 trailer) which dropped whole-process physical OS memory to `20.14 MB` (Encrypt) / `20.18 MB` (Decrypt) — a flat 16x memory reduction regardless of file size.
- Pre-transfer asymmetric encryption via certificate (server holds public cert only; user keeps passphrase-protected private key locally; also supports `age` and `gpg` CLI). Hand-rolled passphrase mode deprecated/unsupported. Decrypt locally with `php backend/scripts/decrypt.php backup_*.sql.gz.enc backup_private.key` (or OpenSSL CMS for legacy archives).
- Fail-closed guarantee: if encryption is configured but fails, partial `.enc` output is deleted, unencrypted dump is deleted, Super Admin alert is sent, and unencrypted copies are never kept on server or uploaded offsite.
- Security warnings: Super Admin is alerted if `BACKUP_DIR` is inside the public web root on live server, or if live backups run without an encryption certificate.
- Pure-PHP S3-compatible offsite upload (Cloudflare R2 / Backblaze B2 / AWS S3) using AWS SigV4 PUT over HTTPS via cURL streaming. Authenticated SigV4 HEAD request verifies remote object size matches local archive byte-for-byte; alerts on mismatch. Refuses unencrypted uploads offsite. Slow connection timeout handling (at 2 Mbit/s) alerts Super Admin and retries on next cron run. Tested against local mock server.
- Decoupled backup process: `backup.php` runs as an independent process under its own advisory lock (`bucuc_backup_lock`). When triggered by the dispatcher, it spawns asynchronously so the dispatcher returns immediately and notice sending cron runs uninterrupted every minute.
- Persistent cron state: schedule tracking migrated from JSON file into MariaDB table `cron_schedule` (`job_key`, `last_run_at`, `last_status`, `last_output`).
- Daily backup health check (`backend/scripts/check_backup.php`): checks latest backup exists (including `.enc` encrypted files), is <= 26 hours old, and >= 1 KB. Alerts Super Admin if missing, stale, or tiny.
- Master cron dispatcher (`backend/scripts/cron.php`) running every 1–5 minutes, managing notices, daily backups, backup checks, error scans, and handover reminders under advisory locking (`GET_LOCK`) and per-job heartbeats.
- Multi-layer web defense: `if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }` in all scripts, plus `.htaccess` blocking direct HTTP access to `backend/backups/`, `backend/cache/`, and `backend/logs/`.
- Tested and verified scratch database restore across all 34 tables with 100% row match, and verified that unpacked JPEG, PNG, and WebP images open cleanly.
- Stealth error alert system (`site_alert_super_admin()`) emailing Super Admin only (max 1/hr, applicant data sanitized). Alerts Super Admin immediately if backup script fails.
- Optional heartbeat pings (healthchecks.io style) on successful backup, notices, and cron dispatcher (`BACKUP_HEARTBEAT_URL`, `NOTICE_CRON_HEARTBEAT_URL`, `CRON_DISPATCHER_HEARTBEAT_URL`), pinging `/fail` on error.
- Background notice sending cron (`backend/scripts/send_notices.php`, batch size 25) with MySQL advisory lock (`GET_LOCK`) coordinated with the web progress page (`frontend/notices.php?action=batch`), atomic batch claim (`batch_token`).
- Unified email accounting: every email (welcome/congratulations, password reset, error alerts, handover reminders, notices) is recorded in `mail_log` and counted in `mail_sent_today($account)` against daily limits.
- 76 automated checks across `backup` (61/61) and `notices_cron` (15/15) test suites.
- **Do:**
  1. On Hostinger, create a private backups folder outside `public_html` (e.g. `/home/<user>/backups`).
  2. Create `backend/config/backup.local.php` (never committed to git):
     ```php
     <?php
     define('BACKUP_DIR', '/home/<user>/backups');
     // Pre-transfer encryption (public cert on server; private key kept locally):
     define('BACKUP_ENCRYPTION_CERT', __DIR__ . '/backup_public.crt');

     // Optional: Pure-PHP S3 offsite backup (Cloudflare R2 or Backblaze B2):
     // define('BACKUP_OFFSITE_METHOD', 's3');
     // define('BACKUP_S3_ENDPOINT', 'https://<account_id>.r2.cloudflarestorage.com');
     // define('BACKUP_S3_BUCKET', 'bucuc-backups');
     // define('BACKUP_S3_KEY_ID', 'your_access_key');
     // define('BACKUP_S3_SECRET_KEY', 'your_secret_key');

     // Optional: heartbeat monitoring (e.g. healthchecks.io):
     // define('BACKUP_HEARTBEAT_URL', 'https://hc-ping.com/your-uuid-here');
     // define('NOTICE_CRON_HEARTBEAT_URL', 'https://hc-ping.com/your-notice-uuid-here');
     // define('CRON_DISPATCHER_HEARTBEAT_URL', 'https://hc-ping.com/your-cron-uuid-here');
     ```
  3. In Hostinger **hPanel → Advanced → Cron Jobs**, configure the single unified dispatcher:
     - `* * * * * php /home/<user>/public_html/backend/scripts/cron.php` (or every 5 mins `*/5 * * * *`)
  4. Follow `instruction.md` §22 to set up your free UptimeRobot monitor for `https://<your-domain>/frontend/index.php`.
- **Test:**
  1. **Run manual backup on server:**
     ```bash
     php backend/scripts/backup.php
     ```
     Verify `backup_YYYY-MM-DD.sql.gz.enc` (or `.sql.gz`) and `files_YYYY-MM-DD.zip.enc` exist in your backup folder.
  2. **Verify scratch database restore (Rule 4):**
     Create empty database `bucuc_scratch`, decrypt and decompress `backup_YYYY-MM-DD.sql.gz.enc`, import into `bucuc_scratch`, and verify table counts match production. Extract `files_YYYY-MM-DD.zip` and verify images/videos open.
  3. **Verify daily health check:**
     ```bash
     php backend/scripts/check_backup.php
     ```
     Verify it reports `healthy: true` with exit code 0.
  4. **Verify HTTP blocking (Live checks on Hostinger via curl):**
     *(Note: Local test runner validates router & PHP CLI guard `PHP_SAPI !== 'cli'`. These curl checks verify live Apache/LiteSpeed `.htaccess` enforcement on Hostinger):*
     ```bash
     curl -i -s "https://<your-domain>/backend/backups/"
     curl -i -s "https://<your-domain>/backend/cache/"
     curl -i -s "https://<your-domain>/backend/logs/"
     curl -i -s "https://<your-domain>/backend/scripts/backup.php"
     curl -i -s "https://<your-domain>/backend/scripts/cron.php"
     ```
     * **Expected Status Code for Directories:** `HTTP 403 Forbidden`
     * **Expected Status Code for Scripts:** `HTTP 403 Forbidden` (via `.htaccess`) or `HTTP 404 Not Found` (via PHP CLI guard)
  5. **Run test suites:**
     `node tests/run.js backup notices_cron` passes 76/76 checks.

### [x] P5. Privacy: Applicant personal data deletion on rejection and tracking (23 Oct 2026)
Done 23 Oct 2026 (including Phase 5 follow-ups):
- Immediate atomic deletion of applicant personal data on rejection inside a single database transaction (`applicant_reject_and_delete()`).
- Deletes/scrubs name, student ID, phone, email, date of birth, answers, and interview bookings across all tables (`pending_applications`, `shortlisted_members`, `attendance_*`, `interview_slots`, `interview_bookings`, `notice_recipients`).
- Rejection automatically frees the applicant's interview seat (`interview_slots.booked_count` decremented, `interview_bookings` deleted).
- Rejection removes queued, unsent notices from `notice_recipients` and recalculates notice totals.
- Rejection scrubs physical log files (`email.log`, `cleared_applications.log`, `cleared_shortlisted.log`, `google_sheets.log`).
- Verified that `backend/Api/members.json` never holds applicant data, and Google Sheets sync is triggered for deletion.
- Anonymizes `mail_log` by setting `recipient = NULL` to retain 24-hour rate limiting counts without storing emails.
- Anonymizes `admin_activity_log` to `"An applicant was rejected from the <source> list"` with `target = NULL` and sanitizes historical references.
- Mandatory rejection confirmation dialog in admin UI: `"This permanently deletes this applicant's data and cannot be undone."` Cancelling deletes nothing.
- Minimal anonymous tracking record (`application_tracking`) storing only `tracking_token`, status (`'not_successful'` or `'expired_unreviewed'`), and semester. Zero personal data.
- Public status tracker page (`frontend/track.php`) serving polite rejection, review, and retention-concluded messages with never-cache headers (`Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0`).
- Anonymous rejection counts recorded by semester in `applicant_rejection_stats` and `application_events` (with `university_id = NULL`).
- Automated retention purge for unreviewed applications older than configurable months (`UNREVIEWED_APPLICANT_RETENTION_MONTHS` in `backend/config/retention.php`, default 6 months):
  - Protected with dedicated advisory lock `bucuc_retention_purge`.
  - Sends alert email to Super Admin on purge failure.
  - Logs execution counts only (no personal data) to `backend/logs/retention_purge.log`.
- Phase 4 Pass 2 additions complete:
  - BUCENC01 test matrix: sizes 0, 1, 65535, 65536, 65537, 150000; tampered header, tampered middle, tampered trailer, truncated, extra bytes, wrong key, wrong passphrase; HMAC verified before output accepted; header HMAC covered with separate derived key.
  - One-page BUCENC01 format specification documented in `instruction.md` §24.
  - Standalone `backend/scripts/decrypt.php` runnable with plain PHP CLI and zero dependencies (supporting both positional arguments and named `--in`, `--key`, `--passphrase`, `--out` flags).
  - `proc_open` -> `popen` -> `exec` background cascade in cron dispatcher.
  - Verified `cron_schedule` table never stores personal data or credentials.
  - Untested live step noted: MinIO live server upload (tested locally against authenticated SigV4 HTTP mock server on port 8098; verify against production MinIO/S3 if configured).
  - Atomic log scrubbing via temporary file and atomic rename, preserving original file permissions, with verified rollback and temporary file cleanup on failure.
  - Verified `mail_log` rows are preserved on rejection with only `recipient` set to NULL, keeping 24-hour rate limiting counts fully accurate.
  - Google Sheet deletion dispatch: sends `{"action":"delete","studentId":"<id>","secret":"..."}` to `MEMBER_SHEET_GAS_URL` with shared webhook secret. Verified against local fake endpoint (`tests/fixtures/gas.php`).
  - Scope cleanup executed: removed scaffolding for `track.php`, tracking tokens, `application_tracking`, `application_events`, `interview_slots`, and `interview_bookings`. Attendance remains standalone without event links.
- Tested with 35/35 automated checks in `tests/e2e/rejection.js` and 78/78 checks in `tests/e2e/backup.js`.
- **Do:**
  1. Define `MEMBER_SHEET_SECRET` in `backend/config/config.local.php` (never committed to git) with a strong random secret.
  2. In your Google Sheet Apps Script project:
     - Open **Project Settings** (gear icon) → **Script Properties** → add `WEBHOOK_SECRET` matching `MEMBER_SHEET_SECRET`.
     - Update `doPost(e)` using the snippet provided in `instruction.md` §23.6 to enforce authentication, scan all tabs, and delete applicant rows.
  3. If you wish to customize unreviewed applicant retention, define `UNREVIEWED_APPLICANT_RETENTION_MONTHS` in `backend/config/config.local.php` (defaults to 6 months).
  4. On the live database, run `backend/Database/upgrade.sql` or visit any admin page (automatic migration `.schema_v12` drops the 4 unused scaffolding tables and tracking columns).
  5. Verify rejection confirmation alert works when rejecting a test applicant in Admin → Applications.
- **Test:**
  1. In admin, reject a test applicant. Confirm dialog appears: "This permanently deletes this applicant's data and cannot be undone."
  2. Query database: verify zero rows contain the test applicant's name, student ID, phone, or email across every text/JSON column.
  3. Check Google Sheet (live test): verify applicant is deleted across all tabs, or check that unauthorized requests without the secret return 401.
  4. `node tests/run.js rejection` passes 35/35 checks.
  5. `node tests/run.js backup` passes 78/78 checks.

### [x] P6. Security headers and Content-Security-Policy (23 Oct 2026)
Done 23 Oct 2026:
- Implemented `Content-Security-Policy-Report-Only` centrally via PHP (`backend/Database/db.php`), completely removed from `.htaccess` to eliminate header conflicts.
- Hardened `script-src` to `'self'` only:
  - Removed `'unsafe-inline'` and `'unsafe-eval'`.
  - Refactored all inline `<script>` tags across public and admin pages into external `.js` scripts or `<script type="application/json">` data blocks.
  - Verified Chart.js, Swiper, and CropperJS execute cleanly without `eval()`.
- Added violation reporting endpoint `frontend/csp_report.php` logging sanitized reports (stripping query parameters, tokens, and personal data) to `backend/logs/csp_violations.log`.
- `style-src` retains `'self' 'unsafe-inline'` with verified technical justification:
  - Swiper dynamically applies hardware-accelerated CSS translation transforms (`transform: translate3d(...)`) to slide cards during swipe/autoplay.
  - CropperJS dynamically updates element styles (`top`, `left`, `width`, `height`, `transform`) as the user drags crop handles.
  - Theme palette preview dynamically injects CSS custom properties (`--c-primary: ...`) onto `:root`.
- Directives tailored and verified against all core dynamic components:
  - YouTube embeds (`frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com`) on `frontend/performances.php`
  - YouTube thumbnail cover images (`img-src https://i.ytimg.com`)
  - Built-in crop tool (`img-src 'self' data: blob:`) on `frontend/media.php` (CropperJS previews)
  - Framing protection (`frame-ancestors 'self'`)
  - Form action restriction (`form-action 'self'`)
  - Plugin execution disabled (`object-src 'none'`)
  - Dynamic `upgrade-insecure-requests` enabled for non-local production environments.
- Verified with 36/36 automated checks in `tests/e2e/csp.js` crawling all public pages and all 5 admin roles (`super_admin`, `hr_admin`, `panel_admin`, `miap_admin`, `secretary_admin`), producing 0 CSP violations.
- **Do:**
  1. Once deployed to Hostinger, inspect `backend/logs/csp_violations.log` periodically to check for any browser-reported issues during normal traffic.
  2. Verify HTTPS works across all public and admin routes and subdomains.
  3. Once universal HTTPS is confirmed, add HSTS (`Strict-Transport-Security`) in `.htaccess` as documented in `instruction.md` §25.4.
- **Test:**
  1. `curl -sI https://<your-domain>/frontend/index.php` returns `Content-Security-Policy-Report-Only`.
  2. `node tests/run.js csp` passes 36/36 checks.

### [x] P7. SEO, sitemap, robots.txt, Open Graph, and structured data (24 Oct 2026)
Done 24 Oct 2026:
- Kept standard `.php` URLs (no clean URL rewriting).
- `sitemap.xml`: Generates valid XML urlset containing only public pages (`/frontend/index.php`, `/frontend/past_events.php`, `/frontend/performances.php`). Strictly excludes admin, login, reset, recovery, check-in, sponsor admin, and hidden paths.
- `robots.txt`: Directs search crawlers to `Sitemap: https://bucuc.org/sitemap.xml` and explicitly allows public asset/page paths. Strictly does NOT list any admin or hidden paths (preventing path disclosure).
- Universal Noindex Protection: Emits both HTTP header `X-Robots-Tag: noindex, nofollow` and HTML backup `<meta name="robots" content="noindex, nofollow">` on all admin pages, login, password reset/recovery, session attendance check-in, sponsor management, and 404/503 responses. Automatically injected into HTML via central `ui_inject()` in `backend/includes/auth.php`.
- Open Graph & Twitter Cards: Full tags on all public pages (`og:type`, `og:site_name`, `og:title`, `og:description`, `og:url`, `og:image`, `og:image:secure_url`, `og:image:width`, `og:image:height`, `og:image:type`, `og:image:alt`, `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`).
- Share Images: Generated high-resolution 1200 × 630 px JPEG and PNG images (`og-share.jpg`, `og-events.jpg`, `og-performances.jpg` + `.png` counterparts) via PHP GD (`backend/scripts/generate_og_images.php`). Strictly never WebP.
- Central Domain Configuration: All canonical, OG, Twitter, and Schema.org URLs driven by single configuration constant `APP_URL` (`https://bucuc.org`).
- Canonical Links & Meta Descriptions: Unique meta description and canonical link on every public page.
- Organization Structured Data: Injected Schema.org `Organization` JSON-LD on homepage (`name`, `alternateName`, `url`, `logo`, `sameAs`). Zero Event structured data included (as calendar is excluded from scope).
- 404 Status: `site_not_found()` returns real HTTP 404 status code with noindex header and meta tag.
- Tested with 137/137 automated checks in `tests/e2e/seo.js`.
- **Do:**
  1. Once deployed to Hostinger, verify `https://<your-domain>/sitemap.xml` and `https://<your-domain>/robots.txt` load cleanly with correct Content-Types (`application/xml` and `text/plain`).
  2. Test social card rendering using Facebook Sharing Debugger (`https://developers.facebook.com/tools/debug/`), Twitter Card Validator, and LinkedIn Post Inspector.
  3. Validate homepage Organization structured data using Google Rich Results Test (`https://search.google.com/test/rich-results`).
  4. Submit `sitemap.xml` in Google Search Console under Sitemaps.
- **Test:**
  1. `curl -sI https://<your-domain>/frontend/admin-login.php` includes `x-robots-tag: noindex, nofollow`.
  2. `curl -sI https://<your-domain>/frontend/nonexistent_page.php` returns HTTP 404 and includes `x-robots-tag: noindex, nofollow`.
  3. `node tests/run.js seo` passes 137/137 checks.

### [ ] P8. Analytics and accessibility
One phase at a time; each ends with the full test run and a report.


## A. Go live (do these first, in this order)

### [ ] A1. Commit the work to git
Nothing from the October 2026 clean-up, redesign or security work is committed yet.
- **Do:** in VS Code → Source Control, check that `restore.sql`, `Member_Applications_*.csv`, `backend/Database/config.local.php` and `backend/config/*.local.php` are **not** in the list (they're git-ignored on purpose). `.gitignore` itself and `backend/logs/.htaccess`, `frontend/images/.htaccess`, `frontend/video/.htaccess`, `backend/vendor/.htaccess` **must** be committed. Then commit and push.
- **Test:** `git status` shows a clean tree; the secret and personal-data files still aren't listed; `git ls-files backend/logs` shows only `.htaccess`.

### [ ] A2. Back up the live database and uploads
- **Do:** follow `instruction.md` §15 (database export, `frontend/images` and `frontend/video` zips, `backend/Api/members.json`).
- **Test:** the downloaded `.sql` file is bigger than a few hundred KB and ends with `COMMIT;`; the zips open.

### [ ] A3. Upload the files to Hostinger
- **Do:** upload the whole repository to `public_html` (Git deploy or File Manager). Then upload **by hand** (git never includes them): `backend/Database/config.local.php` (database login, see A7), `backend/config/auth_email_config.local.php` (club App Password) and `backend/config/mail_accounts.local.php` (HR App Password). **Upload `config.local.php` first**: the new `config.php` has no password, so without it every page says "Site temporarily unavailable".
- **Test:** open the site; the homepage shows the new Aurora design with the video. Open `https://<site>/frontend/admin-login.php` and see the "Forgot password?" link.

### [ ] A4. Run `backend/Database/upgrade.sql` on the live database
- **Do:** after the backup (A2): phpMyAdmin → select `u106340611_bucuc` → **SQL** tab → paste the whole file → **Go**. Run it once only. A "Duplicate key name" error just means that step was already done; send me any other red error. Step 5 now **drops** the unused old tables `event_participants`, `performance_metrics`, `events`, `application_settings` and three old views.
- **Test:** the left column now lists `admin_activity_log`, `admin_login_attempts`, `admin_password_resets` and no longer lists `events` / `performance_metrics`. In the **Structure** tab of `members`, `date_of_birth` shows "Null: Yes". After opening any admin page once, `tenure_people` → Structure → Relation view shows the foreign key to `tenures`.

### [ ] A5. Check the venue table name
The code now uses `venuinfo` (all lowercase), which is what the live dump shows, so normally nothing needs doing.
- **Do:** in phpMyAdmin's left column, check the venue table is `venuinfo`. Only if you see `venuInfo` (capital I) with your venue rows, run the two commented lines of `upgrade.sql` step 4. If both exist, tell me before deleting anything.
- **Test:** admin → Set Venue → save a venue → it appears in the table below the form.

### [ ] A5b. Switch the live database to the new admin roles
The code now has 5 roles (Super, Panel, HR, MIAP, Secretary), one account each. The live `adminpanel` still has the old `main_admin` / `admin` roles.
- **Do:** right after uploading the code (A3), open `backend/Database/upgrade_roles.sql`, put the e-mail of the account that should be **Super Admin** between the quotes in `SET @super_email := '';`, then paste the whole file into phpMyAdmin → SQL → Go. (If you never ran `upgrade.sql`, run that instead: it includes the same step.)
- **Then:** log in as the Super Admin → Admin Management → give the other accounts their roles (Edit → Role), or create new ones. Old accounts can't log in until they have a role.
- **Test:** each person logs in and sees their own menu (Panel: Admin Management + Website Media + Dashboard Updates; HR: Admin Management + Members + Website Media; MIAP: Website Media; Secretary: Applications only). Nobody except the Super Admin sees the Super Admin in Admin Management or the Activity Log.

### [ ] A6. Confirm the live address (`APP_URL`)
Reset-password links are built from `APP_URL` in `backend/config/auth_email_config.php` (now `https://bucuc.org`).
- **Do:** if the site lives anywhere else (another domain, a sub-folder, `www.`), change `APP_URL` to that address (no trailing slash).
- **Test:** the link inside a reset e-mail (B1) opens the "New Password" page, not a 404.

### [ ] A7. Change the database password and the HR App Password (you do this)
The old database password and the old HR App Password are in the git history.
- **Do:**
  1. hPanel → Databases → MySQL Databases → change the password of user `u106340611_bucuc_2026`.
  2. On the server (File Manager), edit `backend/Database/config.local.php`: set `DB_PASS` to the new password (and `DB_USER` / `DB_NAME` if hPanel shows different ones). Locally, change the same file only if your local copy talks to that database.
  3. Google Account of hr.bucuc@gmail.com → Security → App passwords → revoke the **old** one (the one created before 19 Oct 2026). The one saved locally on 19 Oct is new; keep it unless you also revoke that. If you make another, put it in `backend/config/mail_accounts.local.php` → `HR_SMTP_PASSWORD` (16 letters, no spaces), locally and on the server.
  4. Then clean the history: `instruction.md` §17.
- **Test:** the homepage loads (no "Site temporarily unavailable"); B2 sends the congratulations e-mail; `git log --all -p | grep -c "<old password>"` prints 0 after the clean-up.

### [ ] A8. HTTPS and blocked files on the live server
The local test server ignores `.htaccess`, so these were not tested.
- **Test:**
  - `http://bucuc.org/` redirects to `https://bucuc.org/` (no redirect loop; if there is one, turn off hPanel's "Force HTTPS" or tell me).
  - `https://<site>/backend/Api/members.json` and `https://<site>/backend/includes/palette_map.json` show **403**; `https://<site>/backend/Api/get_members.php` still returns JSON.
  - Browser dev tools → Network → any page → response headers include `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, and no `X-Powered-By`.
  - Dev tools → Application → Cookies: `PHPSESSID` is **Secure**, **HttpOnly**, SameSite **Lax**.
  - Upload a file named `test.php` (any text) into `frontend/images/` with File Manager, open `https://<site>/frontend/images/test.php` → **403**. Delete the file.
  - `https://<site>/frontend/recover.php` → 404 (no token file).

### [ ] A9. Error log
- **Test:** File Manager → `backend/logs/php_errors.log` exists after some use. Open it now and then; send me anything that repeats.

---

## B. Test on the live site (couldn't be tested locally: real e-mail / Google)

### [ ] B1. Forgot password e-mail (sent from club.bucuc@g.bracu.ac.bd)
The App Password logged in to Gmail successfully on 12 Oct 2026, but no real e-mail has been sent yet.
- **Test:**
  1. Log out → Admin Login → **Forgot password?** → enter your own admin e-mail → Send.
  2. Within a minute an e-mail "Reset your BUCuC admin password" arrives from **BUCuC Admin <club.bucuc@g.bracu.ac.bd>** (check spam).
  3. Click **Choose a new password** → set a new password (8+ characters) → you land on the login page with "Your password was changed".
  4. A second e-mail "Your BUCuC admin password was changed" arrives.
  5. Click the first link again → "This reset link is invalid, already used, or expired".
  6. Log in with the new password.
- **If no e-mail:** open `backend/logs/auth_email.log` in File Manager. "NOT SENT … empty" = the `.local.php` file wasn't uploaded (A3). "FAILED … Username and Password not accepted" = App Password revoked or 2-Step Verification turned off.

### [ ] B2. Congratulations e-mail on Final Approve (sent from hr.bucuc@gmail.com)
The Gmail App Password in `backend/config/email_config.php` was rejected in the old logs; it probably needs a new one.
- **Do:** sign in as hr.bucuc@gmail.com → Google Account → Security → 2-Step Verification on → **App passwords** → create one → put the 16 characters (no spaces) in `SMTP_PASSWORD`.
- **Update 19 Oct 2026:** the new hr.bucuc@gmail.com App Password is now read from `backend/config/mail_accounts.local.php` (not from `email_config.php`), so once that file is uploaded the congratulations e-mail should work; just run the Test below.
- **Test:** apply on the homepage with a test student using **your own** e-mail → shortlist it → as Super Admin, **Final Approve**. A green message lists the recipients and the "Congratulations! Welcome to BRAC University Cultural Club" e-mail arrives, with the venue box if a venue is set. A yellow warning means e-mail or Sheets failed; see `backend/logs/email.log`. Delete the test member afterwards (Approved Members → Delete) and remove its row from the Google Sheet.

### [ ] B3. Accepted-members Google Sheet still updates
- **Test:** during B2, a new row with the test student's Name, ID, G-Suite, Position, Facebook, Phone, priorities appears at the bottom of the existing accepted-members sheet. If not, read `backend/logs/google_sheets.log`.

### [ ] B4. SB / ASB / GB pages (edit the real member sheet)
Never tested with writes: the buttons change the real Google Sheet.
- **Test:** Members → SB → the list loads. To test writing, pick a row you can put back: move it GB → ASB, refresh, confirm it moved in the sheet, move it back. The Activity Log shows "Board member moved".

### [ ] B5. Import members, then promote / demote on the live site
Member levels (GB / ASB / Secretary Panel) now live in the database. Tested locally against a fake Google sheet only.
- **Do:** as Super or Panel Admin open Members → GB and press **Import from Google Sheet** once. Then Dashboard Updates → lower the base member count by the number imported (the Total Members card adds the database members to that base number).
- **Test:** as HR, on the GB page pick a test person, choose "Promote to ASB": the person moves to the ASB page after reload, the Google sheet shows ASB within a few seconds, and the Activity Log shows "Promoted … GB → ASB". Move them back with "Demote to GB". On the SB page HR sees "No permission to move".
- **If the sheet doesn't change:** the move is still saved; a yellow note says why. The Apps Script (`MEMBER_SHEET_GAS_URL`) must accept `{action:'update', studentId, position}`.

### [ ] B6. Uploads on Hostinger
Tested locally only. Needs write access to `frontend/images`, `frontend/video`, `backend/Api/members.json`, `backend/logs`.
- **Test:** in Website Media → Panels & Tenures, open the current tenure and add one Presidential Panel photo (4:5, at most 800 × 1000) and one Secretary Panel photo (3:4, at most 750 × 1000); in Past Events add one (3:2, at most 1500 × 1000); in Hero Video upload one (MP4 H.264, 16:9, ≤ 24 s, ≤ 6 MB). Each shows on the homepage (event also at the top of Past Events). Then remove the test ones and press "Replace with the original video". In File Manager, check the removed files are gone from `frontend/images/tenures/…`, `frontend/images/events/` and `frontend/video/`.
- **If "Could not save…" appears:** in File Manager set those folders to 755 and `members.json` to 644 (owner = your Hostinger user). If a big video fails with "larger than the server allows", raise `upload_max_filesize` / `post_max_size` to 16M in hPanel → PHP Configuration.

### [ ] B8. Panel tenures on the live site
The first page load copies the 7 existing panel years into the new tenure tables.
- **Test:** the homepage panel select shows "Current Panel (2026-27)" first and the six older ones; photos load. In Website Media → Panels & Tenures, create next year's tenure, add its panel and secretaries, then **Make current**: the homepage now opens on it, the old one is under the select.
- **Careful:** the site keeps 12 tenures. Adding the 13th deletes the oldest one and its photo files for good (you're asked first, by name).

### [ ] B7. Lockdown on the live server
A lockdown now takes only the public website offline; the admin panel keeps working. The `past_events.html` → `past_events.php` redirect lives in `frontend/.htaccess`, which the local test server ignores.
- **Test:** as Super Admin → Site Lockdown → schedule 5 minutes ahead with a notice. Every admin sees the notice with a countdown on each page. When the time comes, a private window shows "Down for maintenance" with your notice on the homepage **and** on `…/frontend/past_events.html`; admins keep working (try another admin's login), and a signed-in admin opening the homepage sees the red "Admin preview" ribbon. Press "Lift lockdown now": the public site is back.
- **Also check the clock:** the scheduled time on the Lockdown page matches Dhaka time.

### [ ] B9. Email notices (real sending)
Tested locally with captured mail only. Super / Panel send from club.bucuc@g.bracu.ac.bd (same App Password as password resets, A3); HR sends from hr.bucuc@gmail.com.
- **Done 19 Oct 2026:** App Password for hr.bucuc@gmail.com created and saved in `backend/config/mail_accounts.local.php` (Gmail login tested OK, nothing sent). **Still to do:** upload that file to the same folder on Hostinger by hand (git doesn't include it). It is also used by the congratulations e-mail (B2).
- **Decide:** which address MIAP and Secretary Admin send from if they get the send permission (now: the club account; one line in `backend/config/mail_accounts.php`).
- **Test:** as Super Admin and again as HR: Email Notices → check the "Will be sent from" address → Pick members → choose a test member with your Gmail and G-Suite → Send. The progress shows 2 e-mails sent; both inboxes get it with your name filled in, from the right address. The Activity Log shows the sending address.
- **Before notifying everyone:** after the member import (B5), spot-check that members have the right Gmail / G-Suite in Approved Members. HR's account pauses at 400 e-mails a day, the club account at 1,500; press Resume the next day.
- **If everything fails:** the failed list on the progress page says why; "Username and Password not accepted" means that account's App Password is missing or revoked.

### [ ] B10. Homepage stats
- **Do:** Homepage Stats (Super / Panel / HR) → type all four numbers and the semester label (e.g. "Summer 2026"), tick which to show, Save.
- **Test:** the About section of the homepage shows exactly those numbers and the label; untick one and it disappears; the Activity Log shows "Homepage stats changed" with the old and new values.


### [ ] B11. Site colours on the live site
Tested locally only.
- **Test:** Site Colours (Super / Panel / HR) → pick a main colour → both previews change; check the readability list → Save. Open the homepage in a private window: new colours; the Site Colours page itself still looks the same. Then press **Reset to original colours** and check the homepage is back to the original look.
- **If a palette ever makes the panel hard to use:** go straight to `https://<site>/frontend/theme.php` (it always keeps its own readable colours) and press Reset.
### [ ] B12. Performance gallery with real YouTube videos
Tested locally against a fake YouTube (plus one real check of a public video from this computer). The live server must be able to reach youtube.com.
- **Test:** Admin → Performance Gallery → paste a link to one of the club's YouTube videos → "✓ Found on YouTube…" and the title appears → pick a category → Add. Paste a club **Short** (`youtube.com/shorts/…`): "Vertical video (Short)" is ticked. Open `https://<site>/frontend/performances.php` on a phone: thumbnails show, pressing play plays the video (sound on tap), the Short plays in a tall frame. Try a private video's link: you get the "private, or its owner has turned off embedding" message.
- **If every link says "Couldn't reach YouTube":** the host is blocking outgoing connections; tell me (the log `backend/logs/php_errors.log` has the details).
- **If a video shows "Video unavailable" when played:** its owner turned off embedding after it was added; delete or hide it.

### [ ] B13. Attendance with real phones
Tested in a desktop browser and an emulated iPhone, not with real phones scanning a real screen.
- **Test:** as Super Admin → Attendance → New session (a rehearsal starting now, GB, tick "Allow QR self check-in") → Open QR check-in → Start. Put the screen on a laptop or projector. With two real phones: scan, type your student ID → "Checked in". The name appears on the screen within a few seconds. Scan again on the same phone and type another ID → refused. Take a photo of the code, wait 2 minutes, scan the photo → "This code has expired". End check-in. On the session page mark the rest (Mark all present, change one), Save. Then open the GB page: the attendance % is shown under the names.
- **Also check:** the times on the session page are Dhaka time; after the end time, changing a mark asks for a reason and the Activity Log shows it.
- **Decide:** check-in now stays available during a site lockdown (so a rehearsal isn't blocked). Tell me if it should close during lockdowns instead. Sessions can later be linked to events when the events calendar exists (`event_id` is ready).

### [ ] B14. Handover notes on the live site
- **Do:** as Super Admin or Panel Admin → Handover Notes → set "This tenure ends on". Ask every admin to fill their sections and their own role space.
- **Optional (recommended):** hPanel → Advanced → Cron Jobs → once a day: `php /home/<user>/public_html/backend/scripts/handover_reminders.php` (the dashboard also sends the reminder when someone opens it).
- **Test:** set the end date to about 3 weeks from today, open the dashboard: every admin gets "Handover notes: tenure … ends …" within a minute (check spam), listing the empty sections. Set the real end date afterwards. Click PDF → choose "Save as PDF" → the file shows every section (Bangla text intact).

### [ ] B15. Sponsor banners on the live site (Super Admin only)
- **Test:** Super Admin → Sponsor Banners → New ad (a test banner, link to your own page, today to tomorrow) → Spots → switch "below the hero" on. Open the homepage in a private window on a phone and a laptop: the banner with "Sponsored" shows under the hero (the phone image on the phone), the link opens in a new tab. Stats shows the views and your click. Switch the spot off: the banner is gone with no gap. Delete the test ad.
- **Also check as another admin** (e.g. HR): `https://<site>/frontend/sponsors.php` shows the server's normal "Not Found" page, and there is no menu item.

---

## C. Security checks on the live site (all passed locally)

### [ ] C1. Login limit
- **Test:** enter a real admin e-mail with a wrong password 5 times → the 6th try (even with the right password) says "Too many failed attempts. Try again in 15 minutes". Recover with Forgot password (B1) or wait 15 minutes.

### [ ] C2. Deactivate an admin
- **Test:** with two browsers (or one private window): log in as a normal admin in one, as Super Admin in the other. Admin Management → click the amber **deactivate** button on the admin → in the admin's browser click anything → it lands on login with "Your admin account is no longer active". Reactivate with the green button.

### [ ] C3. Session timeout
- **Test:** log in, leave the tab idle for 31 minutes, then click a menu item → login page with "Your session expired after 30 minutes of inactivity". (There is also a 12-hour limit after login: "For security, you are signed out 12 hours after logging in".)

### [ ] C3b. Logout
- **Test:** sidebar → Logout → a small "Log out?" page → **Log out** → homepage; the back button doesn't reopen the dashboard.

### [ ] C4. Activity Log
- **Test:** Super Admin dashboard → Settings → **Activity Log** shows the actions from A–C. Filter Action = "Failed login" shows only those. A normal admin opening `activity_log.php` is sent back to their dashboard.

### [ ] C5. Blocked files
- **Test:** these must show **403 Forbidden** (or 404 for `.git`): `https://<site>/bucuc.sql`, `/backend/logs/email.log`, `/backend/logs/php_errors.log`, `/backend/config/auth_email_config.local.php`, `/backend/Database/config.local.php`, `/backend/Api/members.json`, `/instruction.md`, `/todo.md`, `/.git/config`.

### [ ] C7. Super Admin recovery (try once, so you know it works)
- **Do:** follow `instruction.md` §16 Option A with your own Super Admin e-mail and a new password.
- **Test:** you can log in with the new password; `backend/config/recovery.token` is gone; the Activity Log shows "Super Admin recovered"; `recover.php` is 404 again.

### [ ] C6. Admins with short passwords
The minimum is now 8 characters; older shorter passwords still work.
- **Do:** ask every admin to change theirs in **My Profile** (needs the current password).
- **Test:** Admin Management lists every admin; each has confirmed they changed it.

---

## D. Public site checks

### [ ] D1. Apply form end to end
- **Test:** submit a test application → the "You're in the queue" ticket appears. Submit the same ID again → "already submitted" with fields refilled. Super Admin → Signup Control → Disable → homepage shows "Application Form expired for current Semester"; enable again. Delete the test application.
- **Also test:** open the homepage, log in to the admin panel in another tab and log out again, then submit the open Apply form: it must still go through (fixed 19 Oct 2026; it used to say "The form had expired").
- **Recruitment day:** the form allows 30 saved applications per hour from one IP address (90 tries). If many students apply from one campus Wi-Fi and see "Too many applications were sent from this network", tell me and I'll raise `SIGNUP_MAX_PER_IP_HOUR` in `backend/Action/signup_handler.php`.

### [ ] D3. Crop tool on a real phone and on the live site
Tested in desktop Chrome and an emulated iPhone (finger drag and pinch), not on a real phone.
- **Test:** on a phone, Website Media → add a Secretary Panel person and pick a landscape photo from the gallery: the crop window opens in 3:4, you can drag the photo and pinch to zoom, **Use this crop** shows the preview with "750 × 1000 px" (or smaller), and **Add secretary** saves it; the photo looks sharp on the homepage. Also pick a photo that is already 4:5 for the Presidential Panel: no window, "uploaded as it is", and the Crop button still opens the window.
- **If the window doesn't open:** check that `frontend/vendor/cropperjs/` was uploaded (the page needs `cropper.min.js` and `cropper.min.css`).

### [ ] D2. Real phones
Tested only in a desktop browser at phone size.
- **Test:** on an Android phone and an iPhone: the hero video plays smoothly without cut-off sides, "Tap for sound" (or tapping the video) turns the sound on and you can hear it, the menu drawer opens/closes, the panel year switch works, the About band, Apply form and footer look right, nothing scrolls sideways.

---

## E. Content and clean-up

### [x] E1. Wild Escape photo
Done 19 Oct 2026: the missing `Wild.jpg` was replaced by the existing `2nd.JPG` (the photo the carousel already uses for Wild Escape), and every event photo falls back to a placeholder if its file is missing.

### [ ] E2. Smaller Wild Escape photo
`2nd.JPG` is 7.5 MB and is now used twice (carousel and the Wild Escape event).
- **Do:** export a copy at most 1500 × 1000 px (under 500 KB) and replace `frontend/images/2nd.JPG` with it (same name).
- **Test:** the carousel and the Wild Escape event still show it; the homepage loads faster on a phone.

### [ ] E3. Panel Facebook links
Current panel links are placeholders (`http://www.facebook.com/`).
- **Do:** fill the real links in `backend/Api/members.json` (2027 panel) and tell me the secretaries' links.
- **Test:** clicking each Facebook icon opens that person's profile.

### [ ] E4. Rotate secrets
Moved to **A7** (database and HR). Optional extra: the club.bucuc App Password was pasted in a chat on 12 Oct 2026; if that matters to you, create a new one, revoke the old one, and update `backend/config/auth_email_config.local.php` → `AUTH_SMTP_PASSWORD` on the server.
- **Test:** B1 still sends.

### [ ] E5. Student personal data files
- **Do:** once no longer needed, delete `restore.sql` and `Member_Applications_*.csv` from the repo folder; never upload them.
- **Test:** they're gone from the folder and from the server.

### [x] E6. Untrack old logs
Done 19 Oct 2026: the logs and `.claude/settings.local.json` are removed from git (staged) and ignored. They are still in the old commits: `instruction.md` §17 removes them from the history.

### [x] E7. Delete `backend/Action/application_handler.php`
Done 19 Oct 2026 (it bypassed the login and CSRF checks). Nothing referenced it.
- **Test:** the full B2 flow still works.

### [x] E9. Old built-in event cards
Done 20 Oct 2026: every past event, including the 12 older stories, is now edited and removed in Website Media → Past Events.
- **Fix (content):** the "A Magical Qawali Night at AquaVenture" story's highlights are copied from the Wild Escape story (it was like that in the old page). Website Media → Past Events → Edit → Highlights.
- **Test on the live site:** open `…/frontend/past_events.php#country-music` and `#event-6` from an old link or QR code: the right story is shown.

### [x] E8. Placeholders
Done 23 Oct 2026: Footer placeholder links (Terms, Privacy, Feedback) have been removed. Sidebar placeholder items ("Tell a Friend", "Help & Feedback") and "Active Panel" stat were previously removed.

### [ ] E10. One admin stylesheet (redesign)
The older admin pages each still carry their own `<style>` block (audit item M12). The shared mailer and the shared pop-up script are done; merging the page styles into one file is left for the planned redesign.
- **Do:** during the redesign, move the shared parts into `css/aurora.css` / one admin stylesheet.
- **Test:** every admin page looks the same before and after (`colorsnap` comparison).
