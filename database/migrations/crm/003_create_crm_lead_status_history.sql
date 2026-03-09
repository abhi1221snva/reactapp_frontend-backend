-- CRM Migration 003: Create crm_lead_status_history — Status Audit Trail
-- Records every pipeline stage change with who changed it and why

CREATE TABLE IF NOT EXISTS `crm_lead_status_history` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`          BIGINT UNSIGNED NOT NULL,
  `user_id`          INT UNSIGNED    NOT NULL COMMENT 'Who made the change',
  `from_status`      VARCHAR(100)    NULL     COMMENT 'slug from crm_lead_status - NULL on first assignment',
  `to_status`        VARCHAR(100)    NOT NULL COMMENT 'slug from crm_lead_status',
  `from_assigned_to` INT UNSIGNED    NULL     COMMENT 'Previous assignee user_id',
  `to_assigned_to`   INT UNSIGNED    NULL     COMMENT 'New assignee user_id',
  `from_lead_type`   VARCHAR(100)    NULL,
  `to_lead_type`     VARCHAR(100)    NULL,
  `reason`           TEXT            NULL     COMMENT 'Optional note explaining the change',
  `triggered_by`     ENUM('agent','system','webhook','bulk_operation','api') NOT NULL DEFAULT 'agent',
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_lead_id`        (`lead_id`),
  KEY `idx_user_id`        (`user_id`),
  KEY `idx_to_status`      (`to_status`),
  KEY `idx_from_to_status` (`from_status`, `to_status`),
  KEY `idx_created_at`     (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable audit trail of all lead pipeline stage changes';
