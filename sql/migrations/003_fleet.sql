-- Kiosky Shepherd 1.3.0: two-factor sign-in, device tags, scheduled commands,
-- offline alerts and faster check-ins while someone is watching a device.
-- Applied by `php bin/migrate.php`, by the console's "Update database" button, or by
-- importing this file in phpMyAdmin.

-- Two-factor sign-in (TOTP) for password users. totp_last_step stops a code from
-- being used twice. totp_recovery holds SHA-256 hashes of unused recovery codes.
ALTER TABLE admins
    ADD COLUMN totp_secret    VARCHAR(64)     NULL AFTER email,
    ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_secret,
    ADD COLUMN totp_recovery  JSON            NULL AFTER totp_last_step;

-- alerted_offline_at: when bin/cron.php last reported this device offline (cleared
-- when it reports it back online). fast_checkin_until: while an admin has the device
-- page open, check-in responses ask the device to check in more often.
ALTER TABLE devices
    ADD COLUMN alerted_offline_at DATETIME NULL AFTER last_ip,
    ADD COLUMN fast_checkin_until DATETIME NULL AFTER alerted_offline_at;

-- Free-form labels for grouping devices across profiles (e.g. "floor-2", "no-alerts")
CREATE TABLE device_tags (
    device_id INT UNSIGNED NOT NULL,
    tag       VARCHAR(32)  NOT NULL,
    PRIMARY KEY (device_id, tag),
    KEY idx_device_tags_tag (tag),
    CONSTRAINT fk_device_tags_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commands queued by bin/cron.php on chosen weekdays at a local time.
-- days is a bitmask: 1 = Monday, 2 = Tuesday, 4 = Wednesday … 64 = Sunday.
-- target is {"all": true} | {"profile_id": n} | {"tag": "x"} | {"device_ids": [..]}.
CREATE TABLE scheduled_commands (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)      NOT NULL,
    type        VARCHAR(32)       NOT NULL,
    params      JSON              NOT NULL,
    target      JSON              NOT NULL,
    days        TINYINT UNSIGNED  NOT NULL,
    at_time     CHAR(5)           NOT NULL,
    timezone    VARCHAR(64)       NOT NULL,
    ttl_hours   SMALLINT UNSIGNED NULL,
    enabled     TINYINT(1)        NOT NULL DEFAULT 1,
    next_run_at DATETIME          NULL,
    last_run_at DATETIME          NULL,
    last_result VARCHAR(255)      NULL,
    created_by  INT UNSIGNED      NULL,
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sched_next (enabled, next_run_at),
    CONSTRAINT fk_sched_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE commands
    ADD COLUMN schedule_id INT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_commands_schedule FOREIGN KEY (schedule_id) REFERENCES scheduled_commands (id) ON DELETE SET NULL;

INSERT IGNORE INTO schema_migrations (name) VALUES ('003_fleet.sql');
