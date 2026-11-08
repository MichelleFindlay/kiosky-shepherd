-- Kiosky Shepherd: single sign-on (Microsoft Entra ID and other OpenID Connect providers)
-- Applied by `php bin/migrate.php`, by the console's "Update database" button, or by
-- importing this file in phpMyAdmin.

-- Single sign-on users have no password. They are identified by the provider id from
-- config/config.php and the provider's `sub` claim, which is case-sensitive ASCII.
ALTER TABLE admins
    MODIFY password_hash VARCHAR(255) NULL,
    ADD COLUMN sso_provider VARCHAR(32) NULL AFTER password_hash,
    ADD COLUMN sso_subject  VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER sso_provider,
    ADD COLUMN email        VARCHAR(255) NULL AFTER sso_subject,
    ADD UNIQUE KEY uq_admins_sso (sso_provider, sso_subject);

-- Sign-ins that have gone to the identity provider and not come back yet. Each row
-- is used once and expires after 10 minutes. Only hashes of the state and of the
-- browser-binding cookie are stored.
CREATE TABLE sso_logins (
    state_hash    CHAR(64)    NOT NULL PRIMARY KEY,
    provider      VARCHAR(32) NOT NULL,
    browser_hash  CHAR(64)    NOT NULL,
    nonce         VARCHAR(64) NOT NULL,
    code_verifier VARCHAR(128) NOT NULL,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sso_logins_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('002_sso.sql');
