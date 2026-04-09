-- =============================================================
-- Migration: 025_create_system_email_templates
-- Stores system-level email templates (forgot password, welcome, OTP, etc.)
-- Editable via admin UI; rendered with placeholder substitution.
-- Run against: master database
-- =============================================================

CREATE TABLE IF NOT EXISTS `system_email_templates` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `template_key`  VARCHAR(50)     NOT NULL,
    `template_name` VARCHAR(100)    NOT NULL,
    `subject`       VARCHAR(255)    NOT NULL,
    `body_html`     LONGTEXT        NOT NULL,
    `placeholders`  JSON            NULL     COMMENT 'Available placeholder definitions [{key,label,sample}]',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `updated_by`    INT UNSIGNED    NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_template_key` (`template_key`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
