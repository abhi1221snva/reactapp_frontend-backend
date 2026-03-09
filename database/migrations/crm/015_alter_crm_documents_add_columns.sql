-- CRM Migration 015: Alter crm_documents — add file_path, uploaded_by, deleted_at
-- The original crm_documents table (2023) only had document_name, file_name, file_size.
-- The CrmDocumentController expects file_path (S3/public URL), uploaded_by (user_id), and soft-delete.
-- This migration is idempotent (uses IF NOT EXISTS / MODIFY COLUMN only if needed).

-- Extend file_name to hold longer names
ALTER TABLE `crm_documents`
  MODIFY COLUMN `file_name` VARCHAR(500) NULL;

-- Add file_path (full public URL to the stored file)
ALTER TABLE `crm_documents`
  ADD COLUMN IF NOT EXISTS `file_path` VARCHAR(1000) NULL AFTER `file_name`;

-- Add uploaded_by (FK-style reference to master users.id)
ALTER TABLE `crm_documents`
  ADD COLUMN IF NOT EXISTS `uploaded_by` INT UNSIGNED NULL AFTER `file_path`;

-- Add soft-delete column
ALTER TABLE `crm_documents`
  ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;

-- Index for fast lookups by lead and soft-delete filter
ALTER TABLE `crm_documents`
  ADD INDEX IF NOT EXISTS `idx_lead_deleted` (`lead_id`, `deleted_at`);
