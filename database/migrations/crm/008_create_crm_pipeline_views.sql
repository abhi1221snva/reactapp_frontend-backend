-- CRM Migration 008: Create crm_pipeline_views — Saved Pipeline View Configurations
-- Stores user-defined kanban/list/table views with filters and column settings

CREATE TABLE IF NOT EXISTS `crm_pipeline_views` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(255)    NOT NULL,
  `user_id`        INT UNSIGNED    NULL     COMMENT 'NULL = global shared view',
  `is_default`     TINYINT(1)      NOT NULL DEFAULT 0,
  `is_shared`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = visible to all tenant users',
  `view_type`      ENUM('kanban','list','table') NOT NULL DEFAULT 'kanban',
  `filters`        JSON            NULL COMMENT 'Serialised filter: statuses[], assigned_to[], date_range, search',
  `column_config`  JSON            NULL COMMENT 'Visible crm_label columns and display order',
  `sort_config`    JSON            NULL COMMENT '{field: "updated_at", direction: "desc"}',
  `status_columns` JSON            NULL COMMENT 'Which pipeline stages show as kanban columns (ordered)',
  `created_by`     INT UNSIGNED    NOT NULL,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_is_shared`  (`is_shared`),
  KEY `idx_is_default` (`user_id`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Saved pipeline view configurations (kanban, list, table) per user or shared';
