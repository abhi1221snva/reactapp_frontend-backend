-- Migration 017: Upgrade crm_label for dynamic sections and conditional fields
-- Safe to re-run: all statements are guarded

-- ─── 1. Convert heading_type ENUM → VARCHAR(100) ─────────────────────────────
-- Allows admins to create custom section names beyond the 4 hardcoded values.
SET @ht_type = (
    SELECT COLUMN_TYPE
    FROM   information_schema.COLUMNS
    WHERE  TABLE_SCHEMA = DATABASE()
      AND  TABLE_NAME   = 'crm_label'
      AND  COLUMN_NAME  = 'heading_type'
);

SET @sql_ht = IF(
    @ht_type LIKE 'enum%',
    "ALTER TABLE crm_label MODIFY COLUMN heading_type VARCHAR(100) NOT NULL DEFAULT 'owner'",
    "SELECT 'heading_type is already VARCHAR — skipping' AS info"
);
PREPARE stmt FROM @sql_ht;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ─── 2. Add conditions JSON column ───────────────────────────────────────────
-- Stores optional visibility rules, e.g.:
--   [{"field":"option_5","operator":"equals","value":"Company"}]
-- An empty array [] or NULL means "always visible".
SET @cond_exists = (
    SELECT COUNT(*)
    FROM   information_schema.COLUMNS
    WHERE  TABLE_SCHEMA = DATABASE()
      AND  TABLE_NAME   = 'crm_label'
      AND  COLUMN_NAME  = 'conditions'
);

SET @sql_cond = IF(
    @cond_exists = 0,
    'ALTER TABLE crm_label ADD COLUMN conditions JSON NULL DEFAULT NULL AFTER placeholder',
    "SELECT 'conditions column already exists — skipping' AS info"
);
PREPARE stmt FROM @sql_cond;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
