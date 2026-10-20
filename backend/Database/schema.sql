-- BUCuC website — database schema for a FRESH install (MySQL 5.7+/8, MariaDB 10.3+).
-- For an existing live database run upgrade.sql instead.
--
-- After importing, create the first Super Admin from the command line:
--   php backend/scripts/create_admin.php "Super Admin" you@example.com "a-strong-password" main_admin

SET NAMES utf8mb4;

-- Admin accounts. role: main_admin = Super Admin, admin = regular admin
CREATE TABLE IF NOT EXISTS `adminpanel` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,             -- password_hash()
  `role` VARCHAR(20) NULL DEFAULT NULL,           -- super_admin | panel_admin | hr_admin | miap_admin | secretary_admin; NULL = no role (can't log in)
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_admin_role` (`role`)              -- one account per role
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stage 1: new applications from the public "Apply Now" form
CREATE TABLE IF NOT EXISTS `pending_applications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(255) NOT NULL,
  `university_id` VARCHAR(50) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `gsuite_email` VARCHAR(255) NOT NULL,
  `department` VARCHAR(100) NOT NULL,          -- academic department (free text)
  `phone` VARCHAR(20) NOT NULL,
  `semester` VARCHAR(50) NOT NULL,              -- 1st … 9th, 10th+
  `gender` VARCHAR(20) NOT NULL,                -- Male / Female / Other
  `date_of_birth` DATE NULL,
  `facebook_url` TEXT NULL,
  `firstPriority` VARCHAR(100) NOT NULL,        -- Admin, PR, HR, EM, Creative, Finance, Performance, RD, MIAP
  `secondPriority` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pending_uid` (`university_id`),
  KEY `idx_pending_gsuite` (`gsuite_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stage 2: shortlisted by any admin, waiting for Super Admin final approval
CREATE TABLE IF NOT EXISTS `shortlisted_members` LIKE `pending_applications`;

-- Stage 3: approved members (congratulations e-mail sent, pushed to Google Sheets)
CREATE TABLE IF NOT EXISTS `members` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(255) NOT NULL,
  `university_id` VARCHAR(50) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `gsuite_email` VARCHAR(255) NULL,
  `department` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `semester` VARCHAR(50) NOT NULL,
  `gender` VARCHAR(20) NOT NULL,
  `date_of_birth` DATE NULL,
  `facebook_url` TEXT NULL,
  `firstPriority` VARCHAR(100) NULL,
  `secondPriority` VARCHAR(100) NULL,
  `position` ENUM('GB','ASB','SB') NOT NULL DEFAULT 'GB',  -- GB -> ASB -> SB (Secretary Panel)
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- original application date
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `university_id` (`university_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `gsuite_email` (`gsuite_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Orientation venue shown in the congratulations e-mail (only the latest row is used).
-- NOTE: the table name is case-sensitive on Linux hosts; the code uses `venuinfo` (all lowercase).
CREATE TABLE IF NOT EXISTS `venuinfo` (
  `venue_id` INT NOT NULL AUTO_INCREMENT,
  `venue_name` VARCHAR(255) NOT NULL,
  `venue_location` VARCHAR(255) NOT NULL,
  `venue_dateTime` DATETIME NOT NULL,
  `venue_startingTime` VARCHAR(20) NOT NULL,    -- free text, e.g. "3:00"
  `venue_endingTime` VARCHAR(20) NOT NULL,
  `venu_ampm` VARCHAR(5) NOT NULL DEFAULT 'PM', -- (sic) column name used by the code
  PRIMARY KEY (`venue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Single-row switch for the public Apply form (id is always 1)
CREATE TABLE IF NOT EXISTS `signup_status` (
  `id` INT NOT NULL DEFAULT 1,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `signup_status` (`id`, `is_enabled`, `updated_by`) VALUES (1, 1, 'system');

-- Dashboard numbers typed in "Dashboard Updates". Append-only: the newest row (highest id) is used.
CREATE TABLE IF NOT EXISTS `dashboardmanagement` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `totalmembers` INT DEFAULT 0,                 -- legacy/base member count, added to live approved count
  `pending_applications` INT DEFAULT 0,         -- unused (dashboard shows the live count)
  `completedevents` INT DEFAULT 0,
  `others` INT DEFAULT 0,                       -- unused
  `gd_male` INT DEFAULT 0,                      -- manual gender split; 0/0 = use live members table
  `gd_female` INT DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Member Growth chart points (category = 'member_growth'), in display order by id
CREATE TABLE IF NOT EXISTS `chart_data` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `value` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Admin security: login throttling, password-reset links, activity log
-- (backend/includes/auth.php also creates these automatically if they are missing)
CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt_email` (`email`, `created_at`),
  KEY `idx_attempt_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_password_resets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `admin_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL,                -- sha256 of the emailed token; the token itself is never stored
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_token` (`token_hash`),
  KEY `idx_reset_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `admin_id` INT NULL,
  `admin_name` VARCHAR(100) NULL,
  `actor_role` VARCHAR(20) NULL,                 -- role at the time (Super Admin rows are hidden from others)
  `action` VARCHAR(60) NOT NULL,
  `target` VARCHAR(255) NULL,                    -- to whom / what
  `target_role` VARCHAR(20) NULL,                -- when the target is an admin
  `details` TEXT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin roles: per-account permission ticks (differences from the role defaults in roles.php)
CREATE TABLE IF NOT EXISTS `admin_permissions` (
  `admin_id` INT NOT NULL,
  `permission` VARCHAR(40) NOT NULL,
  `allowed` TINYINT(1) NOT NULL,                 -- 1 = granted, 0 = taken away
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Site lockdown schedule; the newest row with status 'scheduled' is the current one
CREATE TABLE IF NOT EXISTS `site_lockdown` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `starts_at` DATETIME NOT NULL,                 -- Dhaka time
  `notice` TEXT NOT NULL,                        -- shown to admins
  `public_message` VARCHAR(500) NULL,            -- shown to visitors while locked
  `status` VARCHAR(12) NOT NULL DEFAULT 'scheduled',  -- scheduled | cancelled | lifted
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` DATETIME NULL,
  `ended_by` INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lockdown_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small key/value settings (hero_video, recent board moves)
CREATE TABLE IF NOT EXISTS `site_settings` (
  `name` VARCHAR(60) NOT NULL,
  `value` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Past events uploaded in Website Media
CREATE TABLE IF NOT EXISTS `site_events` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `event_date` DATE NOT NULL,
  `place` VARCHAR(200) NULL,
  `organizer` VARCHAR(120) NULL,                 -- "By BUCuC" (shown under the date)
  `description` TEXT NULL,                       -- blank line = new paragraph; **bold**, *italic*
  `highlights` TEXT NULL,                        -- one per line
  `stats` TEXT NULL,                             -- number tiles, one per line: "400+ | Attendees"
  `image` VARCHAR(255) NOT NULL,                 -- path under frontend/, e.g. images/events/…
  `anchor` VARCHAR(60) NULL,                     -- old page anchor (#event-6 …) of the imported events
  `image_class` VARCHAR(60) NULL,                -- special photo framing of some imported events
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_date` (`event_date`),
  KEY `idx_event_anchor` (`anchor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Panel tenures (2026-10-18): each tenure has its own Presidential Panel and Secretary Panel.
-- Filled automatically from backend/Api/members.json the first time the site runs.
CREATE TABLE IF NOT EXISTS `tenures` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(20) NOT NULL,                  -- e.g. 2026-27
  `start_year` SMALLINT NOT NULL,                -- for ordering; the oldest is deleted at the 12-tenure limit
  `is_current` TINYINT(1) NOT NULL DEFAULT 0,    -- exactly one is shown first on the homepage
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenure_label` (`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tenure_people` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenure_id` INT NOT NULL,
  `grp` VARCHAR(10) NOT NULL,                    -- panel | secretary
  `name` VARCHAR(120) NOT NULL,
  `position` VARCHAR(120) NOT NULL DEFAULT '',
  `facebook` VARCHAR(255) NULL,
  `image` VARCHAR(255) NULL,                     -- path under frontend/
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_people_tenure` (`tenure_id`, `grp`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every non-notice e-mail sent (password e-mails, congratulations): daily limits per account. Also created by mailer_ensure().
CREATE TABLE IF NOT EXISTS `mail_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `account_key` VARCHAR(20) NOT NULL,
  `kind` VARCHAR(20) NOT NULL,
  `recipient` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_account` (`account_key`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Apply form submissions per IP (rate limit). Also created by signup_handler.php.
CREATE TABLE IF NOT EXISTS `signup_attempts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `saved` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup_ip` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sponsor banners (2026-10-20, Super Admin only). Also created by ads_ensure() in backend/includes/ads.php.
CREATE TABLE IF NOT EXISTS `ad_spots` (
  `spot` VARCHAR(20) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`spot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ads` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `spot` VARCHAR(20) NOT NULL,
  `advertiser` VARCHAR(120) NOT NULL,
  `link` VARCHAR(500) NOT NULL,
  `image` VARCHAR(255) NOT NULL,
  `image_mobile` VARCHAR(255) NULL,
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ads_live` (`spot`, `active`, `starts_on`, `ends_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ad_stats` (
  `ad_id` INT NOT NULL,
  `day` DATE NOT NULL,
  `views` INT NOT NULL DEFAULT 0,
  `clicks` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`ad_id`, `day`),
  CONSTRAINT `fk_ad_stats_ad` FOREIGN KEY (`ad_id`) REFERENCES `ads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ad_clicks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ad_id` INT NOT NULL,
  `ip_hash` CHAR(16) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ad_clicks` (`ad_id`, `ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Handover notes (2026-10-20). Also created by handover_ensure() in backend/includes/handover.php.
CREATE TABLE IF NOT EXISTS `handover_docs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenure_id` INT NULL,                              -- cleared (not deleted) when the tenure is removed
  `tenure_label` VARCHAR(20) NOT NULL,
  `unlocked` TINYINT(1) NOT NULL DEFAULT 0,
  `ends_on` DATE NULL,
  `reminder_sent_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_handover_label` (`tenure_label`),
  CONSTRAINT `fk_handover_tenure` FOREIGN KEY (`tenure_id`) REFERENCES `tenures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `handover_sections` (
  `doc_id` INT NOT NULL,
  `section_key` VARCHAR(40) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,                     -- text, or a JSON list of rows
  `version_id` INT NULL,
  `updated_by` INT NULL,
  `updated_by_role` VARCHAR(20) NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`doc_id`, `section_key`),
  CONSTRAINT `fk_handover_sec_doc` FOREIGN KEY (`doc_id`) REFERENCES `handover_docs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `handover_versions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `doc_id` INT NOT NULL,
  `section_key` VARCHAR(40) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `saved_by` INT NULL,
  `saved_by_role` VARCHAR(20) NULL,
  `saved_at` DATETIME NOT NULL,
  `note` VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_handover_ver` (`doc_id`, `section_key`, `id`),
  CONSTRAINT `fk_handover_ver_doc` FOREIGN KEY (`doc_id`) REFERENCES `handover_docs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance (2026-10-20). Also created by attendance_ensure() in backend/includes/attendance.php.
CREATE TABLE IF NOT EXISTS `attendance_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'rehearsal',   -- rehearsal | meeting | event | workshop | other
  `session_date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NULL,                              -- empty = ends at midnight
  `audience` VARCHAR(10) NOT NULL DEFAULT 'all',     -- all | GB | ASB | SB | pick
  `event_id` INT NULL,                               -- for the future events calendar
  `qr_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `checkin_secret` CHAR(64) NULL,
  `checkin_opened_at` DATETIME NULL,
  `checkin_closes_at` DATETIME NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_date` (`session_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_expected` (
  `session_id` INT NOT NULL,
  `member_id` INT NOT NULL,
  PRIMARY KEY (`session_id`, `member_id`),
  KEY `idx_att_exp_member` (`member_id`),
  CONSTRAINT `fk_att_exp_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_exp_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_records` (
  `session_id` INT NOT NULL,
  `member_id` INT NOT NULL,
  `status` VARCHAR(8) NOT NULL,                      -- present | late | excused | absent
  `method` VARCHAR(6) NOT NULL DEFAULT 'manual',     -- manual | qr
  `marked_by` INT NULL,
  `marked_at` DATETIME NOT NULL,
  PRIMARY KEY (`session_id`, `member_id`),
  KEY `idx_att_rec_member` (`member_id`),
  CONSTRAINT `fk_att_rec_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_rec_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attendance_checkin_fails` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_fail` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Performance gallery (2026-10-20): YouTube videos only (ID + details, no files). Also created by gallery_ensure().
CREATE TABLE IF NOT EXISTS `gallery_videos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `youtube_id` CHAR(11) NOT NULL,
  `start_seconds` INT NOT NULL DEFAULT 0,
  `title` VARCHAR(150) NOT NULL,
  `category` VARCHAR(20) NOT NULL DEFAULT 'other',  -- music_video | flash_mob | show_highlight | other
  `video_date` DATE NULL,
  `tenure_id` INT NULL,
  `description` TEXT NULL,
  `thumbnail` VARCHAR(255) NOT NULL,
  `is_short` TINYINT(1) NOT NULL DEFAULT 0,
  `featured` TINYINT(1) NOT NULL DEFAULT 0,
  `hidden` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gallery_order` (`hidden`, `featured`, `sort_order`),
  KEY `idx_gallery_yt` (`youtube_id`),
  CONSTRAINT `fk_gallery_tenure` FOREIGN KEY (`tenure_id`) REFERENCES `tenures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Homepage semester stats (2026-10-18): one row per application received / rejected
CREATE TABLE IF NOT EXISTS `application_events` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(10) NOT NULL,                   -- applied | rejected
  `university_id` VARCHAR(50) NULL,
  `applied_at` DATETIME NULL,                    -- when the person applied (semester stats go by this)
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appev` (`kind`, `applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email notices to members (2026-10-18): one job per notice, one row per recipient
CREATE TABLE IF NOT EXISTS `notices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `subject` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,                          -- with {name}, {first_name}, {student_id}, {level}
  `audience` VARCHAR(120) NOT NULL,              -- e.g. "All members", "GB members", "3 selected members"
  `sender_key` VARCHAR(20) NOT NULL DEFAULT 'club',  -- account in backend/config/mail_accounts.php (club | hr)
  `sender_email` VARCHAR(255) NULL,              -- the address it was sent from
  `members` INT NOT NULL DEFAULT 0,              -- members reached
  `total` INT NOT NULL DEFAULT 0,                -- e-mails (a member with Gmail + G-Suite counts twice)
  `sent` INT NOT NULL DEFAULT 0,
  `failed` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(12) NOT NULL DEFAULT 'sending',   -- sending | done
  `sent_by` INT NULL,
  `sent_by_name` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notice_recipients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `notice_id` INT NOT NULL,
  `member_id` INT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `student_id` VARCHAR(50) NULL,
  `level` VARCHAR(5) NULL,
  `status` VARCHAR(8) NOT NULL DEFAULT 'pending',    -- pending | sent | failed
  `error` VARCHAR(255) NULL,
  `sent_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_nr_notice` (`notice_id`, `status`),
  KEY `idx_nr_sent` (`status`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
