<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Apk;
use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Releases;

final class ReleasesApi
{
    private const SELECT = "SELECT r.*, a.username AS uploaded_by_name,
            (SELECT COUNT(*) FROM devices d WHERE d.status = 'active' AND d.app_version_code = r.version_code) AS devices_on_version
          FROM releases r LEFT JOIN admins a ON a.id = r.uploaded_by";

    /** GET /api/v1/admin/releases */
    public static function list(Request $req): Response
    {
        AdminAuth::require($req);
        return Response::json(array_map([Releases::class, 'present'],
            Db::all(self::SELECT . ' ORDER BY r.version_code DESC')));
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
        $id = Db::insert('releases', [
            'version_name' => $versionName,
            'version_code' => $versionCode,
            'sha256' => $sha,
            'size' => $size,
            'file_name' => $fileName,
            'notes' => $notes === '' ? null : mb_substr($notes, 0, 5000),
            'uploaded_by' => $p->adminId,
        ]);
        Audit::log($p, 'release.uploaded', 'release', $id, ['version' => $versionName, 'sha256' => $sha]);
        return Response::json(Releases::present(Db::one(self::SELECT . ' WHERE r.id = :id', ['id' => $id])), 201);
    }

    /** PATCH /api/v1/admin/releases/{id}  {notes?, active?} — inactive releases are not offered to devices */
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
