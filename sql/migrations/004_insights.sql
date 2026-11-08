-- Kiosky Shepherd 1.4.0: profile history, release rings per tag, daily device
-- health, and console users limited to devices with certain tags.
-- Applied automatically on the first request after upgrading (or by the console's
-- "Update database" button, `php bin/migrate.php`, or importing this file in phpMyAdmin).

-- Users limited to devices carrying at least one of these tags. NULL means every device.
ALTER TABLE admins
    ADD COLUMN scope_tags JSON NULL AFTER role;

-- Every saved state of every profile, newest last. action is created, saved,
-- imported, restored or baseline (the state when this migration ran).
CREATE TABLE profile_versions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_id      INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     VARCHAR(500) NOT NULL DEFAULT '',
    settings        JSON         NOT NULL,
    action          VARCHAR(16)  NOT NULL DEFAULT 'saved',
    note            VARCHAR(255) NULL,
    changed_by      INT UNSIGNED NULL,
    changed_by_name VARCHAR(100) NOT NULL DEFAULT '',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_profile_versions (profile_id, id),
    CONSTRAINT fk_pv_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_pv_admin   FOREIGN KEY (changed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO profile_versions (profile_id, name, description, settings, action, changed_by_name, created_at)
SELECT id, name, description, settings, 'baseline', '', updated_at FROM profiles;

-- Staged rollouts: devices with this tag get this release ('latest' or a version
-- name) unless the device itself overrides updates.release.
CREATE TABLE tag_releases (
    tag             VARCHAR(32)  NOT NULL PRIMARY KEY,
    release_version VARCHAR(32)  NOT NULL,
    updated_by      INT UNSIGNED NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tag_releases_admin FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per device per day (in the server's 'timezone'), updated at each check-in
CREATE TABLE device_daily (
    device_id      INT UNSIGNED      NOT NULL,
    stat_date      DATE              NOT NULL,
    checkins       INT UNSIGNED      NOT NULL DEFAULT 0,
    online_seconds INT UNSIGNED      NOT NULL DEFAULT 0,
    battery_min    TINYINT UNSIGNED  NULL,
    battery_max    TINYINT UNSIGNED  NULL,
    wifi_min       SMALLINT          NULL,
    wifi_sum       INT               NOT NULL DEFAULT 0,
    wifi_count     INT UNSIGNED      NOT NULL DEFAULT 0,
    page_errors    INT UNSIGNED      NOT NULL DEFAULT 0,
    PRIMARY KEY (device_id, stat_date),
    KEY idx_device_daily_date (stat_date),
    CONSTRAINT fk_device_daily_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (name) VALUES ('004_insights.sql');
