-- Kiosky Shepherd 1.5.0: release channels. Each uploaded APK is a stable or a dev
-- build; devices on the dev channel (policy updates.channel) also get dev builds.
-- Applied automatically on the first request after upgrading.

ALTER TABLE releases
    ADD COLUMN channel ENUM('stable','dev') NOT NULL DEFAULT 'stable' AFTER notes,
    ADD KEY idx_releases_channel (channel, active, version_code);

INSERT IGNORE INTO schema_migrations (name) VALUES ('005_release_channels.sql');
