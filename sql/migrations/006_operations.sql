-- Kiosky Shepherd: alerts beyond offline, silences, enrollment presets, console
-- sessions, passkeys, browser push and outgoing event webhooks.
-- Applied automatically on the first request after upgrading.

-- Problems other than "offline" that have been alerted (page error, low battery…),
-- so each is reported once when it starts and once when it clears.
CREATE TABLE alert_state (
    device_id  INT UNSIGNED NOT NULL,
    reason     VARCHAR(32)  NOT NULL,
    since      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    alerted_at DATETIME     NULL,
    PRIMARY KEY (device_id, reason),
    CONSTRAINT fk_alert_state_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Don't alert about this device / tag / anything until …" (maintenance)
CREATE TABLE alert_silences (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_type ENUM('device','tag','all') NOT NULL,
    target      VARCHAR(64)  NOT NULL DEFAULT '',
    until_at    DATETIME     NOT NULL,
    note        VARCHAR(255) NOT NULL DEFAULT '',
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_silences_until (until_at),
    CONSTRAINT fk_silences_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags and notes given to devices that enroll with a code
ALTER TABLE enrollment_tokens
    ADD COLUMN tags  JSON NULL AFTER name_prefix,
    ADD COLUMN notes TEXT NULL AFTER tags;

-- Console sign-ins, so users can see and end them. browser_hash identifies a
-- browser across sign-ins (a long-lived cookie) for "new sign-in" emails.
CREATE TABLE admin_sessions (
    id           CHAR(64)     NOT NULL PRIMARY KEY,   -- sha256 of the token kept in the PHP session
    admin_id     INT UNSIGNED NOT NULL,
    browser_hash CHAR(64)     NULL,
    ip           VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    method       VARCHAR(32)  NOT NULL DEFAULT 'password',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at     DATETIME     NULL,
    KEY idx_admin_sessions_admin (admin_id, ended_at),
    KEY idx_admin_sessions_browser (admin_id, browser_hash),
    CONSTRAINT fk_admin_sessions_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE admins
    ADD COLUMN login_alerts TINYINT(1) NOT NULL DEFAULT 0 AFTER email;

-- Passkeys (WebAuthn). credential_id is base64url; public_key is PEM.
CREATE TABLE webauthn_credentials (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id      INT UNSIGNED NOT NULL,
    credential_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_key    TEXT         NOT NULL,
    sign_count    INT UNSIGNED NOT NULL DEFAULT 0,
    name          VARCHAR(100) NOT NULL DEFAULT '',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL,
    UNIQUE KEY uq_webauthn_credential (credential_id),
    KEY idx_webauthn_admin (admin_id),
    CONSTRAINT fk_webauthn_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Browser push subscriptions for alerts
CREATE TABLE push_subscriptions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id      INT UNSIGNED NOT NULL,
    endpoint      VARCHAR(1000) NOT NULL,
    endpoint_hash CHAR(64)     NOT NULL,
    p256dh        VARCHAR(200) NOT NULL,
    auth          VARCHAR(64)  NOT NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_ok_at    DATETIME     NULL,
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    CONSTRAINT fk_push_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outgoing event webhooks: queued here, sent straight after the request that
-- caused them (or by cron), retried with backoff.
CREATE TABLE event_outbox (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event           VARCHAR(48)   NOT NULL,
    payload         JSON          NOT NULL,
    url             VARCHAR(1000) NOT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at    DATETIME      NULL,
    last_error      VARCHAR(255)  NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_outbox_due (delivered_at, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log search by action and person
ALTER TABLE audit_log
    ADD KEY idx_audit_action (action, id),
    ADD KEY idx_audit_actor (actor_name, id);

INSERT IGNORE INTO schema_migrations (name) VALUES ('006_operations.sql');
