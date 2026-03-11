-- =============================================================
-- Migration: 020_create_registration_logs
-- Audit log for every step in the registration flow.
-- Run against: master database
-- =============================================================

CREATE TABLE IF NOT EXISTS `registration_logs` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `registration_id`  BIGINT UNSIGNED     DEFAULT NULL COMMENT 'FK to prospect_initial_data.id',
    `step`             VARCHAR(60)     NOT NULL COMMENT 'e.g. registration_started, email_otp_sent, ...',
    `email`            VARCHAR(255)        DEFAULT NULL,
    `phone`            VARCHAR(30)         DEFAULT NULL,
    `request_payload`  JSON                DEFAULT NULL,
    `response_payload` JSON                DEFAULT NULL,
    `status`           VARCHAR(20)     NOT NULL DEFAULT 'success' COMMENT 'success | failure',
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_reglogs_email`           (`email`),
    KEY `idx_reglogs_registration_id` (`registration_id`),
    KEY `idx_reglogs_step`            (`step`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
