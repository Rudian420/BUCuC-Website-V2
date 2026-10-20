# BUCuC website: to-do list

Things that couldn't be finished or checked on my local copy. Each item says **how to do it** and **how to test it**.
Tick the box (`[x]`) when done. New items get added at the bottom of the right section as work continues.

Last updated: 19 Oct 2026 (after the audit fixes)

---

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

### [ ] E8. Placeholders
The footer's Terms/Privacy/Feedback links are placeholders. Decide: fill in or remove. (The sidebar "Tell a Friend"/"Help & Feedback" items and the "Active Panel" stat are already gone.)

### [ ] E10. One admin stylesheet (redesign)
The older admin pages each still carry their own `<style>` block (audit item M12). The shared mailer and the shared pop-up script are done; merging the page styles into one file is left for the planned redesign.
- **Do:** during the redesign, move the shared parts into `css/aurora.css` / one admin stylesheet.
- **Test:** every admin page looks the same before and after (`colorsnap` comparison).
