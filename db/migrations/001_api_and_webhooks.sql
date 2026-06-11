-- Iteration 2: API keys + webhooks.
-- Applied ONCE to pre-existing databases by setup.sh (tracked in
-- schema_migrations). Fresh installs get all of this from schema.sql and the
-- migration id is baselined without executing this file.

ALTER TABLE users
    ADD COLUMN api_key CHAR(40) NULL AFTER password_hash,
    ADD UNIQUE KEY uq_users_api_key (api_key);

UPDATE users
SET api_key = SUBSTRING(LOWER(SHA2(CONCAT(id, RAND(), NOW(6)), 256)), 1, 40)
WHERE api_key IS NULL;

ALTER TABLE forms
    ADD COLUMN webhook_url VARCHAR(500) NULL AFTER fields;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    url VARCHAR(500) NOT NULL,
    status ENUM ('pending', 'delivering', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    response_code SMALLINT UNSIGNED NULL,
    error TEXT NULL,
    duration_ms INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_deliveries_submission (submission_id),
    CONSTRAINT fk_webhook_deliveries_submission FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
