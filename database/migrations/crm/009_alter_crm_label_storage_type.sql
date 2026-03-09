-- CRM Migration 009: Add storage_type column to crm_label
-- Controls whether a label's value is stored in crm_lead_data (column) or crm_lead_field_values (eav)
-- After Phase 3 EAV backfill, all rows will be updated to 'eav'

ALTER TABLE `crm_label`
  ADD COLUMN IF NOT EXISTS `storage_type`
    ENUM('column','eav') NOT NULL DEFAULT 'column'
    AFTER `heading_type`
    COMMENT 'column=option_N on crm_lead_data eav=crm_lead_field_values table';

CREATE INDEX IF NOT EXISTS `idx_status`        ON `crm_label` (`status`);
CREATE INDEX IF NOT EXISTS `idx_column_name`   ON `crm_label` (`column_name`);
CREATE INDEX IF NOT EXISTS `idx_display_order` ON `crm_label` (`display_order`);
