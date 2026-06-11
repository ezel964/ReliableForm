-- Stage 2: MySQL-backed sessions (production parity), form settings,
-- autoresponder emails, conditional logic storage.

CREATE TABLE IF NOT EXISTS sessions (
    sid CHAR(64) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    PRIMARY KEY (sid),
    KEY idx_sessions_expires (expires_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE forms
    ADD COLUMN conditions JSON NULL AFTER fields,
    ADD COLUMN submission_limit SMALLINT UNSIGNED NULL AFTER webhook_url,
    ADD COLUMN thankyou_message VARCHAR(500) NULL AFTER submission_limit,
    ADD COLUMN autoresponder_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER thankyou_message;

ALTER TABLE emails
    ADD COLUMN kind ENUM ('notification', 'autoresponder') NOT NULL DEFAULT 'notification' AFTER submission_id,
    DROP KEY uq_emails_submission,
    ADD UNIQUE KEY uq_emails_submission_kind (submission_id, kind);
