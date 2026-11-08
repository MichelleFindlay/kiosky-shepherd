<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Policy;
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
