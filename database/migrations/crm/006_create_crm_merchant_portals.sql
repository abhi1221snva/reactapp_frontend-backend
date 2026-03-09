-- CRM Migration 006: Create crm_merchant_portals — Merchant Portal Access Registry
-- Formalizes unique_token + unique_url from crm_lead_data into a proper access control table
-- Backfill script (010) will populate from existing crm_lead_data rows

CREATE TABLE IF NOT EXISTS `crm_merchant_portals` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`          BIGINT UNSIGNED NOT NULL,
  `client_id`        INT UNSIGNED    NOT NULL COMMENT 'Tenant ID',
  `token`            VARCHAR(64)     NOT NULL COMMENT 'Unique access token for merchant',
  `url`              VARCHAR(500)    NOT NULL COMMENT 'Full merchant portal URL',
  `status`           TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1=active, 0=revoked',
  `last_accessed_at` TIMESTAMP       NULL     COMMENT 'When merchant last visited the portal',
  `access_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `notified_at`      TIMESTAMP       NULL     COMMENT 'When merchant was emailed the link',
  `expires_at`       TIMESTAMP       NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token`  (`token`),
  KEY `idx_lead_id`      (`lead_id`),
  KEY `idx_client_id`    (`client_id`),
  KEY `idx_status`       (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Merchant portal access tokens with audit tracking per lead';
