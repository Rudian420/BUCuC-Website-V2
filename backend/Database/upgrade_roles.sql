-- BUCuC website — admin roles, permissions, lockdown, media (13 Oct 2026).
-- For a live database that already ran upgrade.sql before 13 Oct 2026. Run ONCE in phpMyAdmin
-- (select the database → SQL tab → paste → Go), right after uploading the new code. Back up first.
-- Running it a second time stops at "Duplicate key name 'uq_admin_role'"; that's harmless.

-- Admin roles: five roles, one account each.
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
  `description` TEXT NULL,                       -- blank line = new paragraph
  `highlights` TEXT NULL,                        -- one per line
  `image` VARCHAR(255) NOT NULL,                 -- path under frontend/, e.g. images/events/…
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_event_date` (`event_date`)
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
