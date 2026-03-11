-- =============================================================
-- Migration: 024_widen_otp_columns
-- Widen otp columns to VARCHAR(255) to support bcrypt hashing.
-- Run against: master database
-- =============================================================

ALTER TABLE `email_otps` MODIFY COLUMN `otp` VARCHAR(255) NOT NULL;
ALTER TABLE `phone_otps` MODIFY COLUMN `otp` VARCHAR(255) NOT NULL;
