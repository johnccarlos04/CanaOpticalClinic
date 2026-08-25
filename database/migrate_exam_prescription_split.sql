-- ================================================================
--  CANAOPTICALCLINIC — database/migrate_exam_prescription_split.sql
--  One-time data migration for the Consultation / Examination /
--  Prescription field-separation fix (see schema.sql's CREATE TABLE
--  comments for `consultations`, `examinations`, `prescriptions`,
--  which this migration brings an existing live database in line with).
--
--  Run this ONCE against the live DB, top to bottom, in order — later
--  steps depend on earlier ones (in particular, the DROP COLUMN
--  statements in Step 4 must run AFTER Step 3 has copied their data
--  over, or that data is gone for good). Safe to run against a fresh/
--  empty database too — every step is a no-op there.
--
--  This file is NOT auto-applied by the app. Apply it yourself the same
--  way you apply every other schema change here.
-- ================================================================

-- ── Step 1: add every new column first ─────────────────────────────
ALTER TABLE `consultations`
  ADD COLUMN `exam_id` VARCHAR(10) DEFAULT NULL AFTER `doctor_id`,
  ADD COLUMN `chief_complaint` TEXT DEFAULT NULL,
  ADD COLUMN `history_present_illness` TEXT DEFAULT NULL,
  ADD COLUMN `assessment` TEXT DEFAULT NULL,
  ADD COLUMN `recommendation` TEXT DEFAULT NULL,
  ADD COLUMN `follow_up_date` DATE DEFAULT NULL,
  ADD COLUMN `status` ENUM('completed','cancelled','no-show') NOT NULL DEFAULT 'completed';

ALTER TABLE `examinations`
  ADD COLUMN `consultation_id` VARCHAR(10) DEFAULT NULL AFTER `doctor_id`,
  ADD COLUMN `od_va_uncorrected` VARCHAR(10) AFTER `consultation_id`,
  ADD COLUMN `os_va_uncorrected` VARCHAR(10) AFTER `od_va`,
  ADD COLUMN `external_findings` TEXT DEFAULT NULL;
-- Rename the existing VA columns to make explicit they were always the
-- *corrected* reading — the uncorrected columns above are new.
ALTER TABLE `examinations`
  CHANGE COLUMN `od_va` `od_va_corrected` VARCHAR(10),
  CHANGE COLUMN `os_va` `os_va_corrected` VARCHAR(10);

ALTER TABLE `prescriptions`
  ADD COLUMN `exam_id` VARCHAR(10) DEFAULT NULL AFTER `doctor_id`,
  ADD COLUMN `expiry_date` DATE DEFAULT NULL,
  ADD COLUMN `status` ENUM('valid','expired','superseded') NOT NULL DEFAULT 'valid',
  ADD COLUMN `prc_license` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN `od_add` VARCHAR(10),
  ADD COLUMN `os_add` VARCHAR(10),
  ADD COLUMN `pd` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN `lens_material` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN `frame_selection` TEXT DEFAULT NULL,
  ADD COLUMN `lens_coating` TEXT DEFAULT NULL;

-- ── Step 2: backfill the new prescription fields for EXISTING rows ─────
-- expiry_date + status for every prescription that doesn't have one yet
UPDATE `prescriptions`
   SET `expiry_date` = DATE_ADD(`date`, INTERVAL 1 YEAR)
 WHERE `expiry_date` IS NULL;

UPDATE `prescriptions` p
   SET p.status = CASE WHEN p.expiry_date < CURDATE() THEN 'expired' ELSE 'valid' END
 WHERE p.status = 'valid';  -- don't touch a row already marked otherwise

-- Snapshot each prescription's issuing doctor's CURRENT PRC license number
-- — the best available approximation for historical rows, since there
-- was no snapshot taken at the actual time of issue before this fix.
UPDATE `prescriptions` p
  JOIN `doctors` d ON d.id = p.doctor_id
   SET p.prc_license = d.prc_license
 WHERE p.prc_license IS NULL;

-- ── Step 3: carry lens/material/coating/frame data that's currently ────
--   sitting on `examinations` rows over to a matching prescription row
--   (creating one if none exists) before those columns are dropped below.
-- 3a. Copy into an EXISTING prescription matched by patient+doctor+date —
--     the same heuristic viewPrescriptionDetail() (assets/js/main.js)
--     already uses today to relate a prescription back to its exam.
UPDATE `prescriptions` p
  JOIN `examinations` e
    ON e.patient_id = p.patient_id
   AND e.doctor_id  = p.doctor_id
   AND e.date       = p.date
   SET p.exam_id          = COALESCE(p.exam_id, e.id),
       p.lens_material    = COALESCE(p.lens_material, e.lens_material),
       p.frame_selection  = COALESCE(p.frame_selection, e.frame_selection),
       p.lens_coating     = COALESCE(p.lens_coating, e.lens_coating)
 WHERE (e.lens_type IS NOT NULL AND e.lens_type <> '')
    OR (e.lens_material IS NOT NULL AND e.lens_material <> '')
    OR (e.lens_coating IS NOT NULL AND e.lens_coating <> '')
    OR (e.frame_selection IS NOT NULL AND e.frame_selection <> '');

-- 3b. For exams that had lens/material/coating/frame data but no
--     matching prescription row at all, create one now so nothing is lost.
INSERT INTO `prescriptions`
  (`id`, `patient_id`, `doctor_id`, `exam_id`, `date`, `expiry_date`, `status`,
   `prc_license`, `od_sph`, `od_cyl`, `od_axis`, `od_add`,
   `os_sph`, `os_cyl`, `os_axis`, `os_add`, `pd`,
   `lens_type`, `lens_material`, `frame_selection`, `lens_coating`)
SELECT
  CONCAT('RX-MIG-', e.id), e.patient_id, e.doctor_id, e.id, e.date,
  DATE_ADD(e.date, INTERVAL 1 YEAR),
  CASE WHEN DATE_ADD(e.date, INTERVAL 1 YEAR) < CURDATE() THEN 'expired' ELSE 'valid' END,
  d.prc_license,
  e.od_sph, e.od_cyl, e.od_axis, e.od_add,
  e.os_sph, e.os_cyl, e.os_axis, e.os_add, e.pd,
  e.lens_type, e.lens_material, e.frame_selection, e.lens_coating
FROM `examinations` e
LEFT JOIN `doctors` d ON d.id = e.doctor_id
WHERE ((e.lens_type IS NOT NULL AND e.lens_type <> '')
    OR (e.lens_material IS NOT NULL AND e.lens_material <> '')
    OR (e.lens_coating IS NOT NULL AND e.lens_coating <> '')
    OR (e.frame_selection IS NOT NULL AND e.frame_selection <> ''))
  AND NOT EXISTS (
    SELECT 1 FROM `prescriptions` p2
     WHERE p2.patient_id = e.patient_id AND p2.doctor_id = e.doctor_id AND p2.date = e.date
  );

-- ── Step 4: now that lens/material/coating/frame data is safely copied
--   over, drop the columns that no longer belong on `examinations` or
--   `consultations`. Verify Step 3 above looks right before running this.
ALTER TABLE `examinations`
  DROP COLUMN `lens_type`,
  DROP COLUMN `lens_material`,
  DROP COLUMN `lens_coating`,
  DROP COLUMN `frame_selection`,
  DROP COLUMN `prescription_details`,
  DROP COLUMN `recommendation`;

ALTER TABLE `consultations`
  DROP COLUMN `diagnosis`,
  DROP COLUMN `prescription`;

ALTER TABLE `prescriptions`
  DROP COLUMN `remarks`;
