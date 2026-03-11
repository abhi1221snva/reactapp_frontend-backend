CREATE TABLE IF NOT EXISTS `totp_backup_codes` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     BIGINT UNSIGNED NOT NULL,
    `code_hash`   VARCHAR(255)    NOT NULL,
    `used`        TINYINT(1)      NOT NULL DEFAULT 0,
    `used_at`     DATETIME            DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_totp_backup_user` (`user_id`),
    KEY `idx_totp_backup_used`  (`used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
