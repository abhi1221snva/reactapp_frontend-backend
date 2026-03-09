-- CRM Migration 004: Create crm_lead_approvals — Approval/Decline Workflow
-- Tracks formal approval requests with reasons and reviewer decisions

CREATE TABLE IF NOT EXISTS `crm_lead_approvals` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`          BIGINT UNSIGNED NOT NULL,
  `requested_by`     INT UNSIGNED    NOT NULL COMMENT 'User who submitted for approval',
  `reviewed_by`      INT UNSIGNED    NULL     COMMENT 'User who approved or declined - NULL when pending',
  `approval_type`    ENUM(
                       'funding',
                       'lender_submission',
                       'document_review',
                       'status_override',
                       'custom'
                     ) NOT NULL DEFAULT 'custom',
  `approval_stage`   VARCHAR(100)    NULL     COMMENT 'Status slug this approval gates access to',
  `status`           ENUM('pending','approved','declined','withdrawn','expired') NOT NULL DEFAULT 'pending',
  `request_note`     TEXT            NULL     COMMENT 'Requester explanation of what is being requested',
  `review_note`      TEXT            NULL     COMMENT 'Reviewer decision note / decline reason',
  `requested_amount` DECIMAL(15,2)   NULL     COMMENT 'For funding approvals',
  `approved_amount`  DECIMAL(15,2)   NULL     COMMENT 'May differ from requested if partially approved',
  `expires_at`       TIMESTAMP       NULL     COMMENT 'Auto-expire pending approvals after this time',
  `reviewed_at`      TIMESTAMP       NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_lead_id`      (`lead_id`),
  KEY `idx_status`       (`status`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `idx_reviewed_by`  (`reviewed_by`),
  KEY `idx_lead_status`  (`lead_id`, `status`),
  KEY `idx_expires_at`   (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Approval and decline workflow records for leads';
