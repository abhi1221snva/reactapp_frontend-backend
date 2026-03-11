-- Migration 016: Add placeholder column to crm_label
-- Safe to re-run: uses IF NOT EXISTS guard via stored procedure pattern

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'crm_label'
      AND COLUMN_NAME  = 'placeholder'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE crm_label ADD COLUMN placeholder VARCHAR(255) NULL DEFAULT NULL AFTER `values`',
    'SELECT ''Column placeholder already exists, skipping'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
