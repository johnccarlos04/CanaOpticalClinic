-- ================================================================
--  CANAOPTICALCLINIC — database/schema.sql
--  Full DDL for all tables.
--  Step 1: Create the database first:
--    CREATE DATABASE canaopticalclinic_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--  Step 2: Run this file in phpMyAdmin or MySQL CLI:
--    mysql -u root canaopticalclinic_db < schema.sql
--  Step 3: Run seed.php to populate data.
--
--  If upgrading an existing database, run these once in phpMyAdmin:
--    ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL DEFAULT NULL AFTER `is_active`;
--    ALTER TABLE `users` ADD COLUMN `photo_url` VARCHAR(255) NULL DEFAULT NULL AFTER `last_login_at`;
--    ALTER TABLE `users` ADD COLUMN `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `photo_url`;
--    ALTER TABLE `users` ADD COLUMN `locked_until` DATETIME NULL DEFAULT NULL AFTER `failed_attempts`;
--    ALTER TABLE `archived_records` MODIFY `id` VARCHAR(20) NOT NULL;
--    ALTER TABLE `archived_records` ADD COLUMN `data_json` TEXT NULL DEFAULT NULL AFTER `reason`;
--    ALTER TABLE `patients` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL;
--    ALTER TABLE `doctors`  ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL;
--    ALTER TABLE `staff`    ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL;
--    ALTER TABLE `admins`   ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL;
--    CREATE TABLE IF NOT EXISTS `qr_scan_log` (
--      `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
--      `scanned_by` INT UNSIGNED NULL,
--      `patient_id` VARCHAR(10)  NULL,
--      `found`      TINYINT(1)   NOT NULL DEFAULT 0,
--      `scanned_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
--      PRIMARY KEY (`id`),
--      FOREIGN KEY (`scanned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
--    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--    CREATE TABLE IF NOT EXISTS `contact_messages` (
--      `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
--      `name`       VARCHAR(150) NOT NULL,
--      `email`      VARCHAR(150) NOT NULL,
--      `service`    VARCHAR(100) NULL,
--      `message`    TEXT         NOT NULL,
--      `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
--      `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--      PRIMARY KEY (`id`),
--      INDEX `idx_read` (`is_read`)
--    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--    ALTER TABLE `contact_messages` ADD COLUMN `reply` TEXT NULL DEFAULT NULL;
--    ALTER TABLE `contact_messages` ADD COLUMN `replied_by` VARCHAR(150) NULL DEFAULT NULL;
--    ALTER TABLE `contact_messages` ADD COLUMN `replied_at` DATETIME NULL DEFAULT NULL;
--    ALTER TABLE `contact_messages` ADD INDEX `idx_email` (`email`);
--    ALTER TABLE `contact_messages` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL;
--    ALTER TABLE `clinic_settings` DROP COLUMN IF EXISTS `logo_name`;
--    ALTER TABLE `clinic_settings` ADD COLUMN `logo_url` VARCHAR(255) NULL DEFAULT NULL AFTER `phic_no`;
--    ALTER TABLE `clinic_settings` ADD COLUMN `hero_url` VARCHAR(255) NULL DEFAULT NULL AFTER `logo_url`;
--    ALTER TABLE `clinic_settings` ADD COLUMN `map_lat` DECIMAL(10,7) NULL DEFAULT NULL AFTER `hero_url`;
--    ALTER TABLE `clinic_settings` ADD COLUMN `map_lng` DECIMAL(10,7) NULL DEFAULT NULL AFTER `map_lat`;
--    CREATE TABLE IF NOT EXISTS `sessions` (
--      `id`          VARCHAR(128) NOT NULL,
--      `data`        MEDIUMTEXT   NOT NULL,
--      `last_access` INT UNSIGNED NOT NULL DEFAULT 0,
--      PRIMARY KEY (`id`)
--    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--    CREATE TABLE IF NOT EXISTS `password_resets` (
--      `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
--      `email`      VARCHAR(255) NOT NULL,
--      `otp`        VARCHAR(6)   NOT NULL,
--      `token`      VARCHAR(64)  NULL DEFAULT NULL,
--      `used`       TINYINT(1)   NOT NULL DEFAULT 0,
--      `expires_at` DATETIME     NOT NULL,
--      `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
--      PRIMARY KEY (`id`),
--      INDEX `idx_email_otp` (`email`, `otp`),
--      INDEX `idx_token` (`token`)
--    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--    CREATE TABLE IF NOT EXISTS `blocked_dates` (
--      `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
--      `doctor_id`  VARCHAR(10)  NOT NULL,
--      `date`       DATE         NOT NULL,
--      `reason`     VARCHAR(255) DEFAULT NULL,
--      `created_by` VARCHAR(100) DEFAULT NULL,
--      `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--      PRIMARY KEY (`id`),
--      UNIQUE KEY `uq_doctor_date` (`doctor_id`, `date`),
--      FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE
--    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--    ALTER TABLE `doctors` ADD COLUMN `prc_license` VARCHAR(50) NULL DEFAULT NULL AFTER `specialization`;
--    ALTER TABLE `patients` ADD COLUMN `middle_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`;
--    ALTER TABLE `doctors` ADD COLUMN `middle_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`;
--    ALTER TABLE `staff` ADD COLUMN `middle_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`;
--    ALTER TABLE `admins` ADD COLUMN `middle_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`;
--    ALTER TABLE `pending_registrations` ADD COLUMN `middle_name` VARCHAR(100) NULL DEFAULT NULL AFTER `first_name`;
--    ALTER TABLE `activity_log` ADD COLUMN `ip_address` VARCHAR(45) NULL DEFAULT NULL AFTER `type`;
--    ALTER TABLE `appointments` ADD COLUMN `terms_agreed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reschedule_request`;
--    ALTER TABLE `patients` ADD COLUMN `no_show_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`;
--    ALTER TABLE `patients` ADD COLUMN `booking_restricted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `no_show_count`;
--    ALTER TABLE `appointments` ADD COLUMN `reminder_sent_at` DATETIME NULL DEFAULT NULL AFTER `terms_agreed`;
--    ALTER TABLE `appointments` ADD COLUMN `confirmed_at` DATETIME NULL DEFAULT NULL AFTER `reminder_sent_at`;
--    ALTER TABLE `appointments` ADD COLUMN `rescheduled_at` DATETIME NULL DEFAULT NULL AFTER `confirmed_at`;
--    ALTER TABLE `notifications` ADD COLUMN `related_id` VARCHAR(10) NULL DEFAULT NULL AFTER `body`;
--    CREATE TABLE IF NOT EXISTS `appointment_waitlist` (
--      `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
--      `patient_id`       VARCHAR(10)  NOT NULL,
--      `patient_name`     VARCHAR(200) DEFAULT NULL,
--      `doctor_id`        VARCHAR(10)  NOT NULL,
--      `doctor_name`      VARCHAR(200) DEFAULT NULL,
--      `date`             DATE         NOT NULL,
--      `time`             VARCHAR(20)  NOT NULL,
--      `type`             VARCHAR(100) DEFAULT NULL,
--      `terms_agreed`     TINYINT(1)   NOT NULL DEFAULT 0,
--      `status`           ENUM('waiting','offered','claimed','expired','declined','cancelled') NOT NULL DEFAULT 'waiting',
--      `offered_at`       DATETIME     NULL DEFAULT NULL,
--      `offer_expires_at` DATETIME     NULL DEFAULT NULL,
--      `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--      PRIMARY KEY (`id`),
--      INDEX `idx_slot` (`doctor_id`, `date`, `time`, `status`),
--      FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
--    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Users (central authentication table) ─────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `email`         VARCHAR(255)     NOT NULL,
  `password_hash` VARCHAR(255)     NOT NULL,
  `role`          ENUM('admin','staff','doctor','patient') NOT NULL,
  `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
  `last_login_at` DATETIME         NULL     DEFAULT NULL,
  `photo_url`     VARCHAR(255)     NULL     DEFAULT NULL,
  `failed_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`     DATETIME         NULL     DEFAULT NULL,
  `email_verified`   TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Admins ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admins` (
  `id`          VARCHAR(10)  NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `first_name`  VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) NULL DEFAULT NULL,
  `last_name`   VARCHAR(100) NOT NULL,
  `contact`     VARCHAR(20)  DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `archived_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Staff ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `staff` (
  `id`          VARCHAR(10)  NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `first_name`  VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) NULL DEFAULT NULL,
  `last_name`   VARCHAR(100) NOT NULL,
  `contact`     VARCHAR(20)  DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `archived_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Doctors ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `doctors` (
  `id`             VARCHAR(10)  NOT NULL,
  `user_id`        INT UNSIGNED NULL,
  `first_name`     VARCHAR(100) NOT NULL,
  `middle_name`    VARCHAR(100) NULL DEFAULT NULL,
  `last_name`      VARCHAR(100) NOT NULL,
  `specialization` VARCHAR(100) NOT NULL DEFAULT 'Optometrist',
  `degree`         VARCHAR(30)  NOT NULL DEFAULT 'OD',
  `prc_license`    VARCHAR(50)  NULL DEFAULT NULL,
  `contact`        VARCHAR(20)  DEFAULT NULL,
  `available`      TINYINT(1)   NOT NULL DEFAULT 1,
  `work_hours`     VARCHAR(100) DEFAULT NULL,
  `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `archived_at`    DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctor_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Doctor schedule days ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `doctor_days` (
  `doctor_id`   VARCHAR(10)  NOT NULL,
  `day_of_week` ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
  PRIMARY KEY (`doctor_id`, `day_of_week`),
  FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Doctor blocked dates (one-off unavailability — leave, conference,
--    holiday, etc. — distinct from the recurring weekly schedule above) ──
CREATE TABLE IF NOT EXISTS `blocked_dates` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doctor_id`  VARCHAR(10)  NOT NULL,
  `date`       DATE         NOT NULL,
  `reason`     VARCHAR(255) DEFAULT NULL,
  `created_by` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctor_date` (`doctor_id`, `date`),
  FOREIGN KEY (`doctor_id`) REFERENCES `doctors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Patients ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `patients` (
  `id`              VARCHAR(10)  NOT NULL,
  `user_id`         INT UNSIGNED NULL,
  `first_name`      VARCHAR(100) NOT NULL,
  `middle_name`     VARCHAR(100) NULL DEFAULT NULL,
  `last_name`       VARCHAR(100) NOT NULL,
  `gender`          ENUM('Male','Female','Other') NOT NULL,
  `dob`             DATE         NOT NULL,
  `age`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `contact`         VARCHAR(20)  DEFAULT NULL,
  `address`         TEXT         DEFAULT NULL,
  `blood_type`      VARCHAR(10)  NOT NULL DEFAULT 'Unknown',
  `occupation`      VARCHAR(100) DEFAULT NULL,
  `medical_history` TEXT         DEFAULT NULL,
  `optical_history` TEXT         DEFAULT NULL,
  `qr_data`         VARCHAR(150) DEFAULT NULL,
  `registered_date` DATE         DEFAULT NULL,
  `last_visit`      DATE         DEFAULT NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `no_show_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `booking_restricted` TINYINT(1)   NOT NULL DEFAULT 0,
  `archived_at`     DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_patient_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Appointments ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `appointments` (
  `id`                   VARCHAR(10)  NOT NULL,
  `patient_id`           VARCHAR(10)  DEFAULT NULL,
  `patient_name`         VARCHAR(200) DEFAULT NULL,
  `doctor_id`            VARCHAR(10)  DEFAULT NULL,
  `doctor_name`          VARCHAR(200) DEFAULT NULL,
  `date`                 DATE         NOT NULL,
  `time`                 VARCHAR(20)  NOT NULL,
  `type`                 VARCHAR(100) DEFAULT NULL,
  `status`               ENUM('pending','approved','cancelled','disapproved','completed','no-show') NOT NULL DEFAULT 'pending',
  `notes`                TEXT         DEFAULT NULL,
  `cancellation_reason`  TEXT         DEFAULT NULL,
  `disapproval_reason`   TEXT         DEFAULT NULL,
  `reschedule_note`      TEXT         DEFAULT NULL,
  `reschedule_request`   TEXT         DEFAULT NULL,   -- JSON: {reason,preferredDate,requestedAt}
  `terms_agreed`         TINYINT(1)   NOT NULL DEFAULT 0, -- patient accepted the booking policy at request time
  `reminder_sent_at`     DATETIME     NULL DEFAULT NULL, -- day-before-noon reminder was sent
  `confirmed_at`         DATETIME     NULL DEFAULT NULL, -- patient confirmed attendance after the reminder
  `rescheduled_at`       DATETIME     NULL DEFAULT NULL, -- staff/admin last moved this appointment's date/time
  PRIMARY KEY (`id`),
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`)  REFERENCES `doctors`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Appointment Waitlist ─────────────────────────────────────────
-- One row per patient waiting for a specific doctor+date+time slot that
-- was full at request time. When that exact slot frees up, the oldest
-- 'waiting' row is offered it for a limited window before moving on to
-- the next patient in line.
CREATE TABLE IF NOT EXISTS `appointment_waitlist` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id`       VARCHAR(10)  NOT NULL,
  `patient_name`     VARCHAR(200) DEFAULT NULL,
  `doctor_id`        VARCHAR(10)  NOT NULL,
  `doctor_name`      VARCHAR(200) DEFAULT NULL,
  `date`             DATE         NOT NULL,
  `time`             VARCHAR(20)  NOT NULL,
  `type`             VARCHAR(100) DEFAULT NULL,
  `terms_agreed`     TINYINT(1)   NOT NULL DEFAULT 0,
  `status`           ENUM('waiting','offered','claimed','expired','declined','cancelled') NOT NULL DEFAULT 'waiting',
  `offered_at`       DATETIME     NULL DEFAULT NULL,
  `offer_expires_at` DATETIME     NULL DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_slot` (`doctor_id`, `date`, `time`, `status`),
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Optical Examinations ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `examinations` (
  `id`                   VARCHAR(10)  NOT NULL,
  `patient_id`           VARCHAR(10)  DEFAULT NULL,
  `doctor_id`            VARCHAR(10)  DEFAULT NULL,
  `date`                 DATE         NOT NULL,
  -- Right eye (OD)
  `od_sph`  VARCHAR(10), `od_cyl` VARCHAR(10), `od_axis` VARCHAR(10),
  `od_va`   VARCHAR(10), `od_add` VARCHAR(10),
  -- Left eye (OS)
  `os_sph`  VARCHAR(10), `os_cyl` VARCHAR(10), `os_axis` VARCHAR(10),
  `os_va`   VARCHAR(10), `os_add` VARCHAR(10),
  -- Intraocular pressure & pupillary distance
  `iop_od`               VARCHAR(10)  DEFAULT NULL,
  `iop_os`               VARCHAR(10)  DEFAULT NULL,
  `pd`                   VARCHAR(20)  DEFAULT NULL,
  -- Clinical findings
  `diagnosis`            TEXT         DEFAULT NULL,
  `recommendation`       TEXT         DEFAULT NULL,
  `test_results`         TEXT         DEFAULT NULL,
  `prescription_details` TEXT         DEFAULT NULL,
  -- Lens / frame
  `lens_type`            VARCHAR(50)  DEFAULT NULL,
  `lens_material`        VARCHAR(50)  DEFAULT NULL,
  `lens_coating`         TEXT         DEFAULT NULL,  -- comma-separated
  `frame_selection`      TEXT         DEFAULT NULL,
  `remarks`              TEXT         DEFAULT NULL,
  `status`               ENUM('pending','completed') NOT NULL DEFAULT 'completed',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`)  REFERENCES `doctors`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Prescriptions ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `prescriptions` (
  `id`         VARCHAR(10)  NOT NULL,
  `patient_id` VARCHAR(10)  DEFAULT NULL,
  `doctor_id`  VARCHAR(10)  DEFAULT NULL,
  `date`       DATE         NOT NULL,
  `od_sph`     VARCHAR(10), `od_cyl` VARCHAR(10), `od_axis` VARCHAR(10),
  `os_sph`     VARCHAR(10), `os_cyl` VARCHAR(10), `os_axis` VARCHAR(10),
  `lens_type`  VARCHAR(100) DEFAULT NULL,
  `remarks`    TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`)  REFERENCES `doctors`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Consultations ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `consultations` (
  `id`           VARCHAR(10)  NOT NULL,
  `patient_id`   VARCHAR(10)  DEFAULT NULL,
  `doctor_id`    VARCHAR(10)  DEFAULT NULL,
  `date`         DATE         NOT NULL,
  `type`         VARCHAR(100) DEFAULT NULL,
  `diagnosis`    TEXT         DEFAULT NULL,
  `prescription` TEXT         DEFAULT NULL,
  `remarks`      TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`)  REFERENCES `doctors`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Notifications ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED  NOT NULL,
  `type`       VARCHAR(50)   NOT NULL,
  `title`      VARCHAR(255)  NOT NULL,
  `body`       TEXT          NOT NULL,
  `related_id` VARCHAR(10)   NULL DEFAULT NULL, -- e.g. the appointment ID this notification is about, when there is one
  `is_read`    TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Contact Messages (public contact-form submissions) ─────────────
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `email`       VARCHAR(150) NOT NULL,
  `service`     VARCHAR(100) NULL,
  `message`     TEXT         NOT NULL,
  `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `reply`       TEXT         NULL,
  `replied_by`  VARCHAR(150) NULL,
  `replied_at`  DATETIME     NULL,
  `archived_at` DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_read` (`is_read`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Activity Log ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`        VARCHAR(20)  NOT NULL,
  `users_id`  INT UNSIGNED DEFAULT NULL,
  `user_name` VARCHAR(100) DEFAULT NULL,
  `role`      VARCHAR(20)  DEFAULT NULL,
  `action`    TEXT         DEFAULT NULL,
  `timestamp` DATETIME     DEFAULT NULL,
  `type`      VARCHAR(50)  DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_al_user` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── QR Scan Log ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `qr_scan_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scanned_by` INT UNSIGNED NULL,
  `patient_id` VARCHAR(10)  NULL,
  `found`      TINYINT(1)   NOT NULL DEFAULT 0,
  `scanned_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`scanned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Clinic Services ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `clinic_services` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `duration`    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `icon`        VARCHAR(50)  DEFAULT NULL,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Clinic Settings (single row, id always 1 — info + consultation rules) ──
CREATE TABLE IF NOT EXISTS `clinic_settings` (
  `id`                            TINYINT UNSIGNED NOT NULL,
  `name`                          VARCHAR(150) NOT NULL DEFAULT 'Cana Optical Clinic',
  `tagline`                       VARCHAR(255) NULL DEFAULT NULL,
  `address`                       VARCHAR(255) NULL DEFAULT NULL,
  `phone`                         VARCHAR(30)  NULL DEFAULT NULL,
  `email`                         VARCHAR(150) NULL DEFAULT NULL,
  `hours`                         VARCHAR(150) NULL DEFAULT NULL,
  `tin_no`                        VARCHAR(50)  NULL DEFAULT NULL,
  `phic_no`                       VARCHAR(50)  NULL DEFAULT NULL,
  `logo_url`                      VARCHAR(255) NULL DEFAULT NULL,
  `default_duration`              VARCHAR(20)  NOT NULL DEFAULT '30 min',
  `max_advance_booking`           VARCHAR(20)  NOT NULL DEFAULT '3 months',
  `min_advance_booking`           VARCHAR(20)  NOT NULL DEFAULT '1 day',
  `max_appts_per_doctor_per_day`  SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `morning_start`                 VARCHAR(20)  NOT NULL DEFAULT '8:00 AM',
  `morning_end`                   VARCHAR(20)  NOT NULL DEFAULT '12:00 PM',
  `afternoon_start`               VARCHAR(20)  NOT NULL DEFAULT '1:00 PM',
  `afternoon_end`                 VARCHAR(20)  NOT NULL DEFAULT '5:00 PM',
  `lunch_break`                   TINYINT(1)   NOT NULL DEFAULT 1,
  `clinic_days`                   VARCHAR(255)      NOT NULL DEFAULT 'Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
  `gallery_max_photos`            TINYINT UNSIGNED  NULL     DEFAULT NULL,
  `founded_year`                  SMALLINT          NULL     DEFAULT NULL,
  `terms_content`                 MEDIUMTEXT        NULL     DEFAULT NULL,
  `appointment_policy_content`    MEDIUMTEXT        NULL     DEFAULT NULL,
  `reminder_time`                 VARCHAR(20)  NOT NULL DEFAULT '12:00 PM', -- day-before reminder send time
  `confirm_deadline_time`         VARCHAR(20)  NOT NULL DEFAULT '9:00 PM',  -- same-day confirm-or-auto-cancel deadline
  `waitlist_offer_hours`          TINYINT UNSIGNED NOT NULL DEFAULT 3,      -- how long a freed slot stays claimable, and the lead-time cutoff below which a "waiting" entry is removed as no longer fulfillable
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Archived Records ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `archived_records` (
  `id`          VARCHAR(20)  NOT NULL,
  `type`        VARCHAR(50)  DEFAULT NULL,
  `name`        VARCHAR(255) DEFAULT NULL,
  `ref_id`      VARCHAR(20)  DEFAULT NULL,
  `archived_by` VARCHAR(100) DEFAULT NULL,
  `reason`      TEXT         DEFAULT NULL,
  `data_json`   TEXT         DEFAULT NULL,
  `date`        VARCHAR(50)  DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PHP Sessions (DB-backed so logins survive container restarts /
--    multiple replicas on hosts like Railway, instead of relying on
--    the local filesystem) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`          VARCHAR(128) NOT NULL,
  `data`        MEDIUMTEXT   NOT NULL,
  `last_access` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Password Resets (forgot-password OTP + reset-token flow) ──────
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `email`          VARCHAR(255)     NOT NULL,
  `otp`            VARCHAR(6)       NOT NULL,
  `token`          VARCHAR(64)      NULL DEFAULT NULL,
  `used`           TINYINT(1)       NOT NULL DEFAULT 0,
  `attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `total_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `blocked_until`  DATETIME         NULL DEFAULT NULL,
  `expires_at`     DATETIME         NOT NULL,
  `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email_otp` (`email`, `otp`),
  INDEX `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--    ALTER TABLE `password_resets` ADD COLUMN IF NOT EXISTS `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0;
--    ALTER TABLE `password_resets` ADD COLUMN IF NOT EXISTS `total_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `attempts`;
--    ALTER TABLE `password_resets` ADD COLUMN IF NOT EXISTS `blocked_until` DATETIME NULL DEFAULT NULL AFTER `total_attempts`;

-- ── Password History (reuse prevention) ───────────────────────────
-- Stores each user's previously-used password hashes so a new password
-- can be checked against their recent history, not just the current one.
CREATE TABLE IF NOT EXISTS `password_history` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `users_id`      INT UNSIGNED NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ph_user` (`users_id`),
  FOREIGN KEY (`users_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pending Registrations (staging until OTP confirmed) ───────────
-- Self-registration form data lives here until the OTP is verified.
-- Only then are rows inserted into users + patients. This prevents
-- ghost accounts from fake or mistyped email addresses.
CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `email`         VARCHAR(255)     NOT NULL,
  `first_name`    VARCHAR(100)     NOT NULL,
  `middle_name`   VARCHAR(100)     NULL DEFAULT NULL,
  `last_name`     VARCHAR(100)     NOT NULL,
  `dob`           DATE             NOT NULL,
  `gender`        VARCHAR(20)      NOT NULL,
  `address`       TEXT             NOT NULL,
  `contact`       VARCHAR(50)      NOT NULL,
  `blood_type`    VARCHAR(10)      NOT NULL DEFAULT 'Unknown',
  `password_hash` VARCHAR(255)     NOT NULL,
  `otp`           VARCHAR(6)       NOT NULL,
  `attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `total_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at`     DATETIME         NOT NULL,
  `created_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pending_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Rate Limits (IP-based, keyed by endpoint) ────────────────────
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45)     NOT NULL,
  `endpoint`   VARCHAR(64)     NOT NULL,
  `created_at` INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_rl_lookup` (`ip`, `endpoint`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── About Gallery (carousel photos on public homepage) ───────────
CREATE TABLE IF NOT EXISTS `about_gallery` (
  `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `caption`    VARCHAR(255)     NULL     DEFAULT NULL,
  `filename`   VARCHAR(255)     NOT NULL DEFAULT '',
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Migrations (run once on existing databases) ───────────────────
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `gallery_max_photos` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `clinic_days`;
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `founded_year` SMALLINT NULL DEFAULT NULL AFTER `gallery_max_photos`;
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `terms_content` MEDIUMTEXT NULL DEFAULT NULL AFTER `founded_year`;
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `appointment_policy_content` MEDIUMTEXT NULL DEFAULT NULL AFTER `terms_content`;
--    ALTER TABLE `clinic_services` ADD COLUMN IF NOT EXISTS `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `icon`;
--    UPDATE `clinic_services` SET sort_order = id WHERE sort_order = 0;
--    ALTER TABLE `about_gallery` ADD COLUMN `filename` VARCHAR(255) NOT NULL DEFAULT '' AFTER `caption`, DROP COLUMN `image_data`, DROP COLUMN `mime_type`;
--    ALTER TABLE `doctors` ADD COLUMN IF NOT EXISTS `degree` VARCHAR(30) NOT NULL DEFAULT 'OD' AFTER `specialization`;
--    ALTER TABLE `doctors` ADD COLUMN IF NOT EXISTS `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `work_hours`;
--    ALTER TABLE `appointments` MODIFY COLUMN `status` ENUM('pending','approved','cancelled','disapproved','completed','no-show') NOT NULL DEFAULT 'pending';
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `video_url` VARCHAR(500) NULL DEFAULT NULL AFTER `map_embed_url`;
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `reminder_time` VARCHAR(20) NOT NULL DEFAULT '12:00 PM' AFTER `appointment_policy_content`;
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `confirm_deadline_time` VARCHAR(20) NOT NULL DEFAULT '9:00 PM' AFTER `reminder_time`;
--    ALTER TABLE `clinic_settings` ADD COLUMN IF NOT EXISTS `waitlist_offer_hours` TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `confirm_deadline_time`;

SET FOREIGN_KEY_CHECKS = 1;
