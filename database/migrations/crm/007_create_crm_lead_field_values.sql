-- CRM Migration 007: Create crm_lead_field_values — EAV Storage for Dynamic Fields
-- New architecture to replace the option_N column pattern
-- Existing option_N data is migrated in Phase 3 via a separate backfill script

CREATE TABLE IF NOT EXISTS `crm_lead_field_values` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`        BIGINT UNSIGNED NOT NULL,
  `label_id`       BIGINT UNSIGNED NOT NULL COMMENT 'FK to crm_label.id',
  `column_name`    VARCHAR(50)     NOT NULL COMMENT 'Mirrors crm_label.column_name for fast lookup',
  `value_text`     TEXT            NULL COMMENT 'For text, email, phone, select data types',
  `value_number`   DECIMAL(15,4)   NULL COMMENT 'For number data type',
  `value_date`     DATE            NULL COMMENT 'For date data type',
  `value_datetime` DATETIME        NULL COMMENT 'For datetime data type',
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lead_label`   (`lead_id`, `label_id`),
  KEY `idx_lead_id`            (`lead_id`),
  KEY `idx_label_id`           (`label_id`),
  KEY `idx_column_name`        (`column_name`),
  KEY `idx_lead_column`        (`lead_id`, `column_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='EAV storage for dynamic CRM label values — replaces option_N columns on crm_lead_data';
