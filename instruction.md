# BUCuC Website — Complete Build Instructions

This document describes the BRAC University Cultural Club (BUCuC) website precisely enough to rebuild it from an empty folder: what every page does, how data moves, the database, permissions, the content files, and the full visual design. Section 13 lists what was fixed in the October 2026 clean-up and what still needs a human.

---

## 1. What the site is

| | |
|---|---|
| Purpose | Public club website + recruitment pipeline (Apply → Shortlist → Final approval) + admin dashboards |
| Stack | Plain PHP 8 (no framework), MySQL/MariaDB via PDO, Bootstrap 5.2 (grid/carousel/tabs), vanilla JS + a little jQuery, Chart.js, Swiper, a canvas "aurora" theme (§10) |
| Hosting | Hostinger (Apache/LiteSpeed, Linux, MySQL). The **whole repository is the web root** |
| Entry point | `/index.php` → redirects to `/frontend/index.php` |
| PHP version | ≥ 8.0 (Composer platform check). Extensions: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `zip`, `gd`, `xml`, `fileinfo` |
| Composer packages (committed in `backend/vendor`) | `phpmailer/phpmailer` 6.12, `phpoffice/phpspreadsheet` 1.30 |
| External services | Gmail SMTP (congratulations e-mail), Google Apps Script web apps (append accepted members; edit SB/ASB/GB sheet), a published Google Sheet CSV (SB/ASB/GB directory) |

---

## 2. Directory map

```
/
├── index.php                      302 → /frontend/index.php
├── .htaccess                      forces HTTPS (live), security headers, blocks *.sql/.log/.csv/.xlsx/.json/.md/.token…, /.git, /.claude
├── .gitignore
├── bucuc.sql                      legacy phpMyAdmin dump (Dec 2025). Historical reference only
├── instruction.md                 this file
├── backend/
│   ├── Action/                    endpoints called by forms / fetch()
│   │   ├── signup_handler.php            public Apply form → pending_applications
│   │   ├── export_members_excel.php      ?status=Pending|Shortlisted|Accepted → .xlsx download
│   │   ├── clear_all_applications.php    Super/Panel Admin: wipe all 3 applicant tables (archived first)
│   │   ├── clear_shortlisted_applications.php  Super/Panel Admin: wipe shortlist (archived first)
│   │   ├── delete_venue.php              delete a venuinfo row (JSON)
│   │   └── google_sheets_integration.php sendToGoogleSheets(), logGoogleSheetsOperation()
│   ├── Api/
│   │   ├── members.json                  copy of all panel tenures, rewritten from the DB after every change (seed source on first run)
│   │   └── get_members.php               public JSON API over members.json (?year=&type=&department=)
│   ├── config/
│   │   ├── email_config.php              SMTP constants + EMAIL_LOG_PATH (congratulations e-mail, hr.bucuc@gmail.com)
│   │   ├── auth_email_config.php         AUTH_SMTP_* + APP_URL (admin password e-mails, club.bucuc@g.bracu.ac.bd)
│   │   ├── auth_email_config.local.php   (git-ignored) real AUTH_SMTP_PASSWORD (club account)
│   │   ├── mail_accounts.php             sender accounts + daily limits; mail_accounts.local.php (git-ignored) = HR_SMTP_PASSWORD
│   │   ├── recovery.token                (only while recovering the Super Admin, §16; git-ignored, blocked)
│   │   └── google_sheets_config.php      GOOGLE_SHEETS_WEBAPP_URL, timeout, logging flag
│   ├── Database/
│   │   ├── config.php                    loads config.local.php; no password in git
│   │   ├── config.local.php              (git-ignored, upload by hand) DB_HOST, DB_NAME, DB_USER, DB_PASS
│   │   ├── db.php                        error logging, security headers, "Site temporarily unavailable" page, class Database
│   │   ├── schema.sql                    fresh install
│   │   └── upgrade.sql                   bring an existing live DB up to date (run once)
│   ├── includes/
│   │   ├── auth.php                      sessions, require_admin(), CSRF, login throttle, reset tokens, log_activity()
│   │   ├── roles.php                     5 roles, permissions, can() / require_can(), who may manage whom
│   │   ├── site.php                      db(), schema self-check, settings, lockdown, past events, upload rules, MP4 reader
│   │   ├── palette.php                   site colour palette: generator, contrast checks, save / history, page injection
│   │   ├── palette_map.json              every colour variable: role + offset from the role colour (made by colorize_css.py)
│   │   ├── mailer.php                    one place for sending mail: mailer_make(), capture for tests, mail_log + daily counts
│   │   ├── auth_mail.php                 send_auth_email(), auth_mail_layout()
│   │   ├── notices.php                   Email Notices jobs and batches
│   │   ├── gallery.php                   Performance gallery: YouTube link parsing, oEmbed check, order (§6.12)
│   │   ├── attendance.php                attendance sessions, marks, percentages, QR check-in codes (§6.13)
│   │   ├── handover.php                  handover notes per tenure: sections, versions, search, reminders (§6.14)
│   │   ├── ads.php                       sponsor banners (Super Admin only): spots, ad_slot(), view / click counting (§6.15)
│   │   ├── palette_bootstrap.css         Bootstrap buttons/alerts on palette variables (injected)
│   │   ├── email_template.php            getLatestVenueInfo(), generateEmailTemplate(), generatePlainTextEmail()
│   │   └── dashboard_stats.php           getDashboardStats(PDO)
│   ├── logs/                             php_errors.log, email.log, google_sheets.log, auth_email.log, cleared_*.log (archives); all git-ignored
│   ├── scripts/create_admin.php          CLI: create/reset an admin account (any role)
│   ├── scripts/handover_reminders.php    CLI / cron: send the handover reminder when it is due
│   ├── scripts/colorize_css.py           turns plain colours in the CSS into palette variables (re-run after adding CSS)
│   └── vendor/                           Composer dependencies (committed)
├── tests/                            browser test suites and their runner (§18): node tests/run.js
│   ├── run.js                        starts a throwaway database + site, runs the suites, prints a summary
│   ├── config.js                     paths, ports, tool locations (XAMPP_DIR, CHROME_PATH)
│   ├── test_prepend.php              test settings: test database, e-mail to a file, fake Google / YouTube
│   ├── e2e/*.js                      the 15 suites            seed/seed.php  made-up test data
│   ├── fixtures/                     fake Google Sheet / Apps Script / YouTube, test images and videos
│   └── runtime/                      (git-ignored) the test database, logs, sessions, downloads
└── frontend/
    ├── index.php                         public one-page site
    ├── past_events.php                   public Past Events page: every event from the database inside past_events.html, lockdown-aware
    ├── past_events.html                  the Past Events page design (the stories now live in site_events)
    ├── .htaccess                         rewrites old past_events.html links to past_events.php
    ├── admin-login.php / logout.php      (logout only by POST with the CSRF token)
    ├── performances.php                  public Performance Gallery (YouTube videos, §7.3)
    ├── attendance.php                    Attendance: sessions list, new / edit session, members report (take_attendance)
    ├── attendance_session.php            one session: totals + checklist (Present / Late / Excused / Absent)
    ├── attendance_checkin.php            QR check-in screen (rotating code, live list, end / remove)
    ├── attendance_member.php             one member's attendance (overall, per semester, every session)
    ├── attendance_export.php             Excel: one session, or the members report
    ├── checkin.php                       members' QR self check-in (no login)
    ├── handover.php                      Handover Notes (every admin): tenure documents, edit / history / restore, search
    ├── handover_print.php                a tenure's handover document as a print page (browser → Save as PDF)
    ├── sponsors.php / sponsors_export.php  sponsor banners and the per-ad Excel report (Super Admin; 404 for everyone else)
    ├── go.php                            sponsor banner click: counts it, then redirects to the advertiser
    ├── video_gallery.php                 Performance Gallery admin: add / edit / hide / reorder / delete videos (manage_gallery)
    ├── recover.php                       one-time Super Admin recovery, 404 unless backend/config/recovery.token exists (§16)
    ├── forgot-password.php / reset-password.php   e-mailed password reset (§6.6)
    ├── activity_log.php                  who did what to whom, with filters (every admin; Super Admin rows hidden from others)
    ├── media.php                         Website Media: carousel, panel photos, past events, hero video
    ├── lockdown.php                      schedule / cancel / lift a site lockdown (Super Admin)
    ├── theme.php                         Site Colours: palette from one colour, readability, preview, history (top 3)
    ├── super_admin_dashboard.php / admin_dashboard.php   thin wrappers → includes/dashboard_view.php
    ├── pending_applications.php / shortlisted_members.php / approved_members.php
    ├── handle_application.php            workflow POST handler (shortlist / reject / accept / delete)
    ├── set_venue.php                     orientation venue (used in the e-mail)
    ├── dashboard_updates.php             manual dashboard numbers + growth chart points (Super/Panel Admin)
    ├── admin-signup-control.php          open/close the Apply form (Super/Panel Admin)
    ├── admin_management.php              admins, roles, permission ticks (Super / Panel / HR)
    ├── profile.php                       edit own username/email/password
    ├── member_types.php                  menu → SB / ASB / GB
    ├── sb_members.php / asb_panel.php / gb_members.php   thin wrappers → includes/member_board.php
    ├── includes/dashboard_view.php, includes/member_board.php
    ├── AdminCss/Dashboard.css, AdminCss/admin.css        older admin styles (recoloured by aurora.css)
    ├── css/  aurora.css (theme for every page), site.css (homepage), gallery.css (video cards), image-crop.css,
    │         bootstrap.min.css (5.2.2), bootstrap-icons.css, templatemo-festava-live.css (admin pages only)
    ├── js/   aurora-sky.js (sky on every page), site.js (homepage), dashboard-charts.js, admin-toast.js (pop-up messages), image-crop.js (crop and resize before upload),
    │         jquery.min.js (2.2.3), bootstrap.min.js (5.2.2), click-scroll.js
    ├── images/  logo.png, logopng.png, placeholder.png/.svg, slides, Advisor/, Panel_19_20 … Panel_26_27/, tenures/, events/ (uploads)
    └── video/   bucuc1.mp4 + bucuc1.webm (original hero video), hero-*.mp4 (uploads)
```

Every internal folder (`backend/logs`, `backend/Database`, `backend/config`, `backend/scripts`, `backend/includes`, `backend/vendor`, `frontend/includes`) has a deny-all `.htaccess`. PHP still `require`s those files from disk; browsers can't fetch them. `frontend/images` and `frontend/video` have an `.htaccess` that refuses scripts (`.php`, `.phtml`, `.phar`, `.cgi`, …), so an upload can never run as code.

---

## 3. Setup

### 3.1 Local
1. PHP 8.x with the extensions above, plus MySQL/MariaDB.
2. Create the database and import `backend/Database/schema.sql`.
3. Create `backend/Database/config.local.php` (git-ignored) with `<?php define('DB_HOST', 'localhost'); define('DB_NAME', '…'); define('DB_USER', '…'); define('DB_PASS', '…');`. `config.php` loads it; without it the password is empty.
4. Create the first Super Admin:
   `php backend/scripts/create_admin.php "Super Admin" you@example.com "strong-password" super_admin`
   (other roles: `panel_admin`, `hr_admin`, `miap_admin`, `secretary_admin`; one account per role)
   (running it again with the same e-mail resets that password/role).
5. Serve the repo root: `php -S 127.0.0.1:8080 -t .` → open `http://127.0.0.1:8080/`.

### 3.2 Live (Hostinger)
- Upload the repository to `public_html` (the repo root = web root). Then upload the git-ignored secret files by hand with File Manager (they are never in git): `backend/Database/config.local.php` (database), `backend/config/auth_email_config.local.php` (club App Password), `backend/config/mail_accounts.local.php` (HR App Password). See §3.4.
- The root `.htaccess` redirects every `http://` request to `https://` (except `localhost` / `127.0.0.1`), so the session cookie is always sent with `Secure`. If the site ever shows a redirect loop, Hostinger's own "Force HTTPS" is fighting it: turn one of them off.
- **Run `backend/Database/upgrade.sql` once in phpMyAdmin** (see §13.2). It's needed so that accepting applicants without a date of birth works. If you already ran it before 13 Oct 2026, run **`backend/Database/upgrade_roles.sql`** instead, right after uploading the role system: it turns the old roles into the five new ones (§5). Set `@super_email` at its top to choose which account becomes Super Admin.
- Uploads go into `frontend/images/…` and `frontend/video/`; those folders must be writable by PHP (Hostinger default is fine). `backend/Api/members.json` must be writable too (panel photos), and `backend/logs/` (backups + schema marker). PHP's `upload_max_filesize` / `post_max_size` must be at least 8 MB.
- PHP runs on Dhaka time (`date_default_timezone_set('Asia/Dhaka')` in `db.php`) and every MySQL connection uses the same offset, so NOW(), stored times and lockdown times agree.
- The congratulations e-mail and HR's notices are sent from `hr.bucuc@gmail.com` with the 16-character **App Password** in `HR_SMTP_PASSWORD` (`backend/config/mail_accounts.local.php`; 2-Step Verification must be on).
- **Forgot-password e-mails** are sent from `club.bucuc@g.bracu.ac.bd` (BRACU Google Workspace). Sign in to that account, turn on 2-Step Verification, create an App Password ("BUCuC website"), and put it in `backend/config/auth_email_config.local.php` as `<?php define('AUTH_SMTP_PASSWORD', 'xxxxxxxxxxxxxxxx');` (upload this file by hand; it's git-ignored). If "App passwords" is missing, the BRACU IT admin has turned it off for the domain and must allow it. Until the password is set, reset requests are logged as "NOT SENT" in `backend/logs/auth_email.log` and nothing is mailed. Sending through Gmail SMTP is free (Workspace accounts allow about 2,000 messages a day).
- **Email notices from HR** are sent from `hr.bucuc@gmail.com` (normal Gmail). Turn on 2-Step Verification for that account, create an App Password, and upload `backend/config/mail_accounts.local.php` containing `<?php define('HR_SMTP_PASSWORD', 'xxxxxxxxxxxxxxxx');` (git-ignored, never shown in the panel). Until then HR's Email Notices page says the address isn't set up and the Send button is disabled.
- Set `APP_URL` in `auth_email_config.php` to the public address (default `https://bucuc.org`); reset links are built from it, never from the request's Host header.

### 3.3 Configuration constants
| File | Constant | Meaning |
|---|---|---|
| `backend/Database/config.local.php` (git-ignored) | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | PDO DSN `mysql:host=…;dbname=…;charset=utf8mb4`, ERRMODE_EXCEPTION, FETCH_ASSOC, real prepares. `config.php` only holds defaults with an empty password |
| `backend/config/email_config.php` | `SMTP_HOST` smtp.gmail.com, `SMTP_PORT` 587, `SMTP_SECURITY` tls, `SMTP_USERNAME`, `SMTP_PASSWORD` (= `HR_SMTP_PASSWORD` from `mail_accounts.local.php`), `FROM_EMAIL`, `FROM_NAME`, `EMAIL_LOG_PATH` | Congratulations e-mail (sent through `mailer.php`, account `hr`) |
| `backend/config/google_sheets_config.php` | `GOOGLE_SHEETS_WEBAPP_URL`, `GOOGLE_SHEETS_TIMEOUT` (30), `GOOGLE_SHEETS_LOGGING` (true) | Accepted-member sheet. **Keep this existing sheet:** `https://script.google.com/macros/s/AKfycbyPKki88NP8D_geycHZpzNgwiC-U0l-y9--sfJg1bQ2AOW7hjcuy7MNYZU-VTPg9Hxr/exec`. Every final approval appends a row to it (see §6.4a). Don't replace it with a new sheet during a rebuild or redesign |
| `backend/config/auth_email_config.php` | `AUTH_SMTP_HOST` smtp.gmail.com, `AUTH_SMTP_PORT` 587, `AUTH_SMTP_SECURITY` tls, `AUTH_SMTP_USERNAME` / `AUTH_FROM_EMAIL` club.bucuc@g.bracu.ac.bd, `AUTH_FROM_NAME` "BUCuC Admin", `AUTH_SMTP_PASSWORD` (empty here; set in the `.local.php` file), `APP_URL`, `AUTH_EMAIL_LOG_PATH` | Admin password-reset and password-changed e-mails. Every constant uses `defined() \|\| define()`, so the local file can override any of them. Defining `AUTH_MAIL_CAPTURE` (a file path) writes messages to that file instead of sending: for local testing only |
| `backend/config/mail_accounts.php` | `MAIL_ACCOUNTS` (`club` = club.bucuc@g.bracu.ac.bd, 1,500 e-mails/day, password `AUTH_SMTP_PASSWORD`; `hr` = hr.bucuc@gmail.com, 400/day, password `HR_SMTP_PASSWORD` from the git-ignored `mail_accounts.local.php`), `MAIL_ACCOUNT_FOR_ROLE` (super / panel → club, hr → hr, miap / secretary → club until decided) | Sender account for Email Notices by role. The daily limit counts **every** e-mail the account sends (notices, password e-mails, congratulations e-mails; `mail_log` table) |
| `backend/includes/auth.php` | `AUTH_IDLE_TIMEOUT` 1800 s, `AUTH_MAX_SESSION` 12 h, `AUTH_MIN_PASSWORD` 8, `AUTH_MAX_FAILS_EMAIL` 5, `AUTH_MAX_FAILS_IP` 20, `AUTH_LOCK_MINUTES` 15, `AUTH_RESET_MINUTES` 30 | Admin security policy (§6.6) |
| `backend/Action/signup_handler.php` | `SIGNUP_MAX_PER_IP_HOUR` 30, `SIGNUP_MAX_POSTS_PER_HOUR` 90 | Apply form limits per IP address (campus Wi-Fi shares addresses, so keep them generous) |
| `frontend/includes/member_board.php` | `MEMBER_SHEET_CSV_URL`, `MEMBER_SHEET_GAS_URL` | SB/ASB/GB directory (read CSV / write via Apps Script) |

**Errors are never shown to visitors.** `db.php` (loaded first by every page) turns `display_errors` off and writes every PHP error to `backend/logs/php_errors.log`. If the database can't be reached, or an uncaught error happens, the visitor gets a plain "Site temporarily unavailable" page (HTTP 503 / 500; JSON `{"success":false,…}` for API calls) and the details only go to the log. Database errors inside admin forms show "Saving failed because of a database problem…", never the SQL message.

### 3.4 Secrets (never in git)
| What | File on the server (git-ignored, upload with File Manager) | Change it when |
|---|---|---|
| Database password | `backend/Database/config.local.php` → `DB_PASS` (and `DB_USER` if hPanel shows a different user) | you change the password in hPanel → Databases |
| Club App Password (password e-mails, notices from Super / Panel) | `backend/config/auth_email_config.local.php` → `AUTH_SMTP_PASSWORD` | you create a new App Password for club.bucuc@g.bracu.ac.bd |
| HR App Password (congratulations e-mails, HR notices) | `backend/config/mail_accounts.local.php` → `HR_SMTP_PASSWORD` | you create a new App Password for hr.bucuc@gmail.com |

All three files use `defined('X') || define('X', '…');`. Older versions of `config.php` and `email_config.php` contained the real passwords, so they are still in the git history; §17 shows how to remove them.

---

## 4. Database

Charset `utf8mb4`, InnoDB. Fresh schema: `backend/Database/schema.sql`.

| Table | Purpose | Key columns |
|---|---|---|
| `adminpanel` | Admin accounts | `id`, `username` (unique), `email` (unique), `password` (password_hash), `role` VARCHAR(20) NULL, **unique** (`super_admin`, `panel_admin`, `hr_admin`, `miap_admin`, `secretary_admin`; NULL = no role, can't log in; old values `main_admin`/`admin` are read as Super / Secretary until upgrade_roles.sql runs), `status` ENUM(`active`,`inactive`), timestamps |
| `pending_applications` | Stage 1, new applications | `id`, `full_name`, `university_id`, `email`, `gsuite_email`, `department` (academic, free text), `phone`, `semester` (`1st`…`9th`, `10th+`), `gender` (`Male`/`Female`/`Other`), `date_of_birth` (NULL ok), `facebook_url`, `firstPriority`, `secondPriority`, `created_at` (= application date), `updated_at` |
| `shortlisted_members` | Stage 2, waiting for final approval | same columns as `pending_applications` |
| `members` | Stage 3, approved members **and the member directory** | same applicant columns + `position` ENUM(`GB`,`ASB`,`SB`) default `GB` (member level; SB = Secretary Panel; added automatically on first run); `university_id`, `email`, `gsuite_email` UNIQUE. Members imported from the Google sheet have only name, ID, G-Suite and level (e-mail = G-Suite, or `{id}@members.bucuc.local` when the sheet has none). The live table also still has legacy columns (`event_category`, `gender_tracking`, `application_status`, `status`, `motivation`) that nothing reads |
| `venuinfo` | Orientation venue; only the newest row (highest `venue_id`) is used | `venue_name`, `venue_location`, `venue_dateTime` DATETIME, `venue_startingTime`, `venue_endingTime` (free text like `3:00`), `venu_ampm` (`AM`/`PM`; the misspelling is part of the schema) |
| `signup_status` | One row, `id = 1` | `is_enabled` 1/0, `updated_by`, `updated_at` |
| `dashboardmanagement` | Append-only log of manual dashboard numbers; the newest row wins | `totalmembers` (legacy base count), `completedevents`, `gd_male`, `gd_female` (`pending_applications`, `others`, `mg_*` unused) |
| `chart_data` | Member Growth chart points | `category` = `member_growth`, `label`, `value`, ordered by `id` |
| `admin_login_attempts` | Login throttling | `email` (lower-case), `ip`, `success` 1/0, `created_at`. A successful login deletes that e-mail's failures; rows older than 30 days are pruned now and then |
| `admin_password_resets` | Reset links | `admin_id`, `token_hash` (sha256 of the e-mailed token, unique; the token itself is never stored), `expires_at` (+30 min), `used_at` (single use; requesting a new link voids older ones), `ip`, `created_at` |
| `admin_activity_log` | Audit trail | `admin_id`, `admin_name` (kept even if the admin is later deleted), `actor_role` (role at the time), `action` (code, §6.6), `target` (to whom / what), `target_role` (when the target is an admin), `details`, `ip`, `created_at` |
| `admin_permissions` | Permission ticks | `admin_id` + `permission` (PK), `allowed` 1/0, `updated_by`. Only differences from the role defaults are stored |
| `site_lockdown` | Lockdown schedule | `starts_at` (Dhaka time), `notice` (admins), `public_message` (visitors), `status` `scheduled`/`cancelled`/`lifted`, `created_by`, `ended_at`, `ended_by`. The newest `scheduled` row is the current one; it is "active" once `starts_at` has passed |
| `site_settings` | Key/value | `palette` (saved site palette JSON), `palette_css` (its ready `:root{…}` CSS), `palette_history` (last 5 saved palettes), `home_stats` (homepage numbers JSON), `hero_video` (path of the uploaded MP4, empty = original), `board_pos:<studentId>` (last move made here, JSON, trusted for 10 min because Google caches the published CSV) |
| `tenures` | Panel tenures | `label` (unique, e.g. `2026-27`), `start_year` (ordering), `is_current` (exactly one), `created_by`. At most 12 (`TENURE_LIMIT`) |
| `tenure_people` | People of a tenure | `tenure_id`, `grp` (`panel` = Presidential Panel, `secretary` = Secretary Panel), `name`, `position`, `facebook`, `image` (path under `frontend/`), `sort_order` |
| `application_events` | Semester stats | `kind` (`applied` when the Apply form is submitted, `rejected` when an application is rejected), `university_id`, `applied_at` (when the person applied; rejections carry the application date), `created_at` |
| `notices` | Email notice jobs | `subject`, `body` (with placeholders), `audience` (e.g. "All members", "GB members", "3 selected members"), `sender_key` / `sender_email` (sending account), `members`, `total` (e-mails), `sent`, `failed`, `status` (`sending`/`done`), `sent_by`, `sent_by_name`, `sent_by_role` (so the Super Admin's name is hidden from others), `created_at`, `finished_at` |
| `notice_recipients` | One row per e-mail | `notice_id` (FK → `notices`, cascade), `member_id`, `name`, `email`, `student_id`, `level`, `status` (`pending`/`sending`/`sent`/`failed`), `error`, `sent_at`, `batch_token` + `claimed_at` (a batch claims its rows in one UPDATE before sending, so two open tabs never send the same e-mail; rows stuck in `sending` for 10 minutes go back to `pending`) |
| `mail_log` | Every non-notice e-mail sent (daily limits) | `account_key` (`club`/`hr`), `kind` (`auth`/`welcome`), `recipient`, `created_at`; kept 30 days |
| `signup_attempts` | Apply form rate limit | `ip`, `saved` 1/0, `created_at`; kept 1 day |
| `ad_spots` | Sponsor banner spots | `spot` (`home_hero`, `home_middle`, `home_footer`, `events`), `enabled` |
| `ads` | Sponsor banners | `spot`, `advertiser`, `link` (http/https), `image`, `image_mobile` (optional), `starts_on`, `ends_on` (Dhaka dates, inclusive), `active`, `created_by` |
| `ad_stats` | Views and clicks per ad per day | `ad_id` (FK, cascade) + `day`, `views`, `clicks` |
| `ad_clicks` | Recent clicks (hashed IP + browser) | so one visitor's repeat clicks within 10 minutes count once; kept 1 day |
| `handover_docs` | One handover document per tenure | `tenure_label` (unique; the notes are found by it), `tenure_id` (FK → `tenures`, **set to NULL** when the tenure is deleted, so the notes survive the 12-tenure rule), `unlocked` (Super Admin unlocked an old document), `ends_on` (tenure end, for the reminder), `reminder_sent_at` |
| `handover_sections` | Current content of each section | `doc_id` + `section_key` (`summary`, `contacts`, `worked`, `didnt`, `pending`, `calendar`, `accounts`, `links`, `role:{role}`), `content` (text, or a JSON list of rows for table sections), `version_id`, `updated_by`, `updated_by_role`, `updated_at` |
| `handover_versions` | Every saved version | `doc_id`, `section_key`, `content`, `saved_by`, `saved_by_role`, `saved_at`, `note` (e.g. "Restored the version of …", "Done: …") |
| `attendance_sessions` | Attendance sessions | `title`, `type` (`rehearsal`/`meeting`/`event`/`workshop`/`other`), `session_date`, `start_time`, `end_time` (optional; without it the session ends at midnight), `audience` (`all`/`GB`/`ASB`/`SB`/`pick`), `event_id` (for the future events calendar; unused), `qr_enabled`, `checkin_secret` (signs the QR codes), `checkin_opened_at`, `checkin_closes_at`, `created_by` |
| `attendance_expected` | Who is expected at a session | `session_id` + `member_id` (FKs, cascade). Saved when the session is created or edited, so later level changes don't rewrite old percentages |
| `attendance_records` | Marks | `session_id` + `member_id` (PK; FKs, cascade), `status` (`present`/`late`/`excused`/`absent`), `method` (`manual`/`qr`), `marked_by`, `marked_at` |
| `attendance_checkin_fails` | Wrong student IDs at QR check-in | `ip`, `created_at` (limit per network) |
| `gallery_videos` | Performance gallery (YouTube only, no files) | `youtube_id` (11 chars), `start_seconds` (from a `t=` / `start=` in the link), `title`, `category` (`music_video` / `flash_mob` / `show_highlight` / `other`), `video_date` (optional), `tenure_id` (optional; FK → `tenures`, set to NULL if the tenure is deleted), `description`, `thumbnail` (i.ytimg.com URL from oEmbed), `is_short` (vertical), `featured`, `hidden`, `sort_order`, `created_by`, timestamps. Created by `gallery_ensure()` on first use |
| `site_events` | Uploaded past events | `title`, `event_date`, `place`, `description` (blank line = paragraph), `highlights` (one per line), `image` (under `frontend/`), `created_by` |

All `admin_*` and `site_*` tables are in `schema.sql` and `upgrade.sql` / `upgrade_roles.sql`. `site_ensure_schema()` (site.php) also creates them, adds newer columns, indexes (`members.position`, shortlist `university_id` / `gsuite_email`, activity log `admin_id` / `actor_role`) and foreign keys with ON DELETE CASCADE (`tenure_people` → `tenures`, `admin_permissions` → `adminpanel`, `admin_password_resets` → `adminpanel`; `notice_recipients` → `notices` is added by notices.php) on first use. Rows already pointing at a deleted parent are removed before a key is added. Afterwards it writes `backend/logs/.schema_v6` and skips the check (delete that file to run it again). The role change itself (column type, unique key, mapping old roles) needs the SQL file.

Department priority codes: `Admin`, `PR`, `HR`, `EM`, `Creative`, `Finance`, `Performance`, `RD`, `MIAP`.

**Table-name case:** Linux MySQL is case-sensitive. The live database (and the code) use `venuinfo`, all lowercase. Only if phpMyAdmin shows a `venuInfo` with a capital I, use `upgrade.sql` step 4.

---

## 5. Roles and permissions

Five roles, highest first. **One account per role** (unique key on `adminpanel.role`). Everything is defined in `backend/includes/roles.php` and checked on the server on every request.

| # | Role (code) | Label shown |
|---|---|---|
| 1 | `super_admin` | Super Admin |
| 2 | `panel_admin` | Panel Admin |
| 3 | `hr_admin` | Secretary Admin (HR) |
| 4 | `miap_admin` | Secretary Admin (MIAP) |
| 5 | `secretary_admin` | Secretary Admin |

**Fixed powers** (never toggled): everyone can **shortlist** (and reject pending applications, export pending/shortlisted). `manage_site` = Signup Control, Dashboard Updates, Clear All / Clear List, delete approved members, remove people from the member sheet (Super, Panel). `admin_management` (Super, Panel, HR). `manage_permissions` (Super, Panel). `home_stats` = Homepage Stats (Super, Panel, HR). `theme` = Site Colours (Super, Panel, HR). `lockdown` (Super).

**Grantable permissions** (ticks): defaults per role, which the Panel Admin or Super Admin can switch on/off per account for **HR, MIAP and Secretary Admin** (not for Panel Admin).

| Permission | What it unlocks | Panel | HR | MIAP | Secretary |
|---|---|---|---|---|---|
| `final_approve` | Final Approve / reject shortlisted, Set Venue, delete venue | ✓ | ✓ | — | — |
| `view_members` | Members menu (GB/ASB/SB pages), Approved Members, export approved | ✓ | ✓ | — | — |
| `promote_gb_asb` | Promote GB → ASB, demote ASB → GB (includes viewing the lists) | ✓ | ✓ | — | — |
| `promote_sp` | Promote ASB → Secretary Panel (SB), demote SB → ASB (includes viewing) | ✓ | — | — | — |
| `upload_carousel` | Secretaries carousel photos | ✓ | ✓ | ✓ | — |
| `upload_panel` | Current panel members + photos | ✓ | ✓ | ✓ | — |
| `upload_events` | Past events with photo + details | ✓ | ✓ | ✓ | — |
| `upload_hero` | Homepage hero video | ✓ | ✓ | ✓ | — |
| `send_notices` | Email Notices to members | ✓ | ✓ | — | — |
| `manage_gallery` | Performance Gallery: add, edit, hide, reorder, delete YouTube videos | ✓ | ✓ | ✓ | — |
| `handover_contacts` | Read and edit the Contacts section of the handover notes (also in search, history and the PDF) | ✓ | ✓ | — | — |
| `take_attendance` | Attendance: sessions, marking, QR check-in, reports, Excel; the attendance % on the member pages | ✓ | ✓ | — | — |

The Super Admin has everything. `can($perm)` answers for the signed-in admin; pages use `require_admin(['can' => …])`, actions call `require_can()` again.

**Managing admins** (Admin Management):

| Actor | Create | Edit name/e-mail/password/role | Remove / deactivate | Permission ticks |
|---|---|---|---|---|
| Super Admin | any role (vacant only) | anyone, incl. own account | anyone but self | HR, MIAP, Secretary |
| Panel Admin | HR, MIAP, Secretary | HR, MIAP, Secretary, role-less | HR, MIAP, Secretary, role-less | HR, MIAP, Secretary |
| HR | — | — | MIAP, Secretary | — |
| MIAP, Secretary | no access to the page | | | |

Rules: you can only remove admins ranked **below** you; nobody changes their own role or status; "revoke powers" = deactivate (signed out at once, can't log in). Removing an account frees its role. Changing a role clears that account's ticks.

**The Super Admin is invisible to everyone else**: not listed in Admin Management (forged requests about it answer "That admin no longer exists"), not offered as a role to create, filtered out of the Activity Log (rows where the actor or target is/was the Super Admin, by `actor_role`/`target_role` and by the current Super Admin's id), not in the log's admin filter, and no page text mentions it to other admins ("senior admin", "Panel Admin only", "final-approval permission" instead). The lockdown notice shows no author.

**Accounts without a role** (after `upgrade_roles.sql`, every old account except the chosen Super Admin) can't log in ("Your account has no admin role yet. Ask a senior admin to assign one.") until the Super Admin or Panel Admin gives them a role in Admin Management → Edit.

Session keys set at login by `auth_login()` (which also calls `session_regenerate_id(true)`): `admin` (true), `admin_id`, `username`, `admin_email`, `admin_role` (normalised code), `admin_logged_in`, `admin_name`, `last_activity`, `pw_fp` (password fingerprint, §6.6). `csrf_token` is created on first use.

Every admin page and endpoint starts with one call from `backend/includes/auth.php`: `require_admin()` (any active admin with a role), `require_admin(['can' => 'view_members'])` or `['can' => [list]]` (needs that permission / any of them), `require_admin(['super' => true])`, plus `'json' => true` for fetch() endpoints (401/403/419/503 JSON) and `'inject' => false` for file downloads. It starts the hardened session, enforces the idle timeout, reloads the account on every request (deleted, deactivated, role-less or re-roled admins and sessions from before a password change are signed out), signs non-Super admins out during a lockdown, loads the permissions, checks CSRF on POST, adds the token to forms and adds the lockdown notice. Blocked pages redirect to the admin's dashboard with "You don't have permission to open that page."

| Page / endpoint | Needs |
|---|---|
| dashboards, profile, activity log, pending & shortlisted lists | any admin |
| `handle_application.php` shortlist / reject pending | shortlist |
| accept, reject shortlisted, `set_venue.php`, `delete_venue.php` | final_approve |
| delete approved member, clear endpoints, signup control, dashboard updates | manage_site |
| `approved_members.php`, `member_types.php`, SB/ASB/GB pages, export `?status=Accepted` | view_members |
| GAS proxy update | the move's permission (§6.7); delete needs manage_site |
| `media.php` | any upload permission; Presidential Panel = upload_panel, Secretary Panel = upload_carousel, creating / switching / deleting tenures = either of the two, events = upload_events, hero = upload_hero |
| `admin_management.php` | admin_management (actions per table above) |
| `lockdown.php` | lockdown |
| `home_stats.php` | home_stats |
| `notices.php` | send_notices |
| `video_gallery.php` | manage_gallery |
| `attendance*.php` | take_attendance (`checkin.php` needs no login) |
| `handover.php`, `handover_print.php` | any admin (Contacts: handover_contacts; role spaces: own role) |
| `theme.php` | theme |

`index.php` and `signup_handler.php` call `auth_start_session()` so the public flash messages use the same cookie settings.

---

## 6. The recruitment workflow

```
Apply form ──► pending_applications ──Shortlist (any admin)──► shortlisted_members ──Final Approve (Super Admin)──► members
                   │ Reject (any admin)                            │ Reject (Super Admin)                         │ Delete (Super Admin)
                   ▼                                               ▼                                              ▼
                deleted                                          deleted                                        deleted
```

### 6.1 Apply (`backend/Action/signup_handler.php`, form on `index.php#signup`)
Form field names: `signup-name`, `signup-id`, `signup-main-email`, `signup-gsuite-email`, `signup-department`, `signup-phone`, `signup-semester`, `signup-gender`, `signup-dob`, `signup-facebook`, `signup-first-dept`, `signup-second-dept`.

Server checks, in order:
0. Spam trap: a hidden `website` field that people never see; if it's filled the visitor sees the normal success message and nothing is saved. Then, if applications are closed, "Applications are currently closed." (before the token check, so a tab opened earlier gets the real reason). Then the form token: a time stamp signed with a server secret (`form_token()` / `form_token_ok()` in site.php, secret in `site_settings.form_secret`), valid for 12 hours and **not tied to the session**, so an admin logging in or out in the same browser, an expired session or a slow applicant never breaks it. A missing, forged or older token gets "The form was open too long, so nothing was sent. Your answers are kept: please press Submit again." (the refilled page carries a new token). Then the per-IP limit (30 saved applications or 90 submissions per hour: "Too many applications were sent from this network in the last hour…").
1. Every field is filled.
2. Both e-mails are valid; semester, gender and priorities come from the allowed lists; the two priorities differ; DOB is a real past date (`Y-m-d`); Facebook is a URL.
3. `signup_status.is_enabled` is 1. Otherwise: "Applications are currently closed."
4. Duplicates: the same `university_id` **or** `gsuite_email` must not exist in `pending_applications`, `shortlisted_members` or `members`.
5. INSERT into `pending_applications`.

Result: a session flash (`signup_success` / `signup_error`) and redirect to `index.php#signup`. On error, `signup_old` refills every field, including both department selects. DB errors are logged, never shown to the applicant.

### 6.2 Shortlist / Reject / Accept / Delete (`frontend/handle_application.php`)
POST: `action`, `member_id`, `source` (`pending`|`shortlisted`, only for reject), `redirect_to` (whitelist: the three list pages). It always redirects back with `?success=`, `?warning=` or `?error=`. The list pages show these as dismissible alerts.

- **shortlist**: in one transaction, `SELECT … FOR UPDATE` the pending row, copy it to `shortlisted_members` (keeping `created_at`), delete it from pending.
- **reject**: deletes only from the table named by `source`. IDs are independent per table, so the old "delete id N from both tables" behaviour removed unrelated people.
- **accept** (final_approve permission): in one transaction, lock the shortlisted row, refuse if `members` already has the same university ID / e-mail / G-Suite, insert into `members`, delete from shortlist, commit. **Then**, outside the transaction:
  1. Send the congratulations e-mail (§6.3) to the G-Suite address plus the personal address if different. Logged to `backend/logs/email.log`.
  2. `sendToGoogleSheets()` POSTs JSON `{Name, ID, G-Suite, Position(=department), Facebook, Phone, FirstP, SecondP}` to `GOOGLE_SHEETS_WEBAPP_URL`. Logged to `backend/logs/google_sheets.log`.
  3. If both worked: success message listing recipients. Otherwise: a yellow warning "Member accepted and saved to database. Warnings: …". Acceptance never depends on e-mail or Sheets.
- **delete_approved** (manage_site: Super/Panel Admin): delete from `members`.

Confirmation dialogs use `confirm()` with text produced by PHP `json_encode` (safe for names containing quotes).

### 6.3 Congratulations e-mail (`backend/includes/email_template.php`)
Subject: "Congratulations! Welcome to BRAC University Cultural Club". HTML + plain-text versions, UTF-8, all values HTML-escaped.

Layout (620px wrapper, Georgia serif body, Arial for labels):
- Header: gradient `135deg #1a1a2e → #16213e → #0f3460`, white "🎉 Congratulations!", subtitle `#b8ccee` "BRAC University Cultural Club — Official Membership Confirmation", top radius 8px.
- Greeting "Dear **{full_name}**", then a green box (`#f2f8f2`, 4px left border `#2e7d32`) with the selection message.
- "YOUR APPLICATION DETAILS" table: Full Name, University ID, Department, First Priority, Second Priority. Section titles: 11px uppercase, letter-spacing 1.5px, color `#0f3460`, bottom border `#dde3ed`.
- "STAY CONNECTED": navy buttons (`#0f3460`, white, radius 4px), two per row: Official Website (bucuc.org), Facebook Page (facebook.com/bucuc), Facebook Group (facebook.com/groups/86555568937), Instagram (instagram.com/bucuclive), then full-width YouTube (@bracuniversityculturalclub717).
- Orientation paragraph; **venue box** (only when a venue exists): `#fffbf0`, left border `#e6a817`, title color `#7a5c00`, rows Venue / Location / Date (`F j, Y`) / Time (`start - end AM/PM`).
- Red "Please note: Attendance … is mandatory" box (`#fff5f5`, border `#c0392b`), Messenger-group paragraph, tagline "Work • Bond • Glow" (italic, letter-spacing 3px, navy), sign-off "Secretary of Human Resources, BRAC University Cultural Club, hr.bucuc@gmail.com | bucuc.org".
- Footer `#f5f5f5`, 12px grey: "This is an automated email…".

### 6.4 Other admin flows
- **Forms keep what was typed after an error** on Set Venue, Dashboard Updates, Homepage Stats, My Profile and Website Media (person, event and tenure forms; a photo has to be chosen again). Pop-up messages on Set Venue, Pending Applications and the board pages come from one script, `js/admin-toast.js`.
- **Set Venue** (`set_venue.php`): one form (name, location, date, time, start, end, AM/PM). If a row exists the newest row is UPDATEd, otherwise INSERTed. The table lists all rows, each with an AJAX Delete (`delete_venue.php`). Start must be earlier than end (compared numerically as H:MM).
- **Export Excel** (`export_members_excel.php?status=`): `Pending` → pending_applications, `Shortlisted` → shortlisted_members, `Accepted` → members. Columns A–O: Full Name, University ID, Email, G-Suite Email, Department, Phone, Semester, Gender, Date of Birth, Facebook URL, First Priority, Second Priority, Status, Application Date, Last Updated. Header fill `#2E86AB`, bold white; **every cell is written as plain text** (leading zeros kept, and a name or link starting with `=` can never run as a formula); summary block below the data ("Exported By" says "an admin" when the Super Admin exported). Values sent to the Google Sheets (accepted-members sheet and member directory) that start with `=`, `+`, `-` or `@` get a leading `'` for the same reason. File name `{Status}_Members_YYYY-MM-DD_HH-MM-SS.xlsx`. An empty list redirects back with an error.
- **Clear all** (pending page, manage_site): the modal requires typing `CLEAR ALL APPLICATIONS`. The endpoint archives every row of all three tables to `backend/logs/cleared_applications.log` (one JSON line), deletes in a transaction, then resets AUTO_INCREMENT.
- **Clear shortlist** (shortlisted page, manage_site): requires `CLEAR ALL SHORTLISTED`. Archives to `backend/logs/cleared_shortlisted.log`.
- **Signup Control**: select Enable/Disable → updates `signup_status`. Disabled: the homepage hides the form and the "Apply" header buttons, and shows "Application Form expired for current Semester". The handler also refuses submissions.
- **Dashboard Updates** (back link goes to the admin's own dashboard): base member count, completed events, Member Growth points (add/remove rows; at least one), manual gender split. Saved in one transaction: INSERT a `dashboardmanagement` row, replace all `chart_data` `member_growth` rows.
- **Admin Management**: rules in §5. POST `action` = `add_admin` (username, email, role from the vacant roles you may create, password ≥ 8 + confirm), `update_admin` (name, e-mail, role, status, optional new password), `toggle_status` (activate/deactivate), `delete_admin`, `save_permissions` (`perms[]`). Every action reloads the target, checks rank on the server and is logged. Deactivating signs that admin out on their next click and blocks their login ("This admin account is inactive…") until reactivated. If the Super Admin changes their own password here, their session stays signed in.
- **My Profile**: change own username/email/password (≥ 8, confirmed). Changing the password **or** the e-mail requires the **Current Password** field. Updates the session; other browsers signed in to the same account are signed out after a password change.

### 6.4a Accepted-members Google Sheet (must keep updating)
The club's existing accepted-members Google Sheet stays the official record and keeps receiving every new member.
- **Target:** the Apps Script web app in `GOOGLE_SHEETS_WEBAPP_URL` (`…AKfycbyPKki88…/exec`), the one in `backend/config/google_sheets_config.php`. This URL logged successful writes through December 2025.
- **When:** right after an admin with final approval clicks **Final Approve** and the member is saved to `members` (`handle_application.php` → `sendToGoogleSheets()`).
- **Payload** (JSON POST, one row per member): `Name` = full_name, `ID` = university_id, `G-Suite` = gsuite_email (falls back to email), `Position` = department, `Facebook` = facebook_url, `Phone` = phone, `FirstP` = firstPriority, `SecondP` = secondPriority. The sheet's column headers must keep these names.
- **Success** = HTTP 200 with `{"success": true}` (or plain text). `{"success": false}` or `{"ok": false}` count as failures. A failure never blocks the approval; the admin sees a yellow warning, and the attempt is logged to `backend/logs/google_sheets.log`.
- An older hardcoded fallback URL (`…AKfycbyktCtz…`) was removed on purpose; don't bring it back. If the Apps Script is ever redeployed and gets a new URL, update only `GOOGLE_SHEETS_WEBAPP_URL`.
- This is separate from the SB/ASB/GB directory sheet (`MEMBER_SHEET_CSV_URL` / `MEMBER_SHEET_GAS_URL` in `frontend/includes/member_board.php`), which also stays as is.

### 6.5 Dashboard numbers (`backend/includes/dashboard_stats.php`)
| Card | Source |
|---|---|
| Total Members | newest `dashboardmanagement.totalmembers` + `COUNT(members)` |
| Pending Applications | live `COUNT(pending_applications)`; "Needs Attention" (yellow) when > 0, else "All Reviewed" (green); links to the pending page |
| Completed Events | newest `dashboardmanagement.completedevents` |
| Shortlisted Members | live `COUNT(shortlisted_members)`; links to the shortlist |
| Member Growth (line) | `chart_data` points; if none, "No growth data yet" |
| Gender (doughnut) | manual `gd_male`/`gd_female` if either > 0, otherwise live counts of `members.gender`; if all zero, "No gender data yet" |

The sidebar "Applications" item shows a yellow badge with the pending count when > 0.

### 6.6 Admin accounts and security (`backend/includes/auth.php`)
- **Login** (`admin-login.php`): POST `adminEmail`, `adminPassword`, `csrf_token`. E-mail compared lower-case. Order: CSRF → both filled → throttle → verify. Wrong e-mail or password both say "Invalid email or password." (no hint which). Correct password on an inactive account says it's inactive. Success redirects straight to the role's dashboard (no interstitial) and re-hashes the password if PHP's default algorithm changed. Visiting the login page while signed in goes to the dashboard. Below the button: **Forgot password?** then Back to Main Site. A blue notice box shows flash messages (session expired, account deactivated, password changed).
- **Throttle**: 5 failed logins for one e-mail, or 20 from one IP, within 15 minutes lock further tries: "Too many failed attempts. Try again in N minutes, or reset your password." A successful reset clears the lock.
- **Forgot password** (`forgot-password.php`): enter an e-mail → always the same answer ("If {email} belongs to an active admin account, a reset link is on its way from club.bucuc@g.bracu.ac.bd. It expires in 30 minutes…") so the form can't reveal which addresses are admins. Only for an existing **active** admin: at most 3 links per account and 10 per IP per hour; a 64-hex-character random token is e-mailed as `APP_URL/frontend/reset-password.php?token=…`; only its sha256 is stored; older unused links for that admin are voided.
- **Reset** (`reset-password.php`): invalid/used/expired token → "This reset link is invalid, already used, or expired. Request a new one." Otherwise new password + repeat (≥ 8). On success: password updated, all of that admin's open links used up, login lock cleared, a "Your BUCuC admin password was changed" notice e-mailed, redirect to login with "Your password was changed. Log in with the new password." The page has `<meta name="referrer" content="no-referrer">` so the token doesn't leak to other sites.
- **E-mails** (`auth_mail.php`): white card, aurora line on top, colour logo from `APP_URL/frontend/images/logopng.png`, title, body, footer "BRAC University Cultural Club · admin panel"; reset e-mail has a purple pill button "Choose a new password" plus the raw link. Plain-text alternative included. Sent from `AUTH_FROM_NAME <AUTH_FROM_EMAIL>`; result logged to `backend/logs/auth_email.log`. Failures are never shown to the visitor.
- **Session**: cookie `HttpOnly`, `SameSite=Lax`, `Secure` (always on the live site, which is HTTPS-only), strict mode. After **30 minutes** without a request the admin part of the session is cleared: "Your session expired after 30 minutes of inactivity. Please log in again." **12 hours after logging in** the admin must log in again even if active ("For security, you are signed out 12 hours after logging in…"). Logout is a POST with the CSRF token (the sidebar Logout opens `logout.php`, which shows a "Log out" button; a link from another site can't sign anyone out); it logs the event, empties the session and deletes the cookie.
- **Password fingerprint**: `pw_fp` = first 32 hex chars of sha256(`bucuc-session|` + password hash). If the stored hash changes (reset, Admin Management, Profile), every other session for that admin no longer matches and is signed out ("Your password was changed. Please log in again."). The page that made the change calls `auth_refresh_fingerprint()` to keep its own session.
- **CSRF**: one random token per session. `require_admin()` wraps HTML output in a buffer that inserts `<input type="hidden" name="csrf_token">` after every `<form … method="post">` and `<meta name="csrf-token">` before `</head>`, so forms need no edits. fetch() calls send it as the `X-CSRF-Token` header (pending: Clear All; shortlist: Clear List; Set Venue: delete; board pages: GAS proxy). A missing/wrong token: HTML pages redirect to the dashboard with "That form had expired. Please try again."; JSON endpoints answer `419`. The login, forgot and reset forms carry the token explicitly.
- **Super Admin's name is never shown to other admins**: Activity Log, Email Notices ("started by", "By"), Site Colours ("saved by", history, restore log) and the Excel "Exported By" line show **"an admin"** instead (`admin_shown_name()` in roles.php; the actor's role is stored with each record).
- **Duplicate account message**: adding or editing an admin (or My Profile) with a username or e-mail already in use says "That username or e-mail can't be used. Choose a different one." without saying whose it is.
- **Activity log**: `log_activity($action, $details)` never throws. Codes: `login`, `login_failed` (wrong password / inactive account; only for real admin e-mails), `logout`, `password_reset_requested` (sent / failed / blocked), `password_reset`, `profile_updated`, `admin_added`, `admin_updated`, `admin_deleted`, `admin_activated`, `admin_deactivated`, `application_shortlisted`, `application_rejected` (with list), `member_accepted` (with e-mail/sheet result), `member_deleted`, `cleared_all_applications` (counts), `cleared_shortlist`, `venue_created`, `venue_updated`, `venue_deleted`, `dashboard_updated`, `signup_toggled`, `exported_excel` (list + rows), `board_member_deleted`, `login_blocked` (right password but no role / lockdown), `permissions_changed` (+/− list), `member_promoted`, `member_demoted`, `carousel_photo_added`/`_removed`, `panel_member_added`/`_updated`/`_removed`, `event_added`/`_updated`/`_removed`, `hero_video_changed`/`_reset`, `lockdown_scheduled`/`_cancelled`/`_lifted`, `super_recovered` (§16), `gallery_video_added` / `_updated` / `_hidden` / `_shown` / `_deleted` (§6.12), `attendance_session_created` / `_updated` / `_deleted`, `attendance_changed`, `attendance_checkin_opened` / `_closed` / `_removed` (§6.13), `handover_edited` / `_restored` / `_task_done` / `_unlocked` / `_locked` / `_exported` / `_end_date_set` / `_reminder_sent` (§6.14). Each row stores **who** (admin + role), **what** (action + details), **to whom / what** (target, e.g. "Gina Body (21100001)", "Sec Tester <sec@…>", "Whole website") and **when**. Details hold names and university IDs, not passwords or tokens. Every admin can open the log; Super Admin rows are hidden from everyone else (§5).

### 6.7 Member promotion / demotion (SB / ASB / GB pages, `frontend/includes/member_board.php`)
Member flow: **GB → ASB → Secretary Panel (SB)**, one step at a time. Levels are stored in the database (`members.position`); the club's Google member sheet is updated alongside (best effort).
- **Lists**: each page (`gb_members.php`, `asb_panel.php`, `sb_members.php`) lists the members at its level from the database (name, student ID, G-Suite). Stats: count at this level, all levels "GB · ASB · Secretary Panel", load time. New members approved through the website start at GB.
- **Import from Google Sheet** (manage_site: Super / Panel Admin; button on every board page, with confirm): downloads the published CSV (`MEMBER_SHEET_CSV_URL`; columns name, student ID, G-Suite, position "… ~ GB" or "GB") and, per student ID, updates the level of an existing member or adds a new one. Rows without name/ID/valid level, or whose e-mail belongs to someone else, are skipped and counted. Logged as `members_imported`.
- **Moving**: each row has a select with the current level plus only the moves this admin may make ("Promote to ASB", "Demote to GB", …) or "No permission to move". It POSTs JSON `{action:'update', id, position}` with `X-CSRF-Token` to `?action=gas_proxy` on the same page. The server loads the member, refuses anything that isn't one step (400), checks `promote_gb_asb` (GB↔ASB) or `promote_sp` (ASB↔SB) (403), updates the row only if nobody changed it meanwhile (409 otherwise), then sends `{action:'update', studentId, position}` to the Apps Script (`MEMBER_SHEET_GAS_URL`). A sheet failure doesn't undo the move: the admin sees "Saved, but …" and the log entry notes it. Logged as `member_promoted` / `member_demoted` ("GB → ASB") with the student as target.
- **Remove** (manage_site): `{action:'delete', id}` deletes the member row and asks the Apps Script to delete the sheet row. Logged as `board_member_deleted`.
- `MEMBER_SHEET_CSV_URL` / `MEMBER_SHEET_GAS_URL` use `defined() || define()` so a test setup can point them at fakes.
- Note: the dashboard "Total Members" card adds `COUNT(members)` to the manual base count, so after importing the sheet lower the base count in Dashboard Updates to avoid counting people twice.

### 6.8 Website Media (`frontend/media.php`)
Three tabs, each shown only with its permission; every POST re-checks it. Accepted image types: JPG, PNG, WebP, max 8 MB. Ratio tolerance ±2 %. **The pixel sizes are maximums**: the server refuses bigger images ("too big … Resize it") and accepts smaller ones if the ratio is right.

**Built-in crop and resize** (`js/image-crop.js` with Cropper.js 1.6.2 in `frontend/vendor/cropperjs/`, styles `css/image-crop.css`). Every image upload (Presidential Panel, Secretary Panel / carousel, Past Events) works like this when a photo is picked:
- **Wrong ratio** → a crop window opens with the frame locked to that area's ratio and its rule shown ("4:5 portrait, at most 800 × 1000 px"); the admin drags and zooms (mouse wheel, + / − buttons, or pinch and drag on a phone) and sees "Result: W × H px" live. **Use this crop** or **Cancel** (Esc); cancelling clears the photo ("Not added: this photo must be cropped to 4:5…").
- **Too big** (after cropping, or a right-shaped photo) → scaled down to fit the maximum, keeping the exact ratio. Never enlarged.
- **Already right** → uploaded unchanged; a **Crop** button lets the admin adjust it anyway.
- **Very small** (below the rule's `warn` size: panel 320 × 400, secretary 300 × 400, event 750 × 500) → "Small for its place on the site… it may look blurry. You can still upload it."
- A preview shows the final image with its final size, format and file size ("800 × 1000 px · JPEG · 214 KB · cropped, scaled down from 3024 × 3780").
- Saved as JPEG 88 % for JPEG photos, WebP 88 % for PNG / WebP (PNG if the browser can't make WebP). Photos over 8 MB are fine when picked because they are re-saved smaller.
- The ratios, maximums and warn sizes come from `MEDIA_IMAGE_RULES` in `site.php` (printed into the page by `media_rules_js()`), the same values the server checks: change them there only. A new upload area only needs `<input type="file" data-rule="KEY">`, the rule in `MEDIA_IMAGE_RULES`, and the three files + `window.MEDIA_RULES` on its page.
- **The server still checks** real type, size and ratio of whatever arrives (`media_check_image()`); the crop tool is only a convenience. The hero video has no crop tool.

| Upload | Rule (matches the homepage layout) |
|---|---|
| Presidential Panel photo | **4:5 portrait, at most 800 × 1000 px** (panel card photo box is `aspect-ratio: 4 / 5`) |
| Secretary Panel photo | **3:4 portrait, at most 750 × 1000 px** (carousel slides are 250 × 330) |
| Past event photo | **3:2 landscape, at most 1500 × 1000 px** (cropped into the homepage card and the event page banner) |
| Hero video | **MP4 with H.264 video, 16:9, 1280 × 720 to 1920 × 1080, ≤ 24 s, ≤ 6 MB** (from the current video: H.264 1280 × 720, 23.3 s, 5.8 MB). Duration, size and codec are read from the MP4 boxes by `mp4_inspect()` |

**Panels & Tenures tab**
- Tenure chips (current one marked) select which tenure to edit; "New tenure" takes a label `YYYY-YY` (end ≠ start, unique; the box suggests the year after the newest) and an optional "Make it the current tenure".
- Per tenure: **Make current** (exactly one; the homepage shows it first) and **Delete tenure** (not the current one; confirm).
- **Presidential Panel** and **Secretary Panel** sections: add form (name, position with suggestions, optional Facebook URL, photo), tiles with Edit (change details, optionally a new photo) and Remove. Photos are saved as `images/tenures/{label}/{panel|secretaries}/{slug}-{timestamp}-{random}.ext`.
- **12-tenure limit** (`TENURE_LIMIT`): with 12 tenures, the page says which tenure the next one will delete (the oldest by start year, never the current one) and the Create button asks to confirm by name. Without a matching `confirm_delete` the server shows a red "Delete tenure {label}?" panel and saves nothing; after confirming it deletes the oldest tenure (both panels, all its photo files) and saves the new one. Logged as `tenure_deleted` ("Automatic: 12-tenure limit …", people and files counted) and `tenure_created`.
- Every change rewrites `backend/Api/members.json` from the database (`tenures_export_json()`), so `get_members.php` shows the same panels.
- **Empty current tenure**: while the current tenure has nobody in it, the homepage (and `members.json`) keep showing the newest tenure that has people as "Current Panel (…)"; tenures without people are left out of the year select. Website Media says so on the empty tenure ("…the homepage still shows tenure 2026-27…"). As soon as one person is added, the new tenure takes over.
- **Delete order**: rows are deleted in a transaction first and photo files only after it commits; a newly uploaded file is deleted again if saving its row fails, so no orphan files and no rows pointing at missing files.
- First run: `tenures_seed()` copies every year of `members.json` into the tables (labels become `2026-27` style; the current-year secretaries come from the old photo folder scan, §9.2); the first year becomes current.

**Deleting removes files**: removing a person, an event, a tenure, or replacing a photo or the hero video deletes the database row **and** the file (`media_delete_file()`), but only when no other tenure person, event, built-in event or the hero setting still uses that file, only inside `frontend/images` / `frontend/video`, and never the original `video/bucuc1.mp4` / `.webm`. Each deletion is logged (details say "photo file deleted").

**Hero video** can't be deleted on its own, only replaced: "Upload & replace" (the previous upload is deleted) or "Replace with the original video" (the upload is deleted and the original plays again), so the hero is never empty.

**Past Events tab**: **every** event, newest first, including the 12 older stories that used to be written into `past_events.html`. Each row has View (opens its story), **Edit** and **Remove**.
- **Fields** (add and edit): title, date (not in the future), "Organised by" (default "By BUCuC"; e.g. "By BUAC"), place, photo (required when adding; when editing blank = keep, with a thumbnail of the current one; crop tool as above), description (blank line = new paragraph), highlights (one per line), **number tiles** (one per line, `number | label`, e.g. `400+ | Attendees`; a line without `|` is refused with the line quoted). Description, highlights and tile labels accept `**bold**` and `*italic*` (`event_rich()`; everything else is escaped). After an error the form keeps what was typed.
- **The older stories**: imported once from `backend/Database/past_events_seed.json` (`events_seed()`, flag `site_settings.events_seeded`), with their original anchors (`#event-6`, `#country-music` …) so old links and QR codes keep working, their photo framing (`image_class`, dropped when a new photo is uploaded) and their bold / italic. Deleted ones are never imported again. New events use `#ev-{id}`.
- **Photo files**: removing an event, or replacing its photo, deletes the photo file only if it was uploaded here (`images/events/…`, `event_photo_deletable()`) and nothing else uses it. The original photos of the older stories are always kept (the homepage carousel uses some of them).
- **Log**: `event_added`, `event_updated` (says what changed, e.g. "Changed: title, number tiles, new photo"), `event_removed`.
- Homepage event cards = the 5 newest events (`site_event_cards()`).

### 6.9 Site lockdown (`frontend/lockdown.php`, Super Admin)
A lockdown takes the **public website** offline; the **admin panel keeps working for every admin**.
- **Schedule**: start date/time (Dhaka) or "Lock right now", a **notice** (required; shown to admins and on the maintenance page) and an optional different message for visitors. A new lockdown replaces a scheduled one.
- **Before the start**: every admin page shows a fixed notice at the bottom: "⚠ The public website goes offline on {date} — {live countdown}" + the notice (closable for that page view; back on every load; reloads the page at zero). No author is shown.
- **From the start time** (automatic, checked on every request): public pages (`index.php`, `past_events.php` and the old `.html` link, `performances.php`, `signup_handler.php`, `backend/Api/get_members.php` as JSON) answer **503** with an Aurora "Down for maintenance" page showing the notice (or the visitor message), "We'll be back soon. — BRAC University Cultural Club" and a small Admin login link. Admins can log in, use every page, reset passwords as usual; their banner says "🔒 The public website is offline (lockdown since …). The admin panel works as normal." (+ Manage for the Super Admin). A signed-in admin who opens the public site sees it normally with a red ribbon "Admin preview: the public website is offline for visitors".
- **Cancel** a scheduled lockdown or **Lift** an active one at any time (one button, with confirm). Recent lockdowns are listed. All changes are logged (visible to the Super Admin only).

### 6.10 Homepage stats (`frontend/home_stats.php`; Super, Panel, HR)
Four numbers in the About section of the homepage (white cards, gradient numbers, 4 across, 2 across on phones): **Members to date**, **Shows organized**, **Applicants · {semester}**, **Rejections · {semester}**.
- **All typed in by hand**; nothing is calculated from the database. The page has one number box (whole number, required) and a **Show** tick per stat, plus one **Semester label** box (e.g. "Summer 2026") added after "Applicants" and "Rejections" (blank = no label).
- Stored in `site_settings.home_stats` as JSON `{"members": {"value": 520, "visible": true}, …, "semester": "Summer 2026"}`.
- Every save is logged as `home_stats_changed` with old and new values ("Members to date: 520 (was 0); Rejections hidden; Semester label: "Summer 2026" (was "")").
- `application_events` still records every application (`applied`) and rejection (`rejected`, with the application date) for internal use; it is not shown on the homepage.

### 6.11 Email notices (`frontend/notices.php`, permission `send_notices`)
- **Sender by role** (`backend/config/mail_accounts.php`): Super Admin and Panel Admin send from **club.bucuc@g.bracu.ac.bd**, Secretary Admin (HR) from **hr.bucuc@gmail.com**, MIAP / Secretary Admin (if given the permission) from the club account until decided. The page shows "Will be sent from {address} · limit N e-mails a day · M sent from this address in the last 24 h" before sending (red, with Send disabled, if that account has no App Password). Passwords are read from git-ignored files and never shown.
- **Recipients**: All members, a group (GB / ASB / Secretary Panel), or picked members (searchable tick list). Each member gets the notice at **both** their personal e-mail (Gmail; `members.email`) and their G-Suite address (`members.gsuite_email`), or the one they have; members with neither are skipped and counted. Import placeholders (`…@members.bucuc.local`) are never e-mailed. One e-mail per address. Counts show "N members · M e-mails".
- **Message**: subject and body are pre-filled with the default template ("Notice from BRAC University Cultural Club" / "Dear {name}, … Work • Bond • Glow"), editable, with click-to-insert placeholders `{name}`, `{first_name}`, `{student_id}`, `{level}` and "Restore the default template". Branded HTML layout plus a plain-text copy.
- **Sending**: PHPMailer (through `mailer.php`) over SMTP from the notice's sender account (fixed when the notice is created, also when someone else resumes it). The page requests one batch at a time (`?action=batch`, 25 e-mails, one SMTP connection per batch) and shows Recipients (e-mails) / Sent / Failed / Waiting. **Daily limits are per account and count every e-mail the account sent in the last 24 h** (notices plus password and congratulations e-mails): club 1,500, HR 400 (Gmail allows about 500); when reached, sending pauses with "Come back tomorrow and press Resume". Failed addresses are listed with the error and a **Retry the failed ones** button. "Sent notices" lists the last 15 with e-mails sent / failed, From and By.
- **Log**: `notice_sent` when started (sender admin, audience, e-mails, members, sending address, subject) and `notice_finished` when done (sent / failed, sending address). Errors also go to `backend/logs/auth_email.log`.
- **Sheet import** (§6.7) fills `members.email` from a Gmail / personal e-mail column when the sheet has one (found by its header); existing real addresses are never overwritten.

### 6.12 Performance gallery (`frontend/video_gallery.php`, permission `manage_gallery`; public page §7.3)
YouTube videos only: the gallery stores the video ID and its details, never a video file.
- **Adding**: paste a link. Accepted: `youtube.com/watch?v=`, `youtu.be/`, `youtube.com/shorts/`, `/embed/`, `/live/`, `m.` / `music.` hosts, `youtube-nocookie.com/embed/`, with any extra parameters (`t=1m30s` / `t=90` / `start=` set the start time; `list=`, `si=`, `feature=` are ignored). Anything else, including lookalike hosts, is refused: "That isn't a YouTube video link…". `youtube_parse()` in `backend/includes/gallery.php` does this.
- **Check with YouTube**: the page checks the link as soon as it is pasted (or with **Check**), and the server checks it again when saving. It calls YouTube's oEmbed endpoint (`GALLERY_OEMBED_URL`, no API key) and fills in the title and thumbnail. Private or embedding-disabled videos (HTTP 401/403): "YouTube won't let this video be shown on other websites: it is private, or its owner has turned off embedding…". Deleted videos (400/404): "This video doesn't exist any more, or it is private…". YouTube unreachable: "Couldn't reach YouTube…". In each case nothing is saved.
- **Details**: title (pre-filled, editable, max 150), category (Music Video, Flash Mob, Show Highlight, Other), tenure (optional, the current one pre-selected) and/or date (optional), short description (optional, max 600), **Featured** (pinned to the top), **Vertical video (Short)**. Vertical is ticked automatically for `/shorts/` links or when YouTube reports a tall video; the admin can change it.
- **Preview**: a card exactly as on the public page (shared `css/gallery.css`), updated live as the fields change.
- **Duplicates**: if the same video ID is already in the gallery, the check says "⚠ Already in the gallery: …" and saving shows a yellow box with **Add it again anyway** / Cancel.
- **Managing**: list in public order (featured first, then position) with thumbnail, title, tags (category, Featured, Short, Hidden), date / tenure, YouTube link. Per video: **Edit** (also the link, which is then re-checked), **Hide / Show**, **Delete** (confirm; the video stays on YouTube). **Order**: drag a row by its handle (desktop; saved at once, "✓ Order saved"), or type a position number (works on phones). New videos go to the top.
- **Log**: `gallery_video_added`, `gallery_video_updated` (says what changed, e.g. "now featured"), `gallery_video_hidden`, `gallery_video_shown`, `gallery_video_deleted`, each with the video title as the target.

### 6.13 Attendance (`frontend/attendance*.php`, permission `take_attendance`; `backend/includes/attendance.php`)
Super, Panel and HR by default; MIAP and Secretary Admin see nothing about attendance unless granted the permission (also not the percentages on the member pages). Times are Dhaka time.
- **Sessions** (Attendance → New session): title, type (Rehearsal, Meeting, Event, Workshop, Other), date, start time, optional end time, who is expected (All members, GB, ASB, Secretary Panel, or picked members from a searchable tick list), and "Allow QR self check-in". The expected members are saved with the session. Edit and Delete on the session page; deleting asks for confirmation and removes its marks too; changing who is expected removes the marks of members no longer expected. The sessions list (filter by semester) shows status (Upcoming / Today / In progress / Check-in open / Ended), expected and the totals. `event_id` is kept for linking sessions to the future events calendar.
- **Ended**: at the end time, or at midnight when there is none. After that, **every change** (marks, removing a check-in, editing the session) needs a short reason (5+ characters) and is logged as `attendance_changed` / `attendance_checkin_removed` / `attendance_session_updated` with who changed it, the reason and each change ("Nora Gmailonly (21100005): Absent → Excused"). Changes while a session is running are not logged one by one.
- **Checklist** (`attendance_session.php`): totals (Present, Late, Excused, Absent, Not marked yet, Expected), search box, filter chips (All / Not marked / Present / Late / Excused / Absent), one row per expected member with a 4-way switch (big tap targets; on phones the switch is full width with P / L / E / A), **Mark all present** (members shown who are not marked or absent become present; late and excused stay), a sticky save bar ("3 changes not saved yet", the reason box after the end, Save). Only the rows the admin changed are saved, so QR check-ins that arrive while the page is open are not overwritten. Leaving with unsaved changes asks first.
- **QR self check-in** (`attendance_checkin.php`, for a projector or laptop): Start (open for 10–120 minutes, default 30) → a QR code with a link to `checkin.php?s={session}&t={code}`; the code changes every **30 seconds** (HMAC of the session and the 30-second window with the session's secret; progress bar "New code in N s"). A scanned code is accepted for its own window and the two before it (about 90 seconds), so a photo of the code sent to someone outside stops working quickly. After a valid scan the member has **5 minutes** to type their student ID. Names appear live (count "N of M expected checked in"), each with Remove (asks for a reason after the end). End check-in closes it; it also closes by itself at the chosen time.
- **Member side** (`checkin.php`, no login, mobile page): student ID → "Checked in, Asha! You're marked Present." (or **Late** when more than 15 minutes after the start). Refused: closed check-in, expired code, ID not on this session's list, an ID already marked ("You are already marked for this session"), **a phone that already checked someone in for this session** (cookie), and after 30 wrong IDs from one network in 10 minutes. Not closed by a site lockdown (it is an attendance tool, not part of the public website).
- **Percentages**: present and late count as attended; excused sessions are left out of the count; a member not marked at an ended session counts as absent; sessions that haven't ended don't count. % = attended ÷ (sessions − excused). Semesters: Spring (Jan–Apr), Summer (May–Aug), Fall (Sep–Dec).
- **Members report** (Attendance → Members report): semester (or all time), level, "Only below N %" (link: "Show members under 50%"), search; per member: sessions, attended, late, excused, absent, the semester's % (rose under 50 %) and overall %. Name → the member's page (overall, by semester, every session with its status and QR / not-marked notes).
- **Member pages** (GB / ASB / Secretary Panel, the promotion screen): under each name "Attendance 78% · Fall 2026: 80%", linking to the member's attendance (only for admins with `take_attendance`).
- **Excel** (`attendance_export.php`): one session (title, details, totals, then name, student ID, level, status, QR / admin, time) or the members report with the same filters. Text cells are written as text (formula-safe, as in §6.4); counts and percentages are numbers. Logged as `exported_excel`.
- **Log**: `attendance_session_created` / `_updated` / `_deleted` (with the number of marks deleted), `attendance_changed`, `attendance_checkin_opened` / `_closed` / `_removed`.

### 6.14 Handover notes (`frontend/handover.php`, every admin; `backend/includes/handover.php`)
Each tenure has one handover document that the outgoing panel writes for the next one.
- **Sections** (each with a short guiding prompt): 1 Summary of the year (text) · 2 **Contacts** (table: name, organisation, their role, phone / e-mail, notes on the relationship; searchable) · 3 What worked · 4 What didn't work · 5 **Pending tasks** (table; each row can be ticked done, showing who (role) and when) · 6 Yearly calendar (table: when, what, notes) · 7 **Accounts and access** (table: account, used for, who holds access now, notes) with a red warning "Never write passwords here…" · 8 Useful links (table: title, http(s) link, notes) · 9 **Role-specific notes** (one space per admin role).
- **Who**: every admin reads every tenure's notes and edits the current tenure's. Contacts need `handover_contacts` (Super, Panel, HR by default; hidden everywhere without it: page, search, history, PDF, "empty sections" list). Each admin edits only their own role's space (the Super Admin can edit all); the Super Admin's space is hidden from everyone else. Authors are shown by **role**, never by name ("Last edited by Secretary Admin (HR) · 20 Oct 2026, 3:12 pm"); the Super Admin shows as "an admin" to others.
- **Editing**: one section at a time (Edit → textarea, or a grid of rows with "Add a row"; emptying all boxes of a row removes it). Saving a section that someone else saved meanwhile is refused ("Someone else saved this section while you were editing it…") and the typed text is kept. Passwords are refused anywhere in the notes (text like "password: …", "pwd=…", "pin - …"), and links must be http(s).
- **Locking**: only the current tenure's document is editable. As soon as another tenure is marked current, the old one is read-only (forged edits refused). The Super Admin can **Unlock for editing** / **Lock again** an old document. Pending tasks can be ticked done on any document (that is the next panel's job).
- **Kept forever**: the 12-tenure rule and deleting a tenure remove its photos, never its handover notes (the document stays, marked "Tenure removed; notes kept"; a tenure created later with the same label gets it back).
- **History**: every save is a version (who (role), when, note). History lists them, each viewable, and **Restore this version** saves the old text as a new version (nothing is ever lost).
- **Search** (box at the top): finds text in every tenure's notes (e.g. a sponsor's name), with the tenure, section and a highlighted snippet; Bangla works.
- **PDF**: `handover_print.php` is a plain black-on-white print page (only the sections this admin may see) that opens the print dialog; choose "Save as PDF". The browser does this instead of a PHP PDF library because it lays out Bangla correctly and needs no extra library.
- **End date and reminder**: the Super Admin or the Panel Admin sets "This tenure ends on" on the current document. About **30 days before**, every active admin gets one e-mail (club account, counted in its daily limit) listing the still-empty sections and their own empty role space, with a link. It is sent when any admin opens a dashboard (after the page is delivered) or by `backend/scripts/handover_reminders.php` from a daily cron job; changing the date allows a new reminder.
- **Dashboard**: a card "Handover notes · Tenure 2026-27: N sections are still empty: …" (gold border while any are empty), linking to the notes.
- **Log**: `handover_edited` (section and size), `handover_restored`, `handover_task_done`, `handover_unlocked`, `handover_locked`, `handover_exported`, `handover_end_date_set`, `handover_reminder_sent`.

### 6.15 Sponsor banners (Super Admin only; `frontend/sponsors.php`, `backend/includes/ads.php`)
**For every other admin this feature does not exist.** `sponsors.php` and `sponsors_export.php` answer the server's ordinary **404 Not Found** page to anyone who isn't the signed-in Super Admin (also logged-out visitors: no login redirect), the "Sponsor Banners" menu item is printed only for the Super Admin, its activity-log labels exist only in the Super Admin's log view, and other admins' log queries exclude `ad_*` entries by name as well as by actor. Nothing on other admins' pages, scripts or API answers mentions it (the crop-tool rules for ad sizes are printed only on `sponsors.php`, not added to `MEDIA_IMAGE_RULES`).
- **Names**: `sponsors.php`, `go.php`, `images/sponsors/`, CSS `.sp-band` / `.sp-tag`, on purpose: ad blockers hide `ad.php`, `/ads/`, `.ad-slot`, `.ad-label`.
- **Spots** (Spots tab, on / off switch each; `AD_SPOTS`): Homepage below the hero (desktop 1200 × 200, phone 600 × 300) · between Secretaries and Past Events (1200 × 300, 600 × 400) · above the footer (1200 × 200, 600 × 300) · Events page (reserved for the events calendar; cannot be switched on yet). A spot that is off, or has no live ad, prints nothing at all (no gap, no message): `ad_slot($spot)` in `index.php` returns ''.
- **Ads** (Ads tab): spot (the form reloads with that spot's sizes), advertiser, link (http/https only), start and end date (Dhaka; live from the start date to the end date inclusive, automatically), Active (untick = paused), banner image and optional phone image, both through the crop tool locked to the spot's ratio and maximum, at most **1 MB** (the server refuses bigger or wrong-size images even if the tool is bypassed). Status chips: Live until … / Starts … / Ended … / Paused. Pause / Resume, Edit (blank image = keep; "Remove the phone image"), Delete (confirm; deletes the images and stats). Several live ads in one spot: a random one on each page load.
- **On the page**: `<aside class="sp-band">` with a small "Sponsored" label, `<picture>` (phone image under 600 px wide), `width` / `height` set (no layout jump), `loading="lazy"`, link `go.php?id=…` with `target="_blank" rel="sponsored noopener"`. Not shown on the maintenance page during a lockdown (the public pages stop before rendering).
- **Counting**: a **view** is counted when a page with the banner is built, except for bots and link previews (user agent: bot, crawler, spider, preview, facebookexternalhit, WhatsApp, Telegram, Discord, LinkedIn, headless browsers, Lighthouse, curl / wget / scripts, empty) and signed-in admins. A **click** goes through `go.php?id=…`, which counts it (same exclusions; one per visitor per ad per 10 minutes, by a hash of IP and browser) and redirects (302) to the link stored for the ad; unknown ids go to the homepage, and nothing in the URL can change the target.
- **Stats** (Stats tab): from / to dates (default the last 30 days), per ad: views, clicks, click rate, plus totals. **Report** downloads an Excel file for the sponsor: summary (campaign dates, link, views, clicks, click rate) and one row per day; text written as text (formula-safe), numbers as numbers.
- **Log** (Super Admin only): `ad_created`, `ad_updated`, `ad_paused`, `ad_resumed`, `ad_deleted` (with its totals), `ad_spot_on`, `ad_spot_off`, `ad_report_exported`.

---

## 7. Public pages

### 7.1 `frontend/index.php` — section order
PHP at the top: session flash messages (`signup_success`, `signup_error`, `signup_old`, `signup_ticket`), `IMG_V = filemtime(index.php)`, an output buffer that appends `?v=IMG_V` to every `src="images/…"` and `url(images/…)` (cache busting; re-save `index.php` after uploading new photos), the current-panel secretary folder scan (§9.2), the panel data from `members.json` (§9.1), and `signup_status`. Stylesheets: Bootstrap 5.2 (grid, carousel, tabs only), Swiper, Font Awesome, `css/aurora.css`, `css/site.css`. `js/aurora-sky.js` runs right after `<body>`; jQuery + Bootstrap + `click-scroll.js` + Swiper + `js/site.js` at the end, with `window.BUCUC_PANEL_YEARS` (all panel years as JSON) printed just before `site.js`.

1. **Navbar** `#mainNavbar` (fixed): logo + equalizer bars + "BUCuC"; 7 links in section order (Home `#section_1`, About `#section_2`, Advisors `#advisors_section`, Panel `#section_3`, Secretaries `#section_4`, Past Events `#section_5`, Contact `#very-bottom`; keep this order for `click-scroll.js`, which highlights links by position), then **Videos** → `performances.php` (always last, so it doesn't shift the positions; also in the drawer and the footer "Links"); between 992 and 1199 px the links are slightly tighter so all fit on one line; **Login** ghost button and **Apply Now** gradient button (only when signup is enabled). Transparent at the top; after 30px of scroll it becomes dark glass (`.scrolled`). Below 992px the links collapse into a hamburger (`#sidebarToggle`) that opens a right-hand glass **drawer** (`#mobileSidebar`, overlay `#mobileSidebarOverlay`, closes on link click, overlay click or Esc) with the same links plus Apply Now and Admin Login.
2. **Hero** `#section_1`, two columns (stacked and centred below 992px):
   - Left: the **vinyl planet** `#system` (spinning record with the club logo on a warm label, 5 tilted coloured staff rings, 4 music notes ♪ ♫ ♩ ♬ orbiting in 3D), the title "BRAC University / Cultural Club" (Syne 800) with "Work • Bond • Glow" below, a one-line lead, **Apply Now** + **Past Events** buttons, and "Follow us" with 4 round icons (Facebook bucuc, BUCuC Archive, YouTube, Instagram).
   - Right: a glass **screen** with the club video (`video/bucuc1.mp4` H.264 1280×720 first, `video/bucuc1.webm` AV1 1920×1080 as fallback; muted autoplay loop; 16:9 frame with `object-fit: contain`, so nothing is cropped), a "Sound off/on" pill `#unmuteBtn` that toggles audio, and a caption bar "BUCuC live · BRAC University, Dhaka". When less than 20% of the hero is visible the video pauses and mutes; on return it resumes, with sound only if the visitor turned it on.
3. **About** `#section_2`, a **white band** (`.au-paper`), two balanced columns (stacked on phones):
   - Left: colour logo + kicker "About us · since 2002", title "BRAC University Cultural Club", the history paragraph (founded 2002, 500+ members, Rhythm Revive, Jolsha, Symphonic Reminiscence, Boishakh), a stats row from Homepage Stats (§6.10: Members to date · Shows organized · Applicants · {semester} · Rejections · {semester}; each can be hidden), "Future Plans", buttons Contact Us / Past Events.
   - Right: a white card with the fading Bootstrap carousel (3.2s, 4:3 photos, dark caption over each photo with a white disc holding the colour logo): `2nd.JPG` "Wild Escape / Our Amazing Experience with BUAC" · `slide2.jpg` "Rhythm Revive 25.0 / Our signature recruitment show." · `slide3.jpg` "Power Panel / The power house of the club" · `slide4.jpg` "BUCuC Iftar / Where tradition meets togetherness." · `slide5.jpg` "বৈশাখ উৎসব / রঙে, গানে, আর ঐতিহ্যে রাঙানো বৈশাখ ১৪৩২!"; below it a white **BUCuC Mentors** card (Rudan Al Amin, Yash Rohan, Sadat Kabir Rudro, Miftah Zaman, Ankan Kumar, Nafisa Tasnuva Hossain).
4. **Advisors** `#advisors_section`: kicker "Guidance", title "Meet Our Advisor & Co-Advisor"; two person cards (photo 4:5, name, gold role, link): Dr. Sharmind Neelotpol, Advisor (`images/Advisor/advisor.jpg`, Google Scholar); Dr. Upal Aditya Oikya, Co-Advisor (`images/Advisor/co.JPEG.jpg`, LinkedIn).
5. **Panel** `#section_3`: kicker "Leadership", title "Meet Presidential Panel", a pill-shaped select `#panelYearSelect` (tenures from the database: "Current Panel (2026-27)" first, then "Panel 2025-26" … newest to oldest), the teal label `#panelYearTitle`, and the card grid `#panelMembersGrid`. Columns: 4 members → one row of 4 (`col-lg-3`), otherwise rows of 3 (`col-lg-4`), centred; cards max 300px wide. The current year is rendered by PHP; changing the select re-renders the panel grid **and** the secretaries carousel from `window.BUCUC_PANEL_YEARS` (no network request) and pulses the aurora.
6. **Secretaries** `#section_4`: kicker "The team", title "Meet Our Secretaries" `#sbSectionTitle`, full-width Swiper coverflow `#sbSwiper` / `#sbSwiperWrapper`.
7. **Past Events** `#section_5`: kicker "On stage", title "Past Events", 5 photo cards (date chip, title, weekday; the five newest events, §6.8) linking to `past_events.php` anchors, plus a glass "View All / Past Events" card with a spinning gradient arrow disc:
   Club Leadership Bootcamp 2025 (10 October 2025, Friday, `pls.jpg`, `#event-6`) · Rhythm Revive 25.1 (7 September 2025, Sunday, `slide8.jpg`, `#event-7`) · Participation at 16th Convocation Volunteers Appreciation Ceremony (15 August 2025, Friday, `slide9.jpg`, `#event-8`) · বৈশাখী উৎসব ১৪৩২ (28 April 2025, Tuesday, `slide5.jpg`, `#country-music`) · Participation at Wild Escape by BUAC (22 April 2025, Tuesday, `2nd.JPG`, `#event-9`). Event photos fall back to `images/placeholder.svg` if a file is missing.
8. **Apply Now** `#signup`: kicker "Join the club", title "Apply Now", lead "Become part of the BUCuC family. Every field is required." One glass panel `#applyPanel`:
   - Header: segmented tabs **Application Form** / **Find Us** (Google Maps embed), a progress meter "N of 12 filled" with 8 coloured equalizer bars that rise as fields become valid, and a **Typing sound** toggle `#soundBtn` (off by default; when on, each keystroke plays a soft pentatonic note).
   - Fields (underline style; label above; a gradient bar sweeps in under the focused field; a green tick appears when valid; a rose message appears under invalid fields after blur or submit): Full name (full width) · University ID | Department · Personal email (full) · G-Suite email (full) · Phone number | Date of birth (max today) · Current semester | Gender (selects) · Facebook profile link (full; plain text, `https://` is added automatically) · First | Second department preference (the two lists never offer each other's choice).
   - Client checks mirror `signup_handler.php` (plus phone = 10–15 digits); an invalid submit focuses the first bad field and shakes the panel. A valid submit cleans the values (trim, lowercase e-mails, digits-only phone, normalised Facebook URL) and shows a "Submitting" equalizer in the button.
   - After a successful submission (redirect back to `#signup`) the form is replaced by a **ticket**: "You're in the queue, {first name}.", the success message, a gradient ticket with name, University ID, semester and first choice, a waveform stub generated from the ID digits, an aurora burst, and a "Submit another application" button. Errors show a rose banner above the refilled form. When signup is closed the panel shows "Application Form expired for current Semester".
9. **Footer** `#footer`: white band (`.au-paper`) with an aurora line on top; colour logo + "BRAC University Cultural Club" + "Work • Bond • Glow" + 4 social icons; "Links" (Home, About, BUCuC Panel, Secretaries, Past Events, All event stories); "Have a question?" (club.bucuc@g.bracu.ac.bd, hr.bucuc@gmail.com); "Location" (Kha 224 Pragati Sarani, Merul Badda, Dhaka 1212, Open in Google Maps); bottom row "Copyright © {current year} BRAC University Cultural Club" and Admin Login. `#very-bottom` is the Contact target.

Deep links (`index.php#section_5`, QR codes, the post-Apply redirect to `#signup`) scroll to the section 76px above it (navbar height), once immediately and once after images load. Sections also carry `scroll-margin-top`.

### 7.2 `frontend/past_events.php` → `past_events.html`
`past_events.php` honours the lockdown, then serves `past_events.html` with every event from the database (newest first) in place of its `<!-- EVENTS … -->` placeholder (§6.8); `frontend/.htaccess` rewrites old `past_events.html` links to it. The HTML itself is a static page using the aurora sky and fonts (`body.au.au-events`, styles at the end of `css/aurora.css`). Fixed gradient "← Back to Home" pill, gradient title "Past Events" and subtitle "Relive the amazing moments from our cultural events". One glass card per event (aurora line on top) with: gradient logo icon, title, meta (date, "By BUCuC", place; teal icons), photo, description, "Event Highlights" (♪ list, teal left border), and stat tiles whose gradient numbers count up when scrolled into view (keeps a trailing `+`). Anchors (in order): `event-6` Leadership Bootcamp · `event-7` Rhythm Revive 25.1 · `event-8` Convocation volunteers · `shrabon-chonde` বাংলা গানের ভাবনা ও পথচলা · `country-music` বৈশাখী উৎসব ১৪৩২ · `event-9` Wild Escape · `pop-night` Rhythm Revive 25.0 · `dj-night` BUCuC Iftar 2025 · `rock-roll` Panel 2025-26 Handover · `free-styles` উচ্ছ্বাসে স্পন্দন by AUSTCC · `event-10` Rythm Revive 24.0 · `qawali-night` Qawali Night at AquaVenture (Club Fair Fall '24). A loading spinner fades out about 0.8s after load; AOS (unpkg aos@2.3.1) fades cards up; 25 floating green particles with slight parallax.


### 7.3 `frontend/performances.php` (Performance Gallery)
Aurora page (sky, gradient title "Performance Gallery", kicker "On screen", "Back to Home" and "Past Events" pills). Closed during a lockdown.
- **Grid** of cards (`css/gallery.css`): thumbnail with a round play button, category, title, date / tenure, description. Featured videos come first (gold border, "★ Featured" badge), then the admin's order. Hidden videos never appear.
- **Shorts** use a vertical 9:16 frame (at most 300 px wide, centred), never squeezed into a wide one.
- **Filters** (no reload): category chips (only categories that have videos) and a "year or tenure" select ("Tenure 2025-26" for videos with a tenure, otherwise the year of their date). A combination with no videos shows "No videos match this filter".
- **Playing**: only thumbnails load with the page (from i.ytimg.com). Pressing play replaces the thumbnail with a `youtube-nocookie.com/embed/ID?autoplay=1&playsinline=1&rel=0[&start=N]` player, so no YouTube player or script loads before a click.
- **Empty**: "No videos yet. Our performance videos will appear here soon…" with a link to past events.

---

## 8. Admin pages

All use the aurora theme (§10.5). Pages without the sidebar have a "Back to Dashboard" link that goes to the right dashboard for the role.

| Page | Contents |
|---|---|
| `admin-login.php` | Glass card: shield icon, "Admin Login", "Enter your credentials to access the dashboard", e-mail (kept after an error) + password (eye toggle), "Login to Dashboard", key icon "Forgot password?", "Back to Main Site". Behaviour in §6.6 |
| `forgot-password.php` | Same card: key icon, "Forgot Password", "Enter your admin email and we'll send you a reset link.", e-mail field, "Send reset link" (paper-plane), "Back to login". After submit: green box with the generic message |
| `reset-password.php` | Same card: lock icon, "New Password", "Choose a new password for {email}.", new + repeat fields (min 8), "Save new password", "Back to login". Bad link: rose box + "Request a new link" |
| Dashboards (`includes/dashboard_view.php`) | Sidebar: logo + "BUCuC", subtitle = the role label; **Dashboard**: Overview; **Management**: Members (view_members), Applications (+badge), Shortlisted Members, Approved Members (view_members), Website Media (any upload permission), Dashboard Updates (manage_site); **Settings**: Signup Control (manage_site), Homepage Stats (home_stats), Site Colours (theme), Email Notices (send_notices), Performance Gallery (manage_gallery), Attendance (take_attendance), Handover Notes (everyone), My Profile, Admin Management (Super/Panel/HR), Activity Log, Site Lockdown (Super), Logout. Header: title ("Super Admin Dashboard" / "Cultural Club Dashboard"), "Welcome back, {username}!", avatar pill (first letter + first name → Profile). Then 4 stat cards and 2 charts (§6.5). Mobile: the sidebar slides in from the left via a round orange button |
| `pending_applications.php` | Title "Pending Applications (N pending)"; action strip (dashed red border): **Clear** (manage_site), **Set Venue** (final_approve), **Export Excel**; table: Full Name, University ID, Email, G-Suite Email, Department, Phone, Semester, Gender, Facebook (link), Priorities, Status (yellow "Pending"), Actions (**Shortlist** blue, **Reject** red). Clear-All modal with type-to-confirm |
| `shortlisted_members.php` | Title "Shortlisted Members", subtitle with count. Strip: Clear List (manage_site), Set Venue (final_approve), Export Excel. Table: Full Name, University ID, Email (G-Suite), Department, Phone, Facebook, Priorities, Status (purple "Shortlisted"), Actions (**Final Approve** green / **Reject** red with final_approve; otherwise "🔒 Needs final-approval permission") |
| `approved_members.php` | Title "Approved Members", count. "Download Member Information (Excel)". Table: Full Name, University ID, Email, Department, Phone, Priorities, Status (green "Approved Member"), Delete (manage_site; otherwise "🔒 Panel Admin only") |
| `set_venue.php` | §6.4 |
| `member_types.php` | Card with 3 big gradient buttons: SB Members, ASB Members, GB Members (staggered fade-in, ripple on click) |
| `sb_members.php`, `asb_panel.php`, `gb_members.php` | Title "{SB/ASB/GB} Members Only", subtitle "{Student Board / Active Student Body / General Board} ({X}) Member Directory". Stats and list as in §6.7 (members of this level from the database; the sheet is only read by "Import from Google Sheet"). Per row: avatar initials, ID, G-Suite, coloured badge, a select with the allowed one-step moves (§6.7; POST `{action:'update', studentId, position}`), and Delete for manage_site (POST `{action:'delete', studentId}`). Both go through `?action=gas_proxy` on the same file to the Apps Script. Errors are reported as errors. Needs view_members |
| `dashboard_updates.php` | §6.4 |
| `admin-signup-control.php` | Glass card (formerly a light page), status card (green ✓ "Signup Form is ENABLED" / red ✗ "DISABLED"), select + "Update Status", Back / View Website buttons, info list |
| `admin_management.php` | Panels (top to bottom): **Permissions** for one admin (when opened: 12 tick cards with label, help text and "default for this role"; Save / Cancel), **Edit** (username, email, role (vacant ones you may assign), status, new password), **Add Admin** (only free roles you may create; or "Every role you can create already has an admin"). Then the **Admins** table sorted by rank: name + e-mail ("(you)"), role pill (Super pink, Panel violet, HR teal, MIAP green, Secretary gold, No role grey), Active (green) / Inactive (rose), Powers (summary), actions shown only when allowed: Edit, Permissions (sliders), Deactivate/Activate, Remove (confirm). Footer note on the rules |
| `media.php` | "Website Media" + tabs (gradient pill = current): Panels & Tenures, Past Events, Hero Video. Tenures: "Tenures (N of 12)" panel with chips + New tenure form (and a rose line when the limit is reached), a red confirmation panel when a tenure would be deleted, the selected tenure's header (Current panel badge / Make current / Delete tenure), then Presidential Panel and Secretary Panel sections, each with a gold rule chip ("4:5 portrait, at most 800 × 1000 px · JPG, PNG or WebP · any photo: you crop it to fit"), add/edit form with the crop tool and a preview of the final photo, and tiles (4:5 / 250:330) with Edit and Remove. Events: form + rows (3:2 thumb, View, Remove). Hero: 16:9 preview, rule, "Upload & replace", "Replace with the original video" |
| `lockdown.php` | "Site Lockdown": status card (green dot "The public website is online" / gold "Lockdown scheduled · Starts …" / red "The public website is OFFLINE · Since …" with the notice and Cancel / Lift button), schedule form (Starts at, Lock right now, notice, optional visitor message, red "Schedule lockdown"), Recent lockdowns table |
| `home_stats.php` | "Homepage Stats": Semester label box, then one row per stat with a number box and a Show tick; Save |
| `handover.php` | "Handover Notes" + search box + Dashboard; tenure chips (current / tenure removed); status (Current · editable / Unlocked / Read-only), PDF, Unlock / Lock (Super), "This tenure ends on" (Super, Panel); gold "N sections are still empty" box and section links; one panel per section with icon, prompt, "Last edited by {role}", Edit / History, the content (text, or a table; pending tasks with a tick button), history with Restore |
| `attendance.php` | "Attendance" + Back to Dashboard; tabs **Sessions** (semester filter, New session, table: when + status chip, title + type + audience + QR chip, expected, present, late, excused, absent, Open) and **Members report** (filters, Excel, search, table). The New / Edit session form opens above the list |
| `attendance_session.php` | Title, details and status chips; totals tiles; QR check-in / Excel / Edit session / Delete; the checklist with search, filter chips, Mark all present, 4-way switch per member, sticky save bar (reason box once ended) |
| `attendance_checkin.php` | Big QR code on white with "New code in N s" bar, "Scan with your phone camera, then type your student ID", Open until … + End check-in (or a duration select + Start check-in when closed); live list with count, time, Present / Late chip and Remove |
| `video_gallery.php` | "Performance Gallery" + View public page / Back to Dashboard. "Add a video" (or "Edit video") panel: YouTube link + Check, live result line (green ✓ / gold ⚠ duplicate / rose error), title, category, tenure, date, Featured, Vertical video (Short), description, Add / Save; preview card on the right. "Videos (N)" list: drag handle, position number, thumbnail, title + tags, date / tenure + YouTube link, Edit / Hide or Show / Delete. Below 700 px the handle is hidden (use the numbers) |
| `notices.php` | "Email Notices": New notice (teal "Will be sent from …" box, Send to pills: All members (N) / A group / Pick members, group select or searchable tick list, Subject, Message, placeholder chips, sending note, "Send notice" with confirm) or, while sending, the progress panel (bar, four counts, status line, Resume / Retry the failed ones / New notice, failed list). Below: Sent notices table |
| `profile.php` | Large gradient avatar initial, username, role badge ("Main Admin" red / "Staff Admin" orange), username/email fields, "Change Password" section (new, confirm, Current Password) |
| `activity_log.php` | Title "Activity Log" + Back to Dashboard. Three tiles: Actions · 24h, Logins · 24h, Failed logins · 24h (rose when > 0). Filter bar (GET): Admin, Action, "Search details / IP", From, To, filter + clear buttons. Every admin can open it (Super Admin rows hidden from others; lockdown filters hidden too). Table, newest first, 50 per page: When ("12 Oct 2026, 9:52 am"; full timestamp on hover), Admin, Action (pill: green = approvals/additions, rose = deletions/rejections/failed logins/deactivations, violet = sign-in events, teal = other), To whom / what, Details, IP (monospace). Search also matches the target. "N entries · page X of Y" with ← Newer / Older → links. Below 720px each row becomes a stacked card |

---

## 9. Content data

### 9.1 Panel tenures (database) and `backend/Api/members.json`
Panels live in the `tenures` / `tenure_people` tables and are managed in Website Media → Panels & Tenures (§6.8). `members.json` is a copy, rewritten after every change:

```json
{ "years": [ { "year": 2027, "label": "Current Panel (2026-27)",
               "panel_members": [ { "id": "avibadhan-das_2026", "name": "Avibadhan Das", "position": "President",
                                    "image": "images/Panel_26_27/Panel/Avibadhan_Das1.jpg", "facebook": "http://www.facebook.com/", "department": "Panel" } ],
               "secretaries": [ … ] } ],
  "metadata": { "total_years": 7, "total_panel_members": 26, "total_secretaries": 161, "last_updated": "2026-10-18" } }
```

UTF-8 without BOM. `year` = start year + 1; current first. Image paths are relative to `frontend/` and case-sensitive on the live server. An empty Facebook link hides the Facebook overlay. The tenures that existed on 18 Oct 2026: 2026-27 (current, 5 + 32), 2025-26, 2023-25, 2022-23, 2021-22, 2020-21, 2019-20.

### 9.2 Old secretary folder (first-run import only)
Before tenures, the current panel's secretaries were read from `images/Panel_26_27/Secreteries/` file names. `secretaries_from_folder()` / `parsePanel26SecretaryFilename()` (site.php) still do this once when `tenures_seed()` imports a year with `secretaries_folder`:
- Format `NameInCamelCase(Position).jpg`, e.g. `AlenaHalder(SecretaryOfMIAP).jpg` → "Alena Halder" / "Secretary of Marketing, IT, Archive & Photography"; `SOP_music` / `SOP_dance` → "Secretary of Performance (Music / Dance)"; typo fixes; legacy `Name_Department_Sub.jpg`.
- Order: "Arafat" first, "Zakaria" second, then Marketing, Human, Event, Finance, Administration, Creative, Public, Performance, Research.

### 9.3 Things that are hard-coded in HTML
Hero text, About text and carousel, Advisors, the footer, and the e-mail template text. (The hero video, every panel tenure and every past event are managed in Website Media.)

---

## 10. Design system — "Aurora"

The whole site (public and admin) uses one look: a night sky with animated aurora curtains and an equalizer horizon, glass panels with a thin aurora line on top, Syne display type, and a green → teal → violet → magenta gradient. The club's old orange/yellow lives on as the warm **gold** accent (record labels, subheads, roles). It came from the "Club Fair Fall 2026" registration mock-up, used as the design reference.

Files:
- `frontend/css/aurora.css`: tokens, fonts, sky layers, shared building blocks (`.au-glass`, `.au-lined`, `.au-btn`, `.au-ghost`, `.au-gradient-text`, `.au-eq`), the **admin restyle** (`body.au-admin …`) and the past-events restyle (`body.au-events …`). It remaps the old variable names (`--primary-orange`, `--primary-yellow`, `--dark-blue`…) so older stylesheets pick up the palette.
- `frontend/js/aurora-sky.js`: the animated canvas. Include it **right after `<body>`**; it injects `<canvas id="sky">`, `.au-shade` and `.au-grain` behind everything. Admin pages (`body.au-admin`) get a calmer sky (aurora 50%, equalizer 28%). Pauses when the tab is hidden; respects `prefers-reduced-motion` (draws one still frame). API: `AuroraSky.pulse(n)`, `.burst()`, `.onFrame(fn)`, `.reduce`.
- `frontend/css/site.css` + `frontend/js/site.js`: homepage only.

**White "paper" sections** (`.au-paper` in `site.css`): the About band and the footer use a `#fbfaff` background with an aurora line on the edges, dark ink text (`#15102c` / body `#4a4466`), violet accents (`#5b34e6`), teal kickers (`#0b8f9c`), white cards with a soft violet shadow, and the **colour logo** `images/logopng.png`. The colour logo also sits on the white vinyl label in the hero and on the small white discs in the carousel captions. The navbar keeps the white logo (`images/logo.png`).

**Performance rules:** the sky canvas renders at 1× resolution and ~30 fps with 7px strips; there's no `backdrop-filter` on homepage panels (blur over an animated canvas is recomputed every frame), only on the navbar; the grain layer has no blend mode; the hero video pauses off-screen.

Every page's `<body>` carries `class="au"` plus `au-site` (homepage), `au-events` (past events) or `au-admin` (admin; the login page also has `au-login`). `aurora.css` must load **after** the page's other stylesheets.

### 10.1 Colour tokens (`:root` in `aurora.css`)
| Token | Value | Use |
|---|---|---|
| `--void` | `#06040f` | Page base (`html` background) |
| `--ink` | `#f3f0ff` | Main text |
| `--mute` | `#aaa3d2` | Secondary text, labels |
| `--line` | `rgba(205,190,255,.2)` | Hairlines, input underlines, ghost borders |
| `--g` green | `#5cffb0` | Valid ticks, positive states, gradient start |
| `--t` teal | `#2de2e6` | Links, kickers, focus, active nav underline |
| `--v` violet | `#8b5cff` | Glows, shortlist actions |
| `--m` magenta | `#ff4fd8` | Gradient end |
| `--gold` | `#ffcf7d` | Record label, gold subheads, person roles, pending badge |
| `--bad` | `#ff93b4` | Errors, destructive buttons |
| `--aur` | `linear-gradient(100deg, #5cffb0, #2de2e6 32%, #8b5cff 66%, #ff4fd8)` | Primary buttons, top lines, progress, date chips |
| `--glass` | `rgba(9,6,28,.66)` | Panel background (+ `blur(16px) saturate(130%)`) |
| `--glass-line` | `rgba(255,255,255,.09)` | Panel borders |

Sky canvas: vertical gradient `#04030c → #0a0724 → #160b3c`; stars (density W×H/5200, twinkle, slight mouse parallax); 4 aurora curtains (green, teal, violet, magenta) drawn as 5–8px vertical strips with additive blending, swaying on two sine waves; a violet glow from the bottom; an equalizer of 7px bars (5px on phones) along the bottom edge, coloured violet → teal → green; a shooting star every 5–12s. The aurora fades in over the first 2.6s. `pulse()` brightens it while typing; `burst()` flares it after a successful application.

Headline gradient text (titles): `linear-gradient(100deg, #fff, #e3f8ff 38%, #d8caff 72%, #ffd3f4)`, drifting slowly (16s), with `drop-shadow(0 0 26px rgba(139,92,255,.45))`.

Charts (dashboard): growth line stroked with the aurora gradient and a teal → transparent fill; gender doughnut Male teal, Female magenta, Other violet.

### 10.2 Typography
- **Bricolage Grotesque** 500–800 (display: headings, buttons, numbers), **Hanken Grotesk** 400–700 (body) and **Hind Siliguri** (Bengali glyphs, used automatically as a fallback), loaded by `aurora.css` from Google Fonts. (Syne from the mock-up was replaced because it read too wide.)
- Hero title `clamp(38px, 4.6vw, 70px)/0.98`, letter-spacing −0.03em; tagline 600 `clamp(17px, 1.8vw, 24px)`, uppercase, letter-spacing .12em.
- Section titles `clamp(32px, 4.6vw, 60px)/1.02`, −0.035em. Kickers: 13px, 600, uppercase, letter-spacing .16em, teal, preceded by 4 animated equalizer bars.
- Body 16px/1.6, paragraphs `rgba(243,240,255,.8)`. Field labels 13px/600 mute; inputs 17px/500. Gold subheads 14px uppercase, letter-spacing .14em.

### 10.3 Shape, depth, motion
- Radii: panels 22px, cards 20–22px, buttons 12–14px, pills 999px, inputs underline-only (public) or 12px boxes (admin).
- Panel shadow `0 30px 80px -30px rgba(0,0,0,.8)` + inset top highlight; hover lifts 6px with a violet glow `0 36px 80px -30px rgba(139,92,255,.55)`.
- **Aurora line**: 2px gradient strip inset 22px from each side at the top of a panel, glowing violet.
- Buttons: `.au-btn` aurora gradient (220% wide, slides on hover), dark ink text `#0a0620`, Syne 700; `.au-ghost` translucent with a hairline border.
- Animations: record spin (28s), hero rise-in (2.2s), equalizer bars (`au-eq`), title drift, input focus bar (0.5s), field tick pop, panel shake on invalid submit, progress bars springing up, ticket waveform. All disabled under `prefers-reduced-motion`.
- Faint film grain overlay (SVG noise, 7% opacity, overlay blend).

### 10.4 Homepage components (`site.css`)
- `.wrap`: max 1200px with a 16–48px gutter. `.au-section`: 64–120px vertical padding. `.sec-head`: centred kicker + title (+ `.sec-lead`).
- **Navbar** `.site-nav`: transparent → `rgba(6,4,15,.72)` glass after scroll; links are pills (14px/600), active link gets a teal underline glow.
- **Drawer** `.drawer`: 320px glass panel from the right with an aurora edge line; overlay `rgba(6,4,15,.6)`.
- **Vinyl planet** `.system` (`--d` 140–200px record): grooved conic-gradient disc, conic sheen, warm gradient label `#fff3d2 → #ffcf7d → #ff9d5c → #c2447a` with the logo, back/front rings (`--k` 1.42–1.62 × diameter, 0.27 aspect, −17°, the front half clipped above the record), glowing note glyphs (24px) orbiting on JS-computed ellipses.
- **Screen** `.screen`: glass frame, 18px-rounded 4:5 video, floating sound pill bottom-right, caption bar.
- **Person / panel card** `.person`, `.panel-member-card`: glass, 4:5 photo (top-aligned, zooms 1.05 on hover), bottom fade, name Syne 18px, role gold 12px uppercase; the aurora line appears on hover; Facebook overlay (violet radial + round glass button) on panel cards.
- **Year select** `.year-select`: centred pill, teal chevron.
- **Secretary slides**: 250×330 (210×280 on phones), 20px radius, active slide teal ring + violet glow; name strip on the active and neighbour slides; teal active bullet. Swiper settings: coverflow rotate 26 / depth 140, centred, loop when > 5, autoplay 3s while ≥ 30% visible, 1.2/2/3/5 slides at 320/480/768/1024px.
- **Event card**: 340px tall photo card, dark gradient overlay, aurora date chip, Syne 20px title, zooms on hover.
- **Apply panel**: see §7.1 (8). Underline fields (`.control`), focus bar `.bar`, tick `.tick`, error `.err`, progress `.prog` (8 bars with individual heights/colours), button `.go` (with `.busy` equalizer state), ticket `.ticket` (gradient green → violet → magenta, dashed stub).
- **Footer**: 4 columns (1 on phones), gold column titles.

### 10.5 Admin look (`body.au-admin` in `aurora.css`)
- Page gradients become transparent over the calm sky; every card (`.applications-card`, `.stat-card`, `.chart-card`, `.form-section`, `.profile-card`, `.login-container`, …) becomes glass with the aurora line.
- Page titles use the gradient headline text; icons teal.
- Buttons: approve / export / venue / save / login = aurora gradient; reject / delete / clear = rose outline (`rgba(255,147,180,.12)` + rose border); shortlist = violet outline; secondary = ghost.
- Tables: transparent, header row violet-tinted with mute 12px uppercase labels, rows highlight teal on hover.
- Badges: Pending gold, Shortlisted/SB violet, Accepted/GB green, ASB teal. Sidebar badge gold.
- Inputs: translucent boxes with a teal focus ring. Modals: near-opaque `rgba(12,8,34,.96)`.
- Sidebar: dark glass; section titles teal; active item green left border on a green → violet tint. Avatar and the mobile toggle use the aurora gradient. Stat icons are gradient-filled, numbers Syne 800.
- Member-type buttons: SB violet → magenta, ASB teal → violet, GB green → teal. Signup Control (formerly light) is dark like the rest.

### 10.6 Breakpoints
992px (navbar → drawer; hero and about stack), 600px (half-width fields go full width, single-column footer and advisors, smaller slides), plus Bootstrap's grid inside the panel grid. Admin pages keep their earlier breakpoints (sidebar off-canvas at 768px).

### 10.7 Icons and libraries
Font Awesome 6.5.1 (cdnjs), Ionicons 7.1.0 (`logo-facebook`), Cropper.js 1.6.2 (local copy in `frontend/vendor/cropperjs/`, MIT licence, Website Media only), Chart.js 4.4.1, Swiper 11.1.14, AOS 2.3.1 (past events), Bootstrap 5.2.2 local (5.3.0 from cdnjs on the board pages). `templatemo-festava-live.css` and `bootstrap-icons.css` are still loaded by admin pages but no longer by the homepage.

---

### 10.8 Colour variables and the site palette (Admin → Site Colours)
**Every colour in the site's own CSS is a variable with the original colour as its fallback**: the stylesheets (`aurora.css`, `site.css`, `AdminCss/*.css`, `templatemo-festava-live.css`), the `<style>` blocks and `style=""` attributes of the pages, and the canvas colours in `aurora-sky.js`, `site.js` and `dashboard-charts.js` (they read the variables through `window.AuroraColor()`). Example: `rgba(139, 92, 255, 0.8)` became `rgb(var(--c-primary, 139 92 255) / 0.8)`. With no palette saved the variables are not defined, every fallback applies, and the site looks exactly as designed. This was checked element by element (computed colours of ~15,000 elements on 25 pages identical before and after). Bootstrap's own file is not converted; instead `backend/includes/palette_bootstrap.css` (injected on every page) points Bootstrap's `--bs-btn-*` / `--bs-alert-*` variables at palette variables holding Bootstrap's exact shades, so stock buttons and alerts follow a saved palette and look unchanged without one. On admin pages every alert has one tinted look (`body.au-admin .alert-*` in `aurora.css`). The lockdown banner, the admin-preview ribbon, the maintenance page, the toasts, `recover.php` and `logout.php` use palette variables too; only the database-down page keeps fixed colours (it can't read the palette when the database is down).
- **Names**: `--c-{role}` for a role colour, `--c-{role}-{hex}` for a shade that follows a role, `--c-fixed-{hex}` for greys / white / black that never change. All 203 are listed in `backend/includes/palette_map.json` with their role and HSL offset (hue, saturation, lightness) from the role colour.
- **Roles (13)**, with their original colours: page background `#06040f`, panels and cards `#0e0924`, main text `#f3f0ff`, secondary text `#aaa3d2`, main colour `#8b5cff`, teal accent `#2de2e6`, green accent `#5cffb0`, pink accent `#ff4fd8`, gold highlight `#ffcf7d`, warnings `#ff93b4`, white band background `#fbfaff`, white band text `#15102c`, white band accent `#5b34e6`. A shade = its role colour + its offset; while a role keeps its original colour, its shades keep their exact original values.
- **Generate from one colour**: every role except gold and warnings takes the new hue but keeps its original brightness (luminance) and at most its original colourfulness, so readability matches the original design and neutrals stay neutral. The main colour is used as picked unless its contrast on the new background is below 4.5; then only its lightness is moved until it is readable (the page says so). The original violet gives back the original palette exactly.
- **Readability**: 13 text/background pairs (main / secondary text on background and panels, each accent and the gold and warning colours on the background, dark button text on the green and teal buttons, white-band text and accent on the white band) are checked with the WCAG contrast ratio: ≥ 4.5 "Easy to read", 3–4.5 "May be hard to read", < 3 "Hard to read". Every generated palette tested (120 main colours) passes.
- **Where it applies**: `auth.php` starts an output buffer (`palette_inject`) on every page that adds `<style id="site-palette">:root{--c-…: R G B; …}</style>` before `</head>` when a palette is saved (HTML only; JSON and downloads are untouched). The Site Colours page defines `NO_SITE_PALETTE` and uses fixed colours in its own CSS, so a bad palette can never make it, or its Reset button, unreadable. Its address is `/frontend/theme.php`.
- **Preview** (nothing is saved): two rows, **public website** (`index.php`) then **admin panel** (the admin's dashboard), each a real, scrollable page in a sandboxed frame. Each row has its own **Desktop / Mobile** switch: the page is laid out at 1366 px or 390 px wide (so the site's own phone layout applies) and scaled down to fit, with the size shown ("1366 px wide, shown at 53%"). Every colour change is written straight into both frames' CSS variables, so they update instantly without reloading.
- **Adding CSS later**: write colours as variables (`rgb(var(--c-primary, 139 92 255))`), or write plain colours and run `python backend/scripts/colorize_css.py <repo folder>` (use `--dry` first): it converts only new plain colours, keeps the existing map, and never touches `theme.php`.

## 11. JavaScript behaviour summary
| File / block | Behaviour |
|---|---|
| `js/aurora-sky.js` | Animated sky canvas on every page (§10) |
| `js/site.js` (homepage) | Navbar `.scrolled`; mobile drawer; vinyl rings + orbiting notes; hero video sound pill; Swiper init (`window.initSbSwiper`, instance `window.sbSwiperInstance`, don't use `window.sbSwiper`, which is the carousel element's id); panel-year switcher from `window.BUCUC_PANEL_YEARS`; typing-sound synth (Web Audio, off by default); Apply form validation, progress meter, value clean-up, busy state; success ticket waveform + aurora burst; deep-link scrolling |
| `js/click-scroll.js` (homepage) | Active nav link by scroll position for the 7 section IDs; smooth scroll with a 90px offset |
| `js/dashboard-charts.js` | Aurora-coloured line + doughnut charts from `window.dashboardStats`, empty states, sidebar toggle |
| `includes/member_board.php` inline | board list, update/delete through the proxy |
| `js/admin-toast.js` | `showNotification(message, type)` pop-ups on admin pages (palette colours, text never parsed as HTML) |
| `past_events.html` inline | AOS init, particles, loading overlay, count-up stats, hash scroll |

---

## 12. Security notes
- Prepared statements everywhere; output escaped with `htmlspecialchars`/`json_encode`; client-rendered strings escaped.
- Server-side role checks on every privileged action; redirect targets whitelisted; Apps Script proxy requires an admin session and only forwards `update`/`delete` with a valid position.
- `.htaccess` forces HTTPS, sends `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, hides `X-Powered-By` (PHP pages send the same headers from `db.php`), and blocks dumps, logs, exports, data files (`.json`, including `members.json`), docs, `.token`, `/.git`, `/.claude` and internal folders. Upload folders refuse scripts.
- Performance gallery: only YouTube links (parsed and checked with oEmbed on the server), only IDs stored, players from youtube-nocookie.com loaded on click (§6.12).
- Errors only go to `backend/logs/php_errors.log` (§3.3). Apply form: signed form token, spam trap and per-IP limit (§6.1). Spreadsheet values can't become formulas (§6.4).
- Admin hardening (§6.6): CSRF tokens on every admin POST and fetch(), login throttling, 30-minute idle timeout, `HttpOnly`/`SameSite=Lax`/`Secure` cookies, live account check on every request (deactivate = instant sign-out), password changes end other sessions, single-use hashed reset tokens that expire in 30 minutes, reset links built from `APP_URL`, no account enumeration on login or forgot-password, activity log of all privileged actions.
- Roles: one account per role, rank checks for every admin action, permissions checked on the server for every page and action (not only hidden buttons), Super Admin invisible to other admins, lockdown enforced on public pages and the panel.
- Uploads: type read from the file itself (`getimagesize`, MP4 boxes), never from the name; size/ratio/duration limits; files renamed by the server; deletes limited to `frontend/images` and `frontend/video`.
- Passwords: `password_hash` (PHP default, re-hashed on login when the default changes), minimum 8 characters everywhere (create, edit, profile, reset).
- Secrets live only in git-ignored `*.local.php` files on the server (§3.4). Old passwords remain in the git history until it is cleaned (§17).

---

## 13. Status

### 13.1 Fixed in this pass (October 2026)
1. **Reject removed the wrong person**: it deleted the same numeric ID from both pending and shortlisted. Reject now only touches the list it came from.
2. **The live e-mail was the old design**: your new template sat in `application_handler.php`, which nothing calls. It's now shared (`backend/includes/email_template.php`) and used by the real Accept button. This also removed the hard-coded personal phone number from the old e-mail.
3. **"Download Member Information" exported the pending table** instead of approved members. Each list now exports its own table (DOB column added, IDs/phones kept as text).
4. **Accepting an applicant without a DOB failed** (`members.date_of_birth` was NOT NULL). Fixed in the schema; **live DB needs `upgrade.sql`**.
5. **Clear All reported "Server error" after already deleting everything** (an ALTER TABLE inside the transaction). Fixed; also Super Admin only now, and every cleared row is archived to a log first.
6. **SB/ASB/GB pages had no login check**: anyone could read the member directory and edit/delete Google Sheet rows through the proxy. Now admin-only, with real error reporting (it used to claim success on failure).
7. **Applying closed applications**: the switch only hid the form. The handler now enforces it. Duplicate checks now cover all three tables. Input validation added, DB errors hidden, typed values kept after an error.
8. **Post-Apply redirect and QR deep links never scrolled**: a script cleared the URL hash on load. Removed.
9. **Super Admin dashboard ran in quirks mode** (a `<script>` printed before `<!DOCTYPE>`), and both dashboards' JS crashed partway (dead references). Rebuilt as one shared view; removed the fake 1Y/2Y/3Y numbers and the dummy gender data; the search box (which did nothing) is gone.
10. **Google Sheets used a stale fallback URL** whenever called from `frontend/` (relative include path), and treated `{"ok":false}` replies as success. Paths are absolute now, so approvals always go to the existing configured sheet (§6.4a), and SSL verification is back on.
11. **`members.json` couldn't be read by PHP** (UTF-8 BOM), and 2019–2022 photos pointed at files that no longer exist. One clean data file now drives all years; the stale hard-coded arrays and the hidden, never-opened "Previous Panels" modal (about 2,800 lines) are gone; HTML nesting errors fixed.
12. Misc: confirm dialogs broke on names with quotes, venue pre-fill could shift the date by a day, venue time check compared text, password length wasn't enforced server-side, the Excel/Hostinger duplicate exporter, session fixation at login, `display_errors=1` in the handler, duplicate anchor IDs on past events, footer year frozen at 2025, a missing footer image (404), the missing `<body>` tag, open redirect, unpinned CDN versions.
13. Removed dead/broken/debug files: `integration_status.php` (wrote a test row to the real Google Sheet on every visit), `asb_panel.final.php` (open proxy), `debug_members.php`, the member `login.php`/`member_dashboard.php` (members have no passwords; it never worked), the unused backend JSON endpoints, broken scripts, empty JS files, `.DS_Store` files.

Verified end to end on a local copy of the legacy database plus the 375 restored applicants (48/48 browser checks: apply, duplicate, refill, closed form, shortlist, cross-table reject, approve with and without DOB, 3 exports, venue, dashboard numbers, both clears, role restrictions, all 7 panel years' photos, deep links). E-mail and Google Sheets calls were pointed at dead local ports during the test, so no real mail or sheet rows were sent.

### 13.1a Redesign (October 2026)
The public site and all admin pages moved to the "Aurora" design (§10), based on the "Club Fair Fall 2026" mock-up: animated sky, glass panels, Syne/Hanken Grotesk, aurora gradient, vinyl-planet hero with the club video beside it, Apply panel with live validation, progress meter, optional typing sounds and a success ticket. Content, IDs, form field names and all PHP/DB behaviour are unchanged; the club-fair form itself was not added. `index.php` went from ~3,500 lines (with ~1,900 lines of inline CSS) to ~890; its styles live in `css/site.css`. Re-tested end to end on the local copy (same 48 checks).

### 13.1b Admin security (October 2026)
Added everything in §6.6: login limit, forgot password by e-mail from club.bucuc@g.bracu.ac.bd, CSRF protection, session timeout, active/inactive switch, activity log page. One shared `auth.php` replaced the hand-written `session_start()` checks in 17 files. Minimum password raised from 6 to 8 (existing shorter passwords still work until changed). Profile now asks for the current password before changing the password or e-mail. Verified locally: 58 security checks (CSRF on every form and endpoint, lock after 5 failures, deactivation kicks out an open session, reset e-mail only for real admins, same reply for unknown e-mails, single-use and expiring links, other sessions end after a reset, idle timeout, activity log filters) plus the original workflow checks. Reset e-mails were captured to a local file; nothing was sent.

### 13.1c Roles, media and lockdown (13 October 2026)
Five-role admin system (§5) with one account per role, rank rules, per-account permission ticks, hidden Super Admin; promotion/demotion GB ↔ ASB ↔ Secretary Panel with server checks (§6.7); Website Media uploads with size/ratio/video limits (§6.8); site lockdown with scheduled notice and countdown (§6.9); activity log with targets, visible to all admins without Super Admin rows. Also: one shared DB connection per request, Dhaka timezone for PHP and MySQL, secretary file-name parser moved to site.php. Verified locally with 121 role checks (every role's pages and actions, forged requests, uploads with good and bad files, a 30 s / 4:3 / HEVC video, lockdown on/off for visitors and admins, log visibility), plus the 58 security checks and the original workflow checks. Google was replaced by local fakes during the tests.

### 13.1d Tenures, member levels, deletes, image sizes (18 October 2026)
1. **Panels by tenure** with current tenure, past panels on the homepage, 12-tenure limit with a named confirmation and automatic deletion of the oldest (§6.8).
2. **Deleting removes files**: people, events, tenures and replaced photos/videos delete their files; the hero video can only be replaced.
3. **Promotion/demotion did not work**: member levels existed only in the Google sheet, so on the local copy a move "succeeded" but nothing changed on reload, and the database had no level at all. Levels are now in `members.position`; moves change the database and are copied to the sheet (§6.7). Tested as Super, Panel, HR (with and without the Secretary Panel permission), MIAP and Secretary Admin, including the real select in the page.
4. **Image sizes are maximums** now (§6.8).
5. Also fixed while testing: the success message after saving a person was lost (the `#section` jump came before the query string), and replacing a photo twice in the same second reused the same file name.
Verified with 59 new checks (each role's moves and the database value, import, 13th tenure with/without confirmation, files really gone from disk, homepage current tenure) plus the earlier role, security and workflow suites.

### 13.1e Lockdown, homepage stats, email notices (18 October 2026)
- Lockdown now closes only the public website; every admin keeps using the panel (§6.9).
- Homepage stats with automatic values, overrides and show/hide for the top three admins (made fully manual the next day, §13.1f / §6.10). Semester counts go by application date, so rejecting an older applicant doesn't change the earlier semester's applicant number.
- Email notices to all / a group / picked members with placeholders, batches, daily cap, retry and logging (§6.11).
- Image rule confirmed: the sizes are maximums and the ratio is enforced; both were already checked.
Verified with 45 new checks (lockdown for every role, stats calculations and overrides, notices to each audience, personalisation, failures, daily-limit pause and resume, permissions, logging) plus the earlier suites. Mail was captured to a file; nothing was sent.

### 13.1f Sender accounts, both addresses, manual stats (19 October 2026)
- Email notices send from club.bucuc@g.bracu.ac.bd (Super, Panel) or hr.bucuc@gmail.com (HR), each with its own daily limit (1,500 / 400); the page shows the sending address and the log records it.
- Each notice goes to the member's personal Gmail and G-Suite address (or the one they have); progress and limits count e-mails. The sheet import reads a Gmail column when present.
- Homepage stats are fully manual (numbers + semester label); the database is no longer used for them.
Verified with 31 new checks plus the earlier suites (mail captured to a file).

### 13.1g Site colour palette (19 October 2026)
All site colours moved into named variables without changing the look (computed colours compared element by element on 25 pages), plus the Site Colours page for the top three admins: colour wheel, generated palette, hand edits with a warning, readability check, live preview of the public site and the admin panel, last 5 palettes with restore, fixed-colour page and Reset button, every save / restore / reset logged (§10.8). Verified with 28 new checks plus the earlier suites.

### 13.1h Audit fixes (19 October 2026)
A full audit found 2 critical, 6 high, 12 medium, 11 low and 4 extra items; all were fixed:
- **Critical**: deleted `application_handler.php` (it bypassed login and CSRF); database password moved to the git-ignored `config.local.php` (§3.4).
- **High**: errors never shown, logged to `php_errors.log`, plain "Site temporarily unavailable" page; Super Admin shown as "an admin" everywhere else; notice batches claim their rows first (no double sends); one-time recovery page (§16); backup and restore procedure (§15); `.json` files blocked (members.json was downloadable); HTTPS forced, Secure cookies.
- **Medium**: files deleted only after the database commit, new uploads removed if saving fails; Excel cells as text and sheet values prefixed against formulas; Apply form CSRF + spam trap + per-IP limit; security headers; empty current tenure falls back to the previous one; forms keep typed values; foreign keys and indexes; `venuinfo` spelled one way; daily limits count all mail; no scripts in upload folders; shared mailer and toast script; logs and `.claude` untracked.
- **Low**: Dashboard Updates back link; maintenance page fits a phone; palette colours for the banner, ribbon, maintenance page, toasts and Bootstrap buttons/alerts; logout by POST; Wild Escape photo + image fallback; one alert style; 12-hour session limit; neutral duplicate-account message; removed `custom.js`, `jquery.sticky.js`, `previous_panels_api.php`, `composer.phar`; this file and `todo.md` brought up to date.

### 13.1i Crop tool and performance gallery (19–20 October 2026)
- Built-in crop and resize for every image upload (§6.8), tested with 23 browser checks (wrong ratio, too big, already right, tiny, cancel, PNG → WebP, phone with finger drag and pinch).
- Performance Gallery (§6.12, §7.3) with the new `manage_gallery` permission, tested with 49 browser checks against a local fake of YouTube's oEmbed (plus one real read-only oEmbed check). The homepage gets a "Videos" link.
- The Apply form token no longer depends on the session (§6.1).
- Every past event is editable and deletable, including the 12 older stories, which moved from `past_events.html` into the database with their anchors, formatting and photo framing (§6.8). Checked story by story against the old page (same titles, organizers, places, photos, paragraphs, bold / italic, highlights and number tiles; the one wrong weekday, 28 April 2025, is now correct).

### 13.1j Attendance (20 October 2026)
Attendance tracking (§6.13): sessions, phone-friendly checklist, QR self check-in with a code that changes every 30 seconds, reports per session / member / semester, low-attendance filter, attendance on the promotion pages, Excel, reasons + log for changes after a session. Tested with browser checks on desktop and an emulated phone (QR codes, check-in refusals, timing, reports, permissions).

### 13.1k Handover notes (20 October 2026)
Handover notes per tenure (§6.14): nine sections with prompts, contacts / accounts / tasks / calendar / links as tables, role spaces, version history with restore, search across tenures, read-only after the next tenure starts (Super Admin unlock), kept when a tenure is deleted, PDF via the browser, reminder e-mail a month before the end date, dashboard card. Tested with browser checks (permissions, conflicts, password guard, locking, survival, captured reminder e-mails, PDF page, phone).

### 13.1l Sponsor banners (20 October 2026)
Super-Admin-only sponsor banners (§6.15): three homepage spots with switches, crop-tool images (desktop + phone), dates and pausing, rotation, view / click counting without bots, click redirect, stats with date range, per-ad Excel report. Invisible to every other admin (404 on every URL, no menu item, no log entries, nothing in their pages). Tested with browser checks.

### 13.2 Needs you
The step-by-step list with tests is in `todo.md`; the main ones:
1. **Back up first (§15), then run `backend/Database/upgrade.sql` in phpMyAdmin on the live database.** Until then, approving applicants who have no date of birth (the 375 restored from the CSV) fails. Step 5 now drops the unused old tables.
2. **Upload `backend/Database/config.local.php`** with the database password before (or together with) the new `config.php`, or the site shows "Site temporarily unavailable".
3. **Rotate secrets and clean the git history** (§3.4, §17): change the database password in hPanel and revoke the old HR App Password, then update the `.local.php` files.
4. The homepage carousel and the Wild Escape event both use `2nd.JPG` (7.5 MB); a smaller copy would load faster.
5. Current panel Facebook links are placeholders (`http://www.facebook.com/`).
6. `restore.sql` and the `Member_Applications_…csv` in the repo root contain student personal data. They're git-ignored and blocked by `.htaccess`; delete them when no longer needed and don't upload them.
7. **Forgot-password e-mail**: create the App Password for `club.bucuc@g.bracu.ac.bd` and upload `backend/config/auth_email_config.local.php` (§3.2). Then test it once with your own admin account and check `backend/logs/auth_email.log`.
8. Admins with passwords shorter than 8 characters should change them in My Profile.
9. **Roles**: run `upgrade_roles.sql` (set `@super_email` first) right after uploading the new code, then log in as the Super Admin and create / assign the Panel, HR, MIAP and Secretary accounts. Old accounts have no role until you assign one.
10. Check that `frontend/images`, `frontend/video` and `backend/Api/members.json` are writable on Hostinger (try one upload of each type).
11. **Members**: on a board page press "Import from Google Sheet" once so the existing club members get their levels in the database, then lower the base member count in Dashboard Updates (§6.7 note).

### 13.3 Known leftovers
- The footer Terms/Privacy/Feedback links are placeholders; `.signup-particles` has CSS but no JS creates particles.
- Each older admin page still has its own inline `<style>` block; merging them into one admin stylesheet is left for the redesign.
- `bucuc.sql` is a historical dump with real admin hashes; `.htaccess` blocks it, but it's better out of the web root.

---

## 14. Rebuild checklist (acceptance tests)
1. `/` redirects to the homepage; the 7 panel years switch both the panel grid and the secretaries carousel, with no broken photos.
2. Apply with valid data → green success at the form; same ID again → "already submitted", fields refilled; identical priorities → rejected; closed signup → form hidden and POST refused.
3. Admin: shortlist moves the row; rejecting pending #N never touches shortlisted #N; Accept/Delete/Clear blocked (403/redirect); SB/ASB/GB load.
4. Admin with final approval: Final Approve moves to `members`, sends the e-mail (with the venue box when a venue exists), appends to the Google Sheet, shows a warning if either fails; exports produce the right list; Dashboard Updates change the cards and charts; Clear archives then wipes.
5. Logged out: every admin page redirects to login, the proxy returns 401, `/bucuc.sql` and `/backend/logs/email.log` return 403.
6. Security: a POST without `csrf_token` is refused (JSON endpoints 419); 5 wrong passwords lock the e-mail for 15 minutes; deactivating an admin signs out their open session on the next click; forgot-password shows the same text for unknown e-mails and mails only real active admins; a reset link works once, expires after 30 minutes, and signs out the admin's other sessions; 30 idle minutes sign an admin out; Activity Log lists and filters these events.
7. Roles: each role sees only its menu items and is redirected from other pages; Panel can't see/create/edit/remove the Super Admin; HR can remove only MIAP/Secretary and create no one; a second account for a taken role is refused; ticking final approval for the Secretary lets them Final Approve, unticking stops it; HR promotes GB→ASB and back but not into SB unless granted; skipping GB→SB is refused.
8. Media: picking a wrong-ratio photo opens the crop window locked to the right ratio, a too-big photo is scaled down, a right one is uploaded unchanged, a tiny one gets the blurry warning, and the preview shows the final size; posting a wrong ratio / too big image directly (without the tool) and too long / non-16:9 / non-H.264 videos are still refused with a clear message; good uploads appear on the homepage (carousel, panel, newest event card, Past Events top, new hero video) and can be removed / reset.
9. Lockdown: a scheduled lockdown shows the notice with countdown on every admin page; at the start time visitors get the 503 page while every admin keeps working; cancel / lift reopens the public site; non-Super admins never see Super Admin entries in the log.
10. Tenures: creating a tenure and making it current changes the homepage default; older tenures stay in the select; the 13th tenure asks to delete the oldest by name and only deletes it after confirming; deleting people/events/tenures removes their files.
11. Members: Import from Google Sheet fills the levels; each one-step move changes `members.position` for roles with the permission and returns 403 for the others; skipping a step is refused; every move is logged.
12. Lockdown: visitors get the maintenance page with the notice; every admin can still log in and work; admins previewing the public site see the red ribbon.
13. Homepage stats: the four typed numbers and the semester label show on the homepage exactly as entered; hiding a stat removes it; database changes don't move them; each save is logged; only Super / Panel / HR can open the page.
14. Notices: the sending address matches the role (club for Super / Panel, hr.bucuc@gmail.com for HR) and is shown first; each member gets it at Gmail and G-Suite (or the one they have) with their own name; counts are in e-mails; each account pauses at its own daily limit; each send is logged with the address.
15. Site colours: with no palette saved pages are unchanged; picking a main colour updates both previews without changing anything for visitors; hand edits show the warning; unreadable text is flagged; Save applies to the website and admin panel but not to the Site Colours page; only 5 palettes are kept; Restore and Reset work; each is logged.
16. Hardening: `/backend/Api/members.json` and any `.json` / `.log` / `.sql` give 403; `http://` redirects to `https://`; a `.php` file placed in `frontend/images` is refused; with a wrong database password every page shows "Site temporarily unavailable" and no error text; the Super Admin's name shows as "an admin" to other admins; the Apply form refuses a POST without a valid form token but still works after an admin logs in/out in the same browser; `recover.php` is 404 without the token file.
17. Performance gallery: every link format above is accepted and other sites are refused; a private / embedding-disabled / deleted video shows its error and isn't saved; title and thumbnail come from YouTube; the preview matches the public card; a duplicate asks to confirm; edit, hide/show, delete, drag and position-number reordering work and are logged; the public page loads no YouTube player until play is pressed, plays from youtube-nocookie.com at the saved start time, shows Shorts vertically, filters by category and year/tenure, puts featured first and shows the empty state; only roles with `manage_gallery` can manage it.
18. Attendance: a session for a group lists exactly that group; Mark all present, then one change, saves; check-ins appear live and count Late after 15 minutes; a second ID from the same phone, an ID not on the list, an expired code (>90 s) and a stale form (>5 min) are refused; a wrong check-in can be removed; after the end every change needs a reason and is logged with who / why / what; the report and member pages give the right % (excused not counted, unmarked = absent) and the under-50 % filter; the GB / ASB / Secretary Panel pages show the % only to admins with the permission; Excel cells can't be formulas; MIAP / Secretary can't open it unless granted.
19. Handover notes: every admin writes the current tenure's sections (role spaces: own role only); authors show as roles, the Super Admin as "an admin"; Contacts hidden without the permission (page, search, PDF); a password is refused; two people saving the same section don't overwrite each other; every save is in the history and can be restored; search finds a name in every tenure; after the next tenure is current the old notes are read-only (Super Admin can unlock) but pending tasks can still be ticked; deleting a tenure keeps its notes; the reminder goes once to every admin about a month before the end date; the PDF page prints the visible sections; edits, unlocks and exports are logged.
20. Sponsor banners: every other admin (and logged-out visitors) get a plain 404 on `sponsors.php`, and none of their pages, scripts or log mention it; a spot switched off or without a live ad leaves no trace on the page; a banner shows the Sponsored label, opens in a new tab with rel sponsored noopener and loads lazily; live dates, pausing and rotation work; views and clicks are counted once per real visitor (not bots or admins); the click redirect only goes to the stored link; stats and the Excel report match; no banner on the maintenance page.

---

## 15. Backup and restore

Back up **before** running any SQL file, before a big upload, and at least once a month. Keep backups off the server (your computer + Google Drive) and never in the git repository: they contain student personal data.

**What to back up**
| Part | Where | Why |
|---|---|---|
| Database | phpMyAdmin, database `u106340611_bucuc` | applicants, members, admins, tenures, settings, palette, logs |
| Uploaded photos | `public_html/frontend/images/` (especially `tenures/` and `events/`) | panel and event photos uploaded in Website Media |
| Videos | `public_html/frontend/video/` | the uploaded hero video (`hero-*.mp4`) |
| Panel copy | `public_html/backend/Api/members.json` | the public members API; rebuilt from the database, but keep a copy |
| Secret files | `backend/Database/config.local.php`, `backend/config/*.local.php` | not in git; without them the site can't connect or send mail |

**Backing up**
1. **Database**: hPanel → Databases → phpMyAdmin → select `u106340611_bucuc` → **Export** → Quick, format SQL → Export. Name the file `bucuc-db-YYYY-MM-DD.sql`. (hPanel → Backups also keeps daily copies of the database and files; use it as a second copy, not the only one.)
2. **Files**: hPanel → File Manager → `public_html/frontend` → right-click `images` → **Compress** (zip) → download the zip → delete the zip from the server. Do the same for `video`. Download `backend/Api/members.json` and the three `.local.php` files.
3. Check the backup: the `.sql` file should end with `COMMIT;` and be more than a few hundred KB; the zips should open.

**Restoring**
1. Turn on **Site Lockdown** (Super Admin) so nobody applies during the restore.
2. **Database**: phpMyAdmin → `u106340611_bucuc` → select all tables → **Drop** (only if you are replacing everything) → **Import** → choose `bucuc-db-YYYY-MM-DD.sql` → Go. For a big file, zip it first (phpMyAdmin accepts `.sql.zip`).
3. **Files**: upload the `images` / `video` zip to `public_html/frontend/` → right-click → **Extract** → allow overwriting. Upload `members.json` to `backend/Api/`. Upload the `.local.php` files if they were lost.
4. Delete `backend/logs/.schema_v*` so the site re-checks its tables on the next page load.
5. Log in, check the homepage (panel photos, events, hero video), Pending Applications and Activity Log, then lift the lockdown.

## 16. Super Admin recovery

Use this only when nobody can log in as the Super Admin (forgotten password and the reset e-mail doesn't arrive, account deleted, or role lost).

**Option A: one-time recovery page** (no SQL)
1. On your computer make a text file named exactly `recovery.token` containing one line of at least 32 random letters and digits (e.g. from a password manager). Keep a copy.
2. hPanel → File Manager → upload it to `public_html/backend/config/`. (Browsers can't download it: the folder and `.token` files are blocked.)
3. Open `https://bucuc.org/frontend/recover.php`. Without the file this page is a plain 404.
4. Paste the token, enter the Super Admin's e-mail and a new password (8+ characters, twice) → **Recover Super Admin**. That e-mail becomes the active Super Admin (a new account is made if it doesn't exist); any other account holding the Super Admin role loses it; login locks for that e-mail are cleared; `super_recovered` is logged.
5. The page deletes `recovery.token` itself and is off again. If it says the file could not be deleted, delete it in File Manager **now**. Wrong tokens count as failed logins (5 per 15 minutes).

**Option B: phpMyAdmin** (if the site itself is broken)
1. On your computer run `php -r "echo password_hash('NEW-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"` (or `php backend/scripts/create_admin.php` on a local copy) and copy the hash that starts with `$2y$`.
2. phpMyAdmin → `u106340611_bucuc` → **SQL**, run (change the e-mail and paste the hash):
   ```sql
   UPDATE adminpanel SET role = NULL WHERE role IN ('super_admin', 'main_admin') AND email <> 'you@example.com';
   UPDATE adminpanel SET role = 'super_admin', status = 'active', password = 'PASTE-HASH-HERE' WHERE email = 'you@example.com';
   DELETE FROM admin_login_attempts WHERE email = 'you@example.com';
   ```
   If the second line says "0 rows affected" the account doesn't exist; add it with
   `INSERT INTO adminpanel (username, email, password, role, status) VALUES ('Super Admin', 'you@example.com', 'PASTE-HASH-HERE', 'super_admin', 'active');`
3. Log in at `admin-login.php`, then change the password in My Profile.

## 17. Removing old secrets from the git history

Older commits contain the database password (`backend/Database/config.php`), the HR Gmail App Password (`backend/config/email_config.php`), test logs with personal data (`backend/logs/*.log`) and `.claude/settings.local.json`. Changing the passwords (§3.4) makes the old ones useless; cleaning the history removes them from the repository too.

1. **First rotate**: new database password in hPanel, revoke the old HR App Password in the Google account (Security → App passwords) and create a new one; update the `.local.php` files on the server. Cleaning history does not help if a copy was already cloned or cached.
2. Commit everything you want to keep, then make a fresh mirror clone to work in:
   `git clone --mirror https://github.com/<owner>/<repo>.git bucuc-clean.git` and `cd bucuc-clean.git`
3. Install git-filter-repo (`pip install git-filter-repo`).
4. Make `replacements.txt` with one line per old secret, e.g. `OLD-DB-PASSWORD==>REMOVED` and `oldhrapppassword==>REMOVED` (write the real old values; don't commit this file).
5. Run:
   ```
   git filter-repo --replace-text ../replacements.txt
   git filter-repo --invert-paths --path-glob 'backend/logs/*.log' --path .claude/settings.local.json --path restore.sql --force
   ```
6. Check: `git log --all -p | grep -c "OLD-DB-PASSWORD"` must print `0`.
7. `git push --force --mirror`. Everyone else must re-clone (old clones still contain the secrets). On GitHub, also ask support to clear cached views if the repository was ever public.
(BFG Repo-Cleaner works too: `bfg --replace-text replacements.txt` then `bfg --delete-files '*.log'`.)

## 18. Testing

All features are covered by browser test suites in `tests/` (about 700 checks). They run against a **throwaway copy**: their own database server, their own made-up data, e-mail written to a file, and fake Google / YouTube services. They never touch the live database, XAMPP's own MySQL (port 3306), real e-mail or the real Google Sheets.

**Run them**
```
cd tests && npm install          (first time only: installs puppeteer-core)
node tests/run.js                every suite (about 15 minutes)
node tests/run.js roles events   only some
node tests/run.js --list         what each suite covers
```
The summary shows each suite as ✓ / ✗ with its checks; failing checks are printed, and full output is in `tests/runtime/logs/<suite>.txt`. Exit code 0 = everything passed. Run them before every upload to the live site.

**What the runner does**
- Starts MariaDB from XAMPP (`E:/xampp`, change with `XAMPP_DIR`) on port **3307** with its data folder in `tests/runtime/db` (created on the first run). If anything else is using 3307 it stops instead of touching it, and it checks that the server answering is its own (`@@datadir`).
- Starts the site with `php -S 127.0.0.1:8099` and `tests/test_prepend.php` (database `bucuc_test`, `AUTH_MAIL_CAPTURE` so mail is written to `tests/runtime/auth_mail_capture.jsonl`, Google Sheets on a closed port, the member sheet / Apps Script / YouTube oEmbed faked by `tests/fixtures` on 127.0.0.1:8098). Ports 8098 / 8099 must be free.
- Before each suite: a fresh `bucuc_test` from `backend/Database/schema.sql` (so the fresh-install schema is tested too) + `tests/seed/seed.php` (375 made-up applicants with example.com / example.edu addresses, the Apply switch, dashboard numbers) + five test admins (`super@test.local` / `superpass123`, `admin@test.local` / `adminpass123` (HR), `panel@test.local` / `panelpass123`, `miap@test.local` / `miappass123`, `sec@test.local` / `secpass1234`).
- After each suite: `backend/Api/members.json` is put back and every file the suite uploaded under `frontend/images` / `frontend/video` is removed. At the end the servers are stopped.
- Chrome: `C:/Program Files/Google/Chrome/Application/chrome.exe` (change with `CHROME_PATH`).

**Writing a new check**: add it to the suite of that feature (or a new `tests/e2e/<name>.js` plus a line in `SUITES` in `tests/run.js`). Use `tests/config.js` for paths (`T.BASE`, `T.MYSQL`, `T.DB_NAME`, `T.RUNTIME`…), print `PASS  …` / `FAIL  …` lines and end with `N/M passed`. Never put a real password or real personal data in `tests/`.
