-- CRM Migration 001: Add missing indexes to existing CRM tables
-- Uses CREATE INDEX IF NOT EXISTS (MySQL 8.0.29+) — safe for re-runs
-- Run against each client_N database

-- crm_lead_data: currently has NO indexes on high-cardinality columns
CREATE INDEX IF NOT EXISTS `idx_lead_status`         ON `crm_lead_data` (`lead_status`);
CREATE INDEX IF NOT EXISTS `idx_assigned_to`         ON `crm_lead_data` (`assigned_to`);
CREATE INDEX IF NOT EXISTS `idx_is_deleted`          ON `crm_lead_data` (`is_deleted`);
CREATE INDEX IF NOT EXISTS `idx_lead_status_deleted` ON `crm_lead_data` (`lead_status`, `is_deleted`);
CREATE INDEX IF NOT EXISTS `idx_created_at`          ON `crm_lead_data` (`created_at`);
CREATE INDEX IF NOT EXISTS `idx_updated_at`          ON `crm_lead_data` (`updated_at`);
CREATE INDEX IF NOT EXISTS `idx_lead_source_id`      ON `crm_lead_data` (`lead_source_id`);

-- crm_log: currently NO lead_id index — every lookup is a full table scan
CREATE INDEX IF NOT EXISTS `idx_lead_id`     ON `crm_log` (`lead_id`);
CREATE INDEX IF NOT EXISTS `idx_type`        ON `crm_log` (`type`);
CREATE INDEX IF NOT EXISTS `idx_campaign_id` ON `crm_log` (`campaign_id`);

-- crm_notifications
CREATE INDEX IF NOT EXISTS `idx_lead_id`   ON `crm_notifications` (`lead_id`);
CREATE INDEX IF NOT EXISTS `idx_lead_type` ON `crm_notifications` (`lead_id`, `type`);
CREATE INDEX IF NOT EXISTS `idx_user_id`   ON `crm_notifications` (`user_id`);

-- crm_scheduled_task
CREATE INDEX IF NOT EXISTS `idx_lead_id` ON `crm_scheduled_task` (`lead_id`);
CREATE INDEX IF NOT EXISTS `idx_user_id` ON `crm_scheduled_task` (`user_id`);
CREATE INDEX IF NOT EXISTS `idx_is_sent` ON `crm_scheduled_task` (`is_sent`);
CREATE INDEX IF NOT EXISTS `idx_date`    ON `crm_scheduled_task` (`date`);

-- crm_lead_status
CREATE INDEX IF NOT EXISTS `idx_title_url`     ON `crm_lead_status` (`lead_title_url`);
CREATE INDEX IF NOT EXISTS `idx_display_order` ON `crm_lead_status` (`display_order`);
CREATE INDEX IF NOT EXISTS `idx_status`        ON `crm_lead_status` (`status`);

-- crm_label
CREATE INDEX IF NOT EXISTS `idx_status`        ON `crm_label` (`status`);
CREATE INDEX IF NOT EXISTS `idx_display_order` ON `crm_label` (`display_order`);

-- crm_send_lead_to_lender_record
CREATE INDEX IF NOT EXISTS `idx_lead_id`   ON `crm_send_lead_to_lender_record` (`lead_id`);
CREATE INDEX IF NOT EXISTS `idx_lender_id` ON `crm_send_lead_to_lender_record` (`lender_id`);

-- crm_documents
CREATE INDEX IF NOT EXISTS `idx_lead_id` ON `crm_documents` (`lead_id`);
