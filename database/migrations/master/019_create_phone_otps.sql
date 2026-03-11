-- =============================================================
-- Migration: 019_create_phone_otps
-- Stores phone OTP records for registration flow.
-- Run against: master database
-- =============================================================

CREATE TABLE IF NOT EXISTS `phone_otps` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone`       VARCHAR(20)     NOT NULL,
    `otp`         VARCHAR(6)      NOT NULL,
    `expires_at`  DATETIME        NOT NULL,
    `verified`    TINYINT(1)      NOT NULL DEFAULT 0,
    `attempts`    TINYINT(3)      NOT NULL DEFAULT 0,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_phone_otps_phone`      (`phone`),
    KEY `idx_phone_otps_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
