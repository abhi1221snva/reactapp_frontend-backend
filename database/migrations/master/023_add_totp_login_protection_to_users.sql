-- Migration 023: Add TOTP login brute-force protection columns to users table
-- totp_login_attempts: counts failed TOTP verify attempts per session
-- totp_login_locked_until: if non-null, TOTP is locked until this datetime
-- Safe to re-run: duplicate column errors can be safely ignored.
ALTER TABLE `users` ADD COLUMN `totp_login_attempts` TINYINT(3) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `totp_login_locked_until` DATETIME DEFAULT NULL;
