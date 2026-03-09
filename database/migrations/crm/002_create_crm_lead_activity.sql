-- CRM Migration 002: Create crm_lead_activity — Unified Lead Timeline
-- Replaces primitive crm_log with a typed, queryable activity feed

CREATE TABLE IF NOT EXISTS `crm_lead_activity` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NULL COMMENT 'NULL = system-generated event',
  `activity_type` ENUM(
                    'status_change',
                    'field_update',
                    'note_added',
                    'document_uploaded',
                    'task_created',
                    'task_completed',
                    'lender_submitted',
                    'lender_response',
                    'email_sent',
                    'sms_sent',
                    'call_made',
                    'approval_requested',
                    'approval_granted',
                    'approval_declined',
                    'affiliate_created',
                    'merchant_accessed',
                    'lead_created',
                    'lead_imported',
                    'lead_assigned',
                    'webhook_triggered',
                    'system'
                  ) NOT NULL DEFAULT 'system',
  `subject`       VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Short summary shown in timeline',
  `body`          TEXT NULL COMMENT 'Rich detail or JSON payload',
  `meta`          JSON NULL COMMENT 'Structured: old_value, new_value, field_name, lender_id, etc.',
  `source_type`   ENUM('crm_log','crm_notifications','manual','api') NOT NULL DEFAULT 'manual',
  `source_id`     BIGINT UNSIGNED NULL COMMENT 'FK to crm_log.id or crm_notifications.id for backfilled rows',
  `is_pinned`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_lead_id`            (`lead_id`),
  KEY `idx_lead_activity_type` (`lead_id`, `activity_type`),
  KEY `idx_user_id`            (`user_id`),
  KEY `idx_created_at`         (`created_at`),
  KEY `idx_is_pinned`          (`lead_id`, `is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Unified timeline of all activities for a CRM lead';
