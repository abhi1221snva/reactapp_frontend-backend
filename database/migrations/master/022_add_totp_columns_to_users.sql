-- Migration 022: Add TOTP columns to users table
-- Columns added: is_2fa_google_enabled, is_2fa_phone_enabled, totp_enabled_at, totp_backup_codes_generated_at
-- Note: These statements may fail with "Duplicate column name" if columns already exist (safe to ignore)
ALTER TABLE `users` ADD COLUMN `is_2fa_google_enabled` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `is_2fa_phone_enabled` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `totp_enabled_at` DATETIME DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `totp_backup_codes_generated_at` DATETIME DEFAULT NULL;
