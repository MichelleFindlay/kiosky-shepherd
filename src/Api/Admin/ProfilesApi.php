<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Migrator;
use Shepherd\Policy;
use Shepherd\ProfileHistory;
use Shepherd\Util;

final class ProfilesApi
{
    private const SELECT = "SELECT p.*,
            (SELECT COUNT(*) FROM devices d WHERE d.profile_id = p.id AND d.status = 'active') AS device_count
          FROM profiles p";

    private static function present(array $r): array
    {
        $settings = Db::decode($r['settings']);
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'settings' => (object) Policy::redact($settings),
            'policy_version' => Policy::toDevice(Policy::resolve($settings, [])['values'])['version'],
            'device_count' => (int) $r['device_count'],
            'is_default' => (int) $r['id'] === 1,
            'created_at' => Util::iso($r['created_at']),
            'updated_at' => Util::iso($r['updated_at']),
        ];
    }

    private static function find(int $id): array
    {
        return Db::one(self::SELECT . ' WHERE p.id = :id', ['id' => $id]) ?? throw HttpError::notFound('Profile');
    }

    /** GET /api/v1/admin/policy/schema */
    public static function schema(Request $req): Response
    {
        AdminAuth::require($req);
        return Response::json(Policy::schemaForUi());
    }

    /** GET /api/v1/admin/profiles */
    public static function list(Request $req): Response
    {
        AdminAuth::require($req);
        return Response::json(array_map(static fn ($r) => self::present($r), Db::all(self::SELECT . ' ORDER BY p.id = 1 DESC, p.name')));
    }

    /** GET /api/v1/admin/profiles/{id} */
    public static function get(Request $req): Response
    {
        AdminAuth::require($req);
        return Response::json(self::present(self::find($req->intParam('id'))));
    }

    /** POST /api/v1/admin/profiles  {name, description?, settings?} */
    public static function create(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        [$name, $description] = self::validateMeta($req->json(), true);
        $settings = $req->input('settings', []);
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw HttpError::validation(['settings' => 'Must be an object of setting keys.']);
        }
        $stored = Policy::apply([], Policy::validate($settings));
        try {
            $id = Db::insert('profiles', [
                'name' => $name,
                'description' => $description ?? '',
                'settings' => Db::json((object) $stored),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw HttpError::validation(['name' => 'A profile with this name already exists.']);
            }
            throw $e;
        }
        ProfileHistory::record($id, 'created', $p);
        Audit::log($p, 'profile.created', 'profile', $id, ['name' => $name, 'settings' => array_keys($stored)]);
        return Response::json(self::present(self::find($id)), 201);
    }

    /** PATCH /api/v1/admin/profiles/{id}  {name?, description?, settings?: {key: value|null}} */
    public static function update(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $id = $req->intParam('id');
        $row = self::find($id);
        $body = $req->json();
        [$name, $description] = self::validateMeta($body, false);

        $changes = [];
        if ($name !== null) {
            $changes['name'] = $name;
        }
        if ($description !== null) {
            $changes['description'] = $description;
        }
        $changedKeys = [];
        if (array_key_exists('settings', $body)) {
            if (!is_array($body['settings']) || ($body['settings'] !== [] && array_is_list($body['settings']))) {
                throw HttpError::validation(['settings' => 'Must be an object of setting keys.']);
            }
            $valid = Policy::validate($body['settings']);
            $changedKeys = array_keys($valid);
            $changes['settings'] = Db::json((object) Policy::apply(Db::decode($row['settings']), $valid));
        }
        if ($changes !== []) {
            try {
                Db::update('profiles', $changes, ['id' => $id]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw HttpError::validation(['name' => 'A profile with this name already exists.']);
                }
                throw $e;
            }
            Policy::forgetProfile($id);
            if (self::snapshotChanged($row, $id)) {
                ProfileHistory::record($id, 'saved', $p);
                \Shepherd\Events::emit('profile.updated', ['profile' => ['id' => $id, 'name' => $name ?? $row['name']],
                    'settings_changed' => $changedKeys, 'by' => $p->name]);
            }
            Audit::log($p, 'profile.updated', 'profile', $id,
                array_filter(['name' => $name, 'settings_changed' => $changedKeys ?: null]));
        }
        return Response::json(self::present(self::find($id)));
    }

    /** DELETE /api/v1/admin/profiles/{id}?move_to=ID  devices and codes move to move_to (default: profile 1) */
    public static function delete(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $id = $req->intParam('id');
        $row = self::find($id);
        if ($id === 1) {
            throw HttpError::conflict('The default profile cannot be deleted.');
        }
        $moveTo = (int) ($req->query['move_to'] ?? 1);
        if ($moveTo === $id || Db::value('SELECT id FROM profiles WHERE id = :id', ['id' => $moveTo]) === null) {
            throw HttpError::validation(['move_to' => 'Choose another existing profile.']);
        }
        Db::transaction(static function () use ($id, $moveTo): void {
            Db::run('UPDATE devices SET profile_id = :to WHERE profile_id = :id', ['to' => $moveTo, 'id' => $id]);
            Db::run('UPDATE enrollment_tokens SET profile_id = :to WHERE profile_id = :id', ['to' => $moveTo, 'id' => $id]);
            Db::run('DELETE FROM profiles WHERE id = :id', ['id' => $id]);
        });
        Audit::log($p, 'profile.deleted', 'profile', $id, ['name' => $row['name'], 'moved_to' => $moveTo]);
        return Response::noContent();
    }

    /** True when a save actually changed the name, description or settings (so history has no empty entries). */
    private static function snapshotChanged(array $before, int $id): bool
    {
        $after = Db::one('SELECT name, description, settings FROM profiles WHERE id = :id', ['id' => $id]);
        return $after === null || $after['name'] !== $before['name'] || $after['description'] !== $before['description']
            || Db::json(Db::decode($after['settings'])) !== Db::json(Db::decode($before['settings']));
    }

    // ---- History -----------------------------------------------------------------

    /** GET /api/v1/admin/profiles/{id}/versions  saved versions, newest first, with what changed in each */
    public static function versions(Request $req): Response
    {
        AdminAuth::require($req);
        $id = $req->intParam('id');
        self::find($id);
        return Response::json(['items' => ProfileHistory::list($id)]);
    }

    /** POST /api/v1/admin/profiles/{id}/versions/{version}/restore  puts the profile back as it was */
    public static function restore(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        Migrator::requireTable('profile_versions');
        $id = $req->intParam('id');
        $row = self::find($id);
        $v = Db::one('SELECT * FROM profile_versions WHERE id = :v AND profile_id = :p',
            ['v' => $req->intParam('version'), 'p' => $id]) ?? throw HttpError::notFound('Version');

        // Settings the schema no longer has are dropped; a pinned release must still exist
        $settings = Db::decode($v['settings']);
        $valid = Policy::validate(array_intersect_key($settings, Policy::SCHEMA));
        $changes = ['description' => $v['description'], 'settings' => Db::json((object) Policy::apply([], $valid))];
        $nameTaken = Db::value('SELECT id FROM profiles WHERE name = :n AND id <> :id', ['n' => $v['name'], 'id' => $id]) !== null;
        if (!$nameTaken) {
            $changes['name'] = $v['name'];
        }
        Db::update('profiles', $changes, ['id' => $id]);
        Policy::forgetProfile($id);
        $when = Util::iso($v['created_at']);
        ProfileHistory::record($id, 'restored', $p, "Restored the version from $when");
        Audit::log($p, 'profile.restored', 'profile', $id, ['version' => (int) $v['id'], 'from' => $when]);
        $out = self::present(self::find($id));
        if ($nameTaken) {
            $out['warning'] = "Kept the name {$row['name']}: another profile is now called {$v['name']}.";
        }
        return Response::json($out);
    }

    // ---- Export and import -----------------------------------------------------

    private const EXPORT_FORMAT = 'kiosky-shepherd-profiles';

    private static function exportOne(array $r): array
    {
        return [
            'name' => $r['name'],
            'description' => $r['description'],
            // Unredacted: a technician PIN travels as its PBKDF2 hash, never in clear
            'settings' => (object) Db::decode($r['settings']),
        ];
    }

    private static function exportDocument(array $rows, string $filename): Response
    {
        $doc = [
            'format' => self::EXPORT_FORMAT,
            'version' => 1,
            'exported_at' => gmdate('c'),
            'server_version' => SHEPHERD_VERSION,
            'profiles' => array_map(static fn (array $r) => self::exportOne($r), $rows),
        ];
        return Response::json($doc, 200, ['Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }

    /** GET /api/v1/admin/profiles/export  every profile, as a downloadable JSON file */
    public static function exportAll(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        return self::exportDocument(Db::all(self::SELECT . ' ORDER BY p.id = 1 DESC, p.name'),
            'kiosky-profiles-' . gmdate('Y-m-d') . '.json');
    }

    /** GET /api/v1/admin/profiles/{id}/export */
    public static function export(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        $row = self::find($req->intParam('id'));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $row['name'])), '-') ?: 'profile';
        return self::exportDocument([$row], "kiosky-profile-$slug.json");
    }

    /**
     * POST /api/v1/admin/profiles/import  a document from …/export
     * Every profile is created new; a name already in use gets " (2)", " (3)"… added.
     * Settings this server doesn't know are skipped and listed in the response.
     */
    public static function import(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $doc = $req->json();
        if (($doc['format'] ?? null) !== self::EXPORT_FORMAT || !is_array($doc['profiles'] ?? null)
            || !array_is_list($doc['profiles']) || $doc['profiles'] === []) {
            throw HttpError::validation(['file' => 'This isn\'t a Kiosky Shepherd profile export.']);
        }
        if (count($doc['profiles']) > 200) {
            throw HttpError::validation(['file' => 'At most 200 profiles per import.']);
        }

        $created = [];
        $skipped = [];
        Db::transaction(static function () use ($doc, $p, &$created, &$skipped): void {
            foreach ($doc['profiles'] as $i => $prof) {
                if (!is_array($prof)) {
                    throw HttpError::validation(["profiles.$i" => 'Must be an object.']);
                }
                try {
                    [$name, $description] = self::validateMeta($prof, true);
                } catch (HttpError $e) {
                    throw HttpError::validation(["profiles.$i" => 'Needs a name of up to 100 characters.']);
                }
                $settings = is_array($prof['settings'] ?? null) ? $prof['settings'] : [];
                $unknown = array_keys(array_diff_key($settings, Policy::SCHEMA));
                if ($unknown !== []) {
                    $skipped[$name] = $unknown;
                }
                try {
                    $valid = Policy::validate(array_intersect_key($settings, Policy::SCHEMA));
                } catch (HttpError $e) {
                    $fields = $e->details['fields'] ?? [];
                    throw HttpError::validation(array_combine(
                        array_map(static fn ($k) => "$name: $k", array_keys($fields)), array_values($fields)) ?: ["profiles.$i" => 'Invalid settings.']);
                }
                $finalName = self::freeName($name);
                $id = Db::insert('profiles', [
                    'name' => $finalName,
                    'description' => $description ?? '',
                    'settings' => Db::json((object) Policy::apply([], $valid)),
                ]);
                $created[] = ['id' => $id, 'name' => $finalName];
                ProfileHistory::record($id, 'imported', $p);
            }
        });
        Audit::log($p, 'profile.imported', 'profile', null, ['created' => $created, 'skipped_settings' => $skipped ?: null]);
        return Response::json(['created' => $created, 'skipped_settings' => (object) $skipped], 201);
    }

    /** $name, or $name (2), (3)… whichever is free. */
    private static function freeName(string $name): string
    {
        $candidate = $name;
        for ($n = 2; Db::value('SELECT id FROM profiles WHERE name = :n', ['n' => $candidate]) !== null; $n++) {
            $suffix = " ($n)";
            $candidate = mb_substr($name, 0, 100 - mb_strlen($suffix)) . $suffix;
        }
        return $candidate;
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function validateMeta(array $body, bool $requireName): array
    {
        $errors = [];
        $name = null;
        $description = null;
        if ($requireName || array_key_exists('name', $body)) {
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            if ($name === '' || mb_strlen($name) > 100) {
                $errors['name'] = 'Enter a name of up to 100 characters.';
            }
        }
        if (array_key_exists('description', $body)) {
            $description = is_string($body['description']) ? trim($body['description']) : '';
            if (mb_strlen($description) > 500) {
                $errors['description'] = 'Use at most 500 characters.';
            }
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        return [$name, $description];
    }
}
