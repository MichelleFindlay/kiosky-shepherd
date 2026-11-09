<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Apk;
use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Devices;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Migrator;
use Shepherd\Policy;
use Shepherd\Releases;
use Shepherd\Util;

final class ReleasesApi
{
    private const SELECT = "SELECT r.*, a.username AS uploaded_by_name,
            (SELECT COUNT(*) FROM devices d WHERE d.status = 'active' AND d.app_version_code = r.version_code) AS devices_on_version
          FROM releases r LEFT JOIN admins a ON a.id = r.uploaded_by";

    /** GET /api/v1/admin/releases */
    public static function list(Request $req): Response
    {
        AdminAuth::require($req);
        $targeted = self::targetCounts();
        return Response::json(array_map(
            static fn (array $r) => Releases::present($r) + ['devices_targeted' => $targeted[$r['version_name']] ?? 0],
            Db::all(self::SELECT . ' ORDER BY r.version_code DESC')));
    }

    /**
     * How many active devices each release is the update target for, after profiles,
     * rings and device overrides.
     *
     * @return array<string, int> version name => devices
     */
    private static function targetCounts(): array
    {
        $out = [];
        foreach (Db::all(Devices::select() . " WHERE d.status = 'active'") as $d) {
            $t = Releases::targetFor(Policy::forDevice($d), $d);
            if ($t !== null) {
                $out[$t['version']] = ($out[$t['version']] ?? 0) + 1;
            }
        }
        return $out;
    }

    // ---- Release rings (staged rollouts by tag) --------------------------------

    /** GET /api/v1/admin/releases/rings  every ring, with how far its devices have got */
    public static function rings(Request $req): Response
    {
        AdminAuth::require($req);
        if (!Migrator::has('tag_releases')) {
            return Response::json([]);
        }
        $latest = Releases::latest('stable'); // "latest" in a ring follows each device's channel; stable shown
        $out = [];
        foreach (Db::all('SELECT r.*, a.username FROM tag_releases r LEFT JOIN admins a ON a.id = r.updated_by ORDER BY r.tag') as $r) {
            $version = $r['release_version'] === 'latest' ? ($latest['version_name'] ?? null) : $r['release_version'];
            $code = $version === null ? null
                : Db::value('SELECT version_code FROM releases WHERE version_name = :v', ['v' => $version]);
            $counts = Db::one("SELECT COUNT(*) AS total, SUM(d.app_version_code = :c) AS on_it, SUM(d.app_version_code > :c2) AS newer
                                 FROM devices d JOIN device_tags t ON t.device_id = d.id
                                WHERE t.tag = :t AND d.status = 'active'",
                ['t' => $r['tag'], 'c' => (int) $code, 'c2' => (int) $code]) ?? [];
            $out[] = [
                'tag' => $r['tag'],
                'release' => $r['release_version'],
                'resolves_to' => $version,
                'devices' => (int) ($counts['total'] ?? 0),
                'on_release' => (int) ($counts['on_it'] ?? 0),
                'newer' => (int) ($counts['newer'] ?? 0),
                'updated_by' => $r['username'],
                'updated_at' => Util::iso($r['updated_at']),
            ];
        }
        return Response::json($out);
    }

    /** PUT /api/v1/admin/releases/rings/{tag}  {release: "latest" | "1.2.0"} */
    public static function setRing(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        Migrator::requireTable('tag_releases');
        $tag = strtolower($req->param('tag'));
        if (!Devices::isTag($tag)) {
            throw HttpError::validation(['tag' => 'Not a valid tag.']);
        }
        $release = Policy::validate(['updates.release' => $req->input('release')])['updates.release'] ?? null;
        if (!is_string($release)) {
            throw HttpError::validation(['release' => 'Use "latest" or an uploaded version.']);
        }
        Db::run('INSERT INTO tag_releases (tag, release_version, updated_by) VALUES (:t, :r, :by)
                 ON DUPLICATE KEY UPDATE release_version = :r2, updated_by = :by2',
            ['t' => $tag, 'r' => $release, 'by' => $p->adminId, 'r2' => $release, 'by2' => $p->adminId]);
        Releases::forgetRings();
        Audit::log($p, 'release.ring_set', 'tag', $tag, ['release' => $release]);
        return Response::json(['tag' => $tag, 'release' => $release]);
    }

    /** DELETE /api/v1/admin/releases/rings/{tag} */
    public static function deleteRing(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        Migrator::requireTable('tag_releases');
        $tag = strtolower($req->param('tag'));
        if (Db::run('DELETE FROM tag_releases WHERE tag = :t', ['t' => $tag])->rowCount() === 0) {
            throw HttpError::notFound('Ring');
        }
        Audit::log($p, 'release.ring_deleted', 'tag', $tag);
        return Response::noContent();
    }

    /**
     * POST /api/v1/admin/releases  (multipart/form-data)
     * apk: the file; version_name, version_code: needed only when the server
     * can't read the APK itself (no zip extension); notes: optional.
     */
    public static function upload(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $file = $_FILES['apk'] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            throw HttpError::validation(['apk' => 'Attach the APK file as "apk".']);
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError(413, 'too_large', 'The APK is larger than PHP\'s upload_max_filesize / post_max_size.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw HttpError::validation(['apk' => 'The upload failed (error ' . (int) $file['error'] . ').']);
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size > (int) Config::get('max_apk_mb', 150) * 1024 * 1024) {
            throw new HttpError(413, 'too_large', 'The APK is larger than max_apk_mb.');
        }
        $fh = fopen($file['tmp_name'], 'rb');
        $magic = $fh ? fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== "PK\x03\x04") {
            throw HttpError::validation(['apk' => 'That is not an APK file.']);
        }

        $formName = trim((string) ($_POST['version_name'] ?? ''));
        $formCode = trim((string) ($_POST['version_code'] ?? ''));
        try {
            $manifest = Apk::manifest($file['tmp_name']);
        } catch (\RuntimeException $e) {
            throw HttpError::validation(['apk' => $e->getMessage()]);
        }

        if ($manifest !== null) {
            $expected = (string) Config::get('apk_package', 'uk.lp0.ha');
            if ($expected !== '' && $manifest['package'] !== $expected) {
                throw HttpError::validation(['apk' => "This APK is {$manifest['package']}, not Kiosky ($expected)."]);
            }
            if ($manifest['version_code'] === null || $manifest['version_name'] === null) {
                throw HttpError::validation(['apk' => 'Could not read the version from the APK.']);
            }
            if (($formName !== '' && $formName !== $manifest['version_name'])
                || ($formCode !== '' && (int) $formCode !== $manifest['version_code'])) {
                throw HttpError::validation(['version_name' => sprintf(
                    'The APK says it is %s (%d). Leave the version fields empty to use that.',
                    $manifest['version_name'], $manifest['version_code'])]);
            }
            $versionName = $manifest['version_name'];
            $versionCode = $manifest['version_code'];
        } else {
            if ($formName === '' || !ctype_digit($formCode)) {
                throw HttpError::validation(['version_code' => 'This server cannot read APKs (PHP zip extension missing). Enter the version name and code.']);
            }
            $versionName = $formName;
            $versionCode = (int) $formCode;
        }
        if (!preg_match('/^\d+\.\d+\.\d+([\-+.][0-9A-Za-z.\-]+)?$/', $versionName) || strlen($versionName) > 32) {
            throw HttpError::validation(['version_name' => 'Version names look like 1.2.3.']);
        }
        if ($versionCode < 1 || $versionCode > 2100000000) {
            throw HttpError::validation(['version_code' => 'Not a valid versionCode.']);
        }
        if (Db::value('SELECT id FROM releases WHERE version_code = :c OR version_name = :n',
            ['c' => $versionCode, 'n' => $versionName]) !== null) {
            throw HttpError::conflict("Release $versionName ($versionCode) has already been uploaded.");
        }

        $sha = hash_file('sha256', $file['tmp_name']);
        $dir = Releases::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $fileName = $versionCode . '-' . substr($sha, 0, 12) . '.apk';
        if (!move_uploaded_file($file['tmp_name'], "$dir/$fileName")) {
            throw new \RuntimeException("Cannot store the APK in $dir");
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $channel = (string) ($_POST['channel'] ?? 'stable');
        if (!in_array($channel, Releases::CHANNELS, true) || ($channel !== 'stable' && !Releases::hasChannels())) {
            @unlink("$dir/$fileName");
            throw HttpError::validation(['channel' => Releases::hasChannels()
                ? 'Use stable or dev.' : 'Release channels need the latest database update.']);
        }
        $rolloutHours = trim((string) ($_POST['rollout_hours'] ?? ''));
        if ($rolloutHours !== '' && (!ctype_digit($rolloutHours) || (int) $rolloutHours > 720 || !Releases::hasRollouts())) {
            @unlink("$dir/$fileName");
            throw HttpError::validation(['rollout_hours' => Releases::hasRollouts()
                ? 'Use 0 (everyone at once) to 720 hours.' : 'Staged rollouts need the latest database update.']);
        }
        $rollout = Releases::hasRollouts() ? ['rollout_hours' => (int) $rolloutHours, 'rollout_start' => Util::now()] : [];
        $id = Db::insert('releases', $rollout + ($channel !== 'stable' ? ['channel' => $channel] : []) + [
            'version_name' => $versionName,
            'version_code' => $versionCode,
            'sha256' => $sha,
            'size' => $size,
            'file_name' => $fileName,
            'notes' => $notes === '' ? null : mb_substr($notes, 0, 5000),
            'uploaded_by' => $p->adminId,
        ]);
        Audit::log($p, 'release.uploaded', 'release', $id, ['version' => $versionName, 'sha256' => $sha, 'channel' => $channel]);
        return Response::json(Releases::present(Db::one(self::SELECT . ' WHERE r.id = :id', ['id' => $id])), 201);
    }

    /**
     * PATCH /api/v1/admin/releases/{id}  {notes?, active?, channel?, rollout_hours?, halted?, restart_rollout?}
     * Inactive and paused (halted) releases are not offered to devices.
     */
    public static function update(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $id = $req->intParam('id');
        Db::one('SELECT id FROM releases WHERE id = :id', ['id' => $id]) ?? throw HttpError::notFound('Release');
        $b = $req->json();
        $changes = [];
        if (array_key_exists('notes', $b)) {
            $changes['notes'] = is_string($b['notes']) ? mb_substr(trim($b['notes']), 0, 5000) : null;
        }
        if (array_key_exists('active', $b)) {
            if (!is_bool($b['active'])) {
                throw HttpError::validation(['active' => 'Must be true or false.']);
            }
            $changes['active'] = $b['active'];
        }
        if (array_key_exists('channel', $b)) {
            if (!in_array($b['channel'], Releases::CHANNELS, true)) {
                throw HttpError::validation(['channel' => 'Use stable or dev.']);
            }
            if (!Releases::hasChannels()) {
                throw HttpError::conflict('Release channels need the latest database update.');
            }
            $changes['channel'] = $b['channel'];
        }
        if (array_key_exists('rollout_hours', $b) || array_key_exists('halted', $b) || !empty($b['restart_rollout'])) {
            if (!Releases::hasRollouts()) {
                throw HttpError::conflict('Staged rollouts need the latest database update.');
            }
        }
        if (array_key_exists('rollout_hours', $b)) {
            if (!is_int($b['rollout_hours']) || $b['rollout_hours'] < 0 || $b['rollout_hours'] > 720) {
                throw HttpError::validation(['rollout_hours' => 'Use 0 (everyone at once) to 720 hours.']);
            }
            $changes['rollout_hours'] = $b['rollout_hours'];
        }
        if (array_key_exists('halted', $b)) {
            if (!is_bool($b['halted'])) {
                throw HttpError::validation(['halted' => 'Must be true or false.']);
            }
            // Pausing by hand; resuming clears the reason too
            $changes['halted_at'] = $b['halted'] ? Util::now() : null;
            $changes['halt_reason'] = $b['halted'] ? 'Paused by ' . $p->name : null;
        }
        if (!empty($b['restart_rollout'])) {
            $changes['rollout_start'] = Util::now(); // back to the first devices in the queue
        }
        if ($changes !== []) {
            Db::update('releases', $changes, ['id' => $id]);
            Audit::log($p, 'release.updated', 'release', $id, $changes);
        }
        return Response::json(Releases::present(Db::one(self::SELECT . ' WHERE r.id = :id', ['id' => $id])));
    }

    /** DELETE /api/v1/admin/releases/{id} */
    public static function delete(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $id = $req->intParam('id');
        $rel = Db::one('SELECT * FROM releases WHERE id = :id', ['id' => $id]) ?? throw HttpError::notFound('Release');

        // Refuse while a profile or device pins this exact version
        $pinned = [];
        foreach (Db::all('SELECT name, settings FROM profiles') as $pr) {
            if ((Db::decode($pr['settings'])['updates.release'] ?? null) === $rel['version_name']) {
                $pinned[] = 'profile ' . $pr['name'];
            }
        }
        foreach (Db::all("SELECT name, overrides FROM devices WHERE status = 'active'") as $d) {
            if ((Db::decode($d['overrides'])['updates.release'] ?? null) === $rel['version_name']) {
                $pinned[] = 'device ' . $d['name'];
            }
        }
        if (Migrator::has('tag_releases')) {
            foreach (Db::all('SELECT tag FROM tag_releases WHERE release_version = :v', ['v' => $rel['version_name']]) as $ring) {
                $pinned[] = 'the ' . $ring['tag'] . ' ring';
            }
        }
        if ($pinned !== []) {
            throw HttpError::conflict('Still selected by ' . implode(', ', array_slice($pinned, 0, 5))
                . (count($pinned) > 5 ? ' and others' : '') . '.');
        }
        Db::run('DELETE FROM releases WHERE id = :id', ['id' => $id]);
        @unlink(Releases::dir() . '/' . basename((string) $rel['file_name']));
        Audit::log($p, 'release.deleted', 'release', $id, ['version' => $rel['version_name']]);
        return Response::noContent();
    }
}
