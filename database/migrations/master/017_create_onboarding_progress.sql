-- =============================================================
-- Migration: 017_create_onboarding_progress
-- Table tracks per-user onboarding wizard step completion.
-- Run against: master database
-- =============================================================

CREATE TABLE IF NOT EXISTS `onboarding_progress` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `client_id`           BIGINT UNSIGNED NOT NULL,

    -- Step flags (1 = completed, 0 = pending)
    `email_verified`      TINYINT(1)  NOT NULL DEFAULT 0,
    `phone_verified`      TINYINT(1)  NOT NULL DEFAULT 0,
    `first_agent_created` TINYINT(1)  NOT NULL DEFAULT 0,
    `lead_fields_set`     TINYINT(1)  NOT NULL DEFAULT 0,
    `dialer_configured`   TINYINT(1)  NOT NULL DEFAULT 0,

    -- Convenience: percentage done (0–100)
    `progress_pct`        TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,

    -- Timestamps
    `email_verified_at`      DATETIME DEFAULT NULL,
    `phone_verified_at`      DATETIME DEFAULT NULL,
    `first_agent_created_at` DATETIME DEFAULT NULL,
    `lead_fields_set_at`     DATETIME DEFAULT NULL,
    `dialer_configured_at`   DATETIME DEFAULT NULL,

    `completed_at`        DATETIME DEFAULT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_onboarding_user_client` (`user_id`, `client_id`),
    KEY `idx_onboarding_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
