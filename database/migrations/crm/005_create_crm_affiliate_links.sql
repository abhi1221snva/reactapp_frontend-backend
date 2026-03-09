-- CRM Migration 005: Create crm_affiliate_links — Affiliate Link Registry
-- Formalizes the ad-hoc users.affiliate_link column into a proper tracked table
-- Backfill script (011) will populate from existing users.affiliate_link values

CREATE TABLE IF NOT EXISTS `crm_affiliate_links` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED    NOT NULL COMMENT 'Affiliate agent/user who owns this link',
  `client_id`    INT UNSIGNED    NOT NULL COMMENT 'Tenant ID mirrors parent_id',
  `extension_id` VARCHAR(50)     NOT NULL COMMENT 'SIP extension used in URL path',
  `token`        VARCHAR(64)     NOT NULL COMMENT 'URL-safe random token (unique per link)',
  `full_path`    VARCHAR(500)    NOT NULL COMMENT 'Path: /client_id/extension_id/token',
  `label`        VARCHAR(255)    NULL     COMMENT 'Human name e.g. "Facebook Campaign June"',
  `utm_source`   VARCHAR(100)    NULL,
  `utm_medium`   VARCHAR(100)    NULL,
  `utm_campaign` VARCHAR(100)    NULL,
  `redirect_url` VARCHAR(500)    NULL     COMMENT 'Optional custom landing page override',
  `status`       TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1=active, 0=deactivated',
  `total_clicks` INT UNSIGNED    NOT NULL DEFAULT 0,
  `total_leads`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `list_id`      BIGINT UNSIGNED NULL     COMMENT 'Default CRM list for leads from this link',
  `expires_at`   TIMESTAMP       NULL,
  `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token`   (`token`),
  KEY `idx_user_id`       (`user_id`),
  KEY `idx_client_id`     (`client_id`),
  KEY `idx_status`        (`status`),
  KEY `idx_full_path`     (`full_path`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registry of all affiliate tracking links with click and lead counters';
