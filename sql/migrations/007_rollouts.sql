-- Kiosky Shepherd 1.8.0: automatic staged rollouts and update health. A release can
-- reach devices gradually over rollout_hours (each device has a fixed place in the
-- queue), and is paused by itself when Kiosky 1.8.0+ reports it crashing after an
-- update (update_unhealthy). Applied automatically on the first request after upgrading.

ALTER TABLE releases
    ADD COLUMN rollout_hours   INT UNSIGNED NOT NULL DEFAULT 0 AFTER channel,
    ADD COLUMN rollout_start   DATETIME     NULL AFTER rollout_hours,
    ADD COLUMN halted_at       DATETIME     NULL AFTER rollout_start,
    ADD COLUMN halt_reason     VARCHAR(255) NULL AFTER halted_at,
    ADD COLUMN healthy_count   INT UNSIGNED NOT NULL DEFAULT 0 AFTER halt_reason,
    ADD COLUMN unhealthy_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER healthy_count;

UPDATE releases SET rollout_start = created_at;

INSERT IGNORE INTO schema_migrations (name) VALUES ('007_rollouts.sql');
