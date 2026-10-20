-- BUCuC website — upgrade an EXISTING live database to what the current code expects.
-- Safe to run once in phpMyAdmin (select the database first, then "SQL" tab → paste → Go).
-- Take an export/backup first.

-- 1. Approving an applicant without a date of birth used to fail ("date_of_birth cannot be null").
--    Also widen columns so every pending/shortlisted value fits in members.
ALTER TABLE `members`
  MODIFY `full_name` VARCHAR(255) NOT NULL,
  MODIFY `university_id` VARCHAR(50) NOT NULL,
  MODIFY `email` VARCHAR(255) NOT NULL,
  MODIFY `gsuite_email` VARCHAR(255) NULL,
  MODIFY `semester` VARCHAR(50) NOT NULL,
  MODIFY `date_of_birth` DATE NULL,
  MODIFY `facebook_url` TEXT NULL;

ALTER TABLE `pending_applications` MODIFY `date_of_birth` DATE NULL;
ALTER TABLE `shortlisted_members`  MODIFY `date_of_birth` DATE NULL;

-- 2. Faster duplicate checks on the Apply form
ALTER TABLE `pending_applications` ADD INDEX `idx_pending_uid` (`university_id`), ADD INDEX `idx_pending_gsuite` (`gsuite_email`);

-- 3. Make sure the venue and signup-switch tables exist
CREATE TABLE IF NOT EXISTS `venuinfo` (
  `venue_id` INT NOT NULL AUTO_INCREMENT,
  `venue_name` VARCHAR(255) NOT NULL,
  `venue_location` VARCHAR(255) NOT NULL,
  `venue_dateTime` DATETIME NOT NULL,
  `venue_startingTime` VARCHAR(20) NOT NULL,
  `venue_endingTime` VARCHAR(20) NOT NULL,
  `venu_ampm` VARCHAR(5) NOT NULL DEFAULT 'PM',
  PRIMARY KEY (`venue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `signup_status` (
  `id` INT NOT NULL DEFAULT 1,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT IGNORE INTO `signup_status` (`id`, `is_enabled`, `updated_by`) VALUES (1, 1, 'system');

-- 3b. Admin security: login throttling, password-reset links, activity log
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
  `action` VARCHAR(60) NOT NULL,
  `details` TEXT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_created` (`created_at`),
  KEY `idx_activity_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3c. Admin roles (October 2026): five roles, one account each. ALSO IN upgrade_roles.sql:
--     if you already ran this file before 13 Oct 2026, run only upgrade_roles.sql.
--     Old roles: one 'main_admin' becomes the Super Admin; every other account gets NO role and
--     can't log in until the Super Admin assigns one in Admin Management.
--     Put the e-mail of the account that should be Super Admin between the quotes below
--     (leave it empty to use the oldest 'main_admin' account).
SET @super_email := '';
ALTER TABLE `adminpanel` MODIFY `role` VARCHAR(20) NULL DEFAULT NULL;
SET @super_id := (SELECT `id` FROM `adminpanel` WHERE @super_email <> '' AND `email` = @super_email LIMIT 1);
SET @super_id := COALESCE(@super_id, (SELECT MIN(`id`) FROM `adminpanel` WHERE `role` = 'main_admin'));
UPDATE `adminpanel` SET `role` = NULL WHERE `role` IN ('main_admin', 'admin') AND `id` <> COALESCE(@super_id, 0);
UPDATE `adminpanel` SET `role` = 'super_admin', `status` = 'active' WHERE `id` = @super_id;
ALTER TABLE `adminpanel` ADD UNIQUE KEY `uq_admin_role` (`role`);

-- (The website adds the new activity-log columns actor_role / target / target_role, and members.position
--  (GB / ASB / SB), by itself the first time it runs.)

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

-- 4. Hostinger runs MySQL on Linux, where table names are case-sensitive. The code uses `venuinfo`
--    (all lowercase, as in the live database). Only if phpMyAdmin lists a table called `venuInfo`
--    (capital I) that holds your venue rows, run these two lines on their own:
-- DROP TABLE IF EXISTS `venuinfo`;
-- RENAME TABLE `venuInfo` TO `venuinfo`;

-- 5. Clean-up: these old objects are not used by any page. event_participants and performance_metrics
--    also hold foreign keys that can block deleting members or admins. (Back up first: instruction.md
--    section "Backup and restore".)
DROP VIEW IF EXISTS `event_gender_summary`, `gender_distribution_view`, `member_statistics`;
DROP TABLE IF EXISTS `event_participants`, `performance_metrics`, `events`, `application_settings`;

-- Foreign keys and indexes on the newer tables (tenures, permissions, notices, password resets) are
-- added by the site itself the first time an admin page loads (backend/includes/site.php, schema v6).
