-- Kiosky Shepherd: initial schema (MySQL 8.0+ / MariaDB 10.5+)
-- Run with `php bin/migrate.php`, or import this file in phpMyAdmin on hosts without SSH.
-- All timestamps are stored in UTC (the app sets the session time zone to +00:00).

CREATE TABLE admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('viewer','operator','admin') NOT NULL DEFAULT 'operator',
    disabled      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME     NULL,
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id     INT UNSIGNED NOT NULL,
    name         VARCHAR(100) NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    prefix       VARCHAR(16)  NOT NULL,
    role         ENUM('viewer','operator','admin') NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME     NULL,
    expires_at   DATETIME     NULL,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    CONSTRAINT fk_api_tokens_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A profile is a named policy that groups devices. Settings is a flat map of
-- policy keys (see src/Policy.php); keys that are absent use the built-in default.
CREATE TABLE profiles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    settings    JSON         NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profiles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO profiles (id, name, description, settings)
VALUES (1, 'Default', 'Used for devices that are not assigned to another profile.', '{}');

CREATE TABLE enrollment_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(32)  NOT NULL,
    label       VARCHAR(100) NOT NULL DEFAULT '',
    profile_id  INT UNSIGNED NOT NULL,
    name_prefix VARCHAR(50)  NOT NULL DEFAULT '',
    max_uses    INT UNSIGNED NULL,
    uses        INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME     NULL,
    revoked     TINYINT(1)   NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrollment_code (code),
    CONSTRAINT fk_enroll_profile FOREIGN KEY (profile_id) REFERENCES profiles (id),
    CONSTRAINT fk_enroll_admin   FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE devices (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid                    CHAR(16)     NOT NULL,          -- public identifier used by the device
    secret_hash            CHAR(64)     NOT NULL,          -- sha256 of the device secret
    name                   VARCHAR(100) NOT NULL,
    profile_id             INT UNSIGNED NOT NULL DEFAULT 1,
    status                 ENUM('active','retired') NOT NULL DEFAULT 'active',
    overrides              JSON         NOT NULL,          -- per-device policy keys, beat the profile
    notes                  TEXT         NULL,
    android_id             VARCHAR(64)  NULL,
    manufacturer           VARCHAR(100) NULL,
    model                  VARCHAR(100) NULL,
    android_version        VARCHAR(32)  NULL,
    sdk_int                SMALLINT UNSIGNED NULL,
    app_version            VARCHAR(32)  NULL,
    app_version_code       INT UNSIGNED NULL,
    state                  JSON         NULL,              -- last status report from the device
    applied_policy_version CHAR(16)     NULL,
    checkin_seconds        SMALLINT UNSIGNED NOT NULL DEFAULT 60,  -- interval last sent, for online status
    enrolled_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enrolled_with          INT UNSIGNED NULL,
    last_seen_at           DATETIME     NULL,
    last_ip                VARCHAR(45)  NULL,
    retired_at             DATETIME     NULL,
    UNIQUE KEY uq_devices_uid (uid),
    KEY idx_devices_profile (profile_id),
    KEY idx_devices_android_id (android_id),
    CONSTRAINT fk_devices_profile FOREIGN KEY (profile_id) REFERENCES profiles (id),
    CONSTRAINT fk_devices_token   FOREIGN KEY (enrolled_with) REFERENCES enrollment_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commands (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id    INT UNSIGNED NOT NULL,
    type         VARCHAR(32)  NOT NULL,
    params       JSON         NOT NULL,
    status       ENUM('pending','delivered','done','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
    result       JSON         NULL,
    attachment   VARCHAR(255) NULL,       -- e.g. screenshot file name under storage/screenshots
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME     NULL,
    completed_at DATETIME     NULL,
    expires_at   DATETIME     NOT NULL,
    KEY idx_commands_device_status (device_id, status),
    CONSTRAINT fk_commands_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
    CONSTRAINT fk_commands_admin  FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE device_events (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id  INT UNSIGNED NOT NULL,
    type       VARCHAR(48)  NOT NULL,
    data       JSON         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_events_device (device_id, id),
    KEY idx_events_created (created_at),
    CONSTRAINT fk_events_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kiosky APKs hosted by this server for managed updates
CREATE TABLE releases (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_name VARCHAR(32)  NOT NULL,
    version_code INT UNSIGNED NOT NULL,
    sha256       CHAR(64)     NOT NULL,
    size         BIGINT UNSIGNED NOT NULL,
    file_name    VARCHAR(100) NOT NULL,
    notes        TEXT         NULL,
    active       TINYINT(1)   NOT NULL DEFAULT 1,
    uploaded_by  INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_releases_code (version_code),
    UNIQUE KEY uq_releases_name (version_name),
    CONSTRAINT fk_releases_admin FOREIGN KEY (uploaded_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type  ENUM('admin','token','device','system') NOT NULL,
    actor_id    INT UNSIGNED NULL,
    actor_name  VARCHAR(100) NOT NULL DEFAULT '',
    action      VARCHAR(64)  NOT NULL,
    target_type VARCHAR(32)  NULL,
    target_id   VARCHAR(64)  NULL,
    details     JSON         NULL,
    ip          VARCHAR(45)  NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    KEY idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket       VARCHAR(128) NOT NULL PRIMARY KEY,
    window_start DATETIME     NOT NULL,
    hits         INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Record this migration so bin/migrate.php skips it when the file was imported by hand
CREATE TABLE IF NOT EXISTS schema_migrations (
    name       VARCHAR(191) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (name) VALUES ('001_initial.sql');
