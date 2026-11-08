<?php

declare(strict_types=1);

namespace Shepherd\Api\Admin;

use Shepherd\Audit;
use Shepherd\Auth\AdminAuth;
use Shepherd\Auth\OidcProvider;
use Shepherd\Auth\Principal;
use Shepherd\Db;
use Shepherd\Devices;
use Shepherd\Migrator;
use Shepherd\Http\HttpError;
use Shepherd\Http\Request;
use Shepherd\Http\Response;
use Shepherd\Util;

/** Console users and their API tokens. */
final class UsersApi
{
    private static function presentUser(array $u): array
    {
        $sso = $u['sso_provider'] ?? null;
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'email' => $u['email'] ?? null,
            'sso' => $sso === null ? null : ['id' => $sso, 'label' => OidcProvider::labelFor($sso)],
            // The identity provider sets this user's role at every sign-in
            'role_managed' => $sso !== null && (OidcProvider::find($sso)?->managesRoles() ?? false),
            'role' => $u['role'],
            'two_factor' => AdminAuth::hasTotp($u),
            'scope_tags' => AdminAuth::scopeOf($u),
            'disabled' => (bool) $u['disabled'],
            'created_at' => Util::iso($u['created_at']),
            'last_login_at' => Util::iso($u['last_login_at']),
        ];
    }

    /** GET /api/v1/admin/users */
    public static function list(Request $req): Response
    {
        AdminAuth::require($req, 'admin');
        $rows = Db::all('SELECT * FROM admins ORDER BY username');
        return Response::json(array_map(static fn ($u) => self::presentUser($u), $rows));
    }

    /**
     * scope_tags from a request body: a list of tags, or null/[] for every device.
     *
     * @return list<string>|null
     */
    private static function scopeInput(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        $tags = Devices::validateTags($v, 'scope_tags');
        if ($tags !== []) {
            Migrator::requireTable('profile_versions'); // the 1.4.0 migration adds admins.scope_tags
        }
        return $tags === [] ? null : $tags;
    }

    /** POST /api/v1/admin/users  {username, password, role, scope_tags?} */
    public static function create(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $username = trim((string) $req->input('username', ''));
        $password = (string) $req->input('password', '');
        $role = (string) $req->input('role', 'operator');
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._@\-]{2,64}$/', $username)) {
            $errors['username'] = 'Use 2–64 letters, digits or . _ @ -';
        }
        if (($err = AdminAuth::validatePassword($password)) !== null) {
            $errors['password'] = $err;
        }
        if (!isset(Principal::ROLES[$role])) {
            $errors['role'] = 'Use viewer, operator or admin.';
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        $scope = self::scopeInput($req->input('scope_tags'));
        if ($scope !== null && $role === 'admin') {
            throw HttpError::validation(['scope_tags' => 'Admins always see every device. Choose operator or viewer.']);
        }
        try {
            $id = Db::insert('admins', [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ] + ($scope !== null ? ['scope_tags' => Db::json($scope)] : []));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw HttpError::validation(['username' => 'That username is taken.']);
            }
            throw $e;
        }
        Audit::log($p, 'user.created', 'admin', $id, ['username' => $username, 'role' => $role]);
        return Response::json(self::presentUser(Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $id])), 201);
    }

    /** PATCH /api/v1/admin/users/{id}  {role?, password?, disabled?, reset_2fa?: true, scope_tags?: [..] | null} */
    public static function update(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $id = $req->intParam('id');
        $user = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $id]) ?? throw HttpError::notFound('User');
        $b = $req->json();
        $changes = [];
        $errors = [];
        $present = self::presentUser($user);
        if (array_key_exists('role', $b) && $present['role_managed'] && $b['role'] !== $user['role']) {
            $errors['role'] = "{$present['sso']['label']} sets this user's role. Change their groups or app roles there.";
        } elseif (array_key_exists('role', $b)) {
            if (!is_string($b['role']) || !isset(Principal::ROLES[$b['role']])) {
                $errors['role'] = 'Use viewer, operator or admin.';
            } else {
                $changes['role'] = $b['role'];
            }
        }
        if (array_key_exists('disabled', $b)) {
            if (!is_bool($b['disabled'])) {
                $errors['disabled'] = 'Must be true or false.';
            } else {
                $changes['disabled'] = $b['disabled'];
            }
        }
        if (array_key_exists('password', $b) && $present['sso'] !== null) {
            $errors['password'] = 'This user signs in with single sign-on and has no password here.';
        } elseif (array_key_exists('password', $b)) {
            $err = is_string($b['password']) ? AdminAuth::validatePassword($b['password']) : 'Must be text.';
            if ($err !== null) {
                $errors['password'] = $err;
            } else {
                $changes['password_hash'] = password_hash($b['password'], PASSWORD_DEFAULT);
            }
        }
        if (array_key_exists('scope_tags', $b)) {
            $scope = self::scopeInput($b['scope_tags']);
            if ($scope !== null && ($changes['role'] ?? $user['role']) === 'admin') {
                $errors['scope_tags'] = 'Admins always see every device. Make them an operator or viewer first.';
            } elseif ($scope !== null || array_key_exists('scope_tags', $user)) {
                $changes['scope_tags'] = $scope === null ? null : Db::json($scope);
            }
        }
        if (($b['reset_2fa'] ?? null) === true) {
            if ($id === $p->adminId) {
                $errors['reset_2fa'] = 'Turn off your own two-factor sign-in under Your account.';
            } elseif (AdminAuth::hasTotp($user)) {
                $changes += ['totp_secret' => null, 'totp_last_step' => null, 'totp_recovery' => null];
            }
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        $demotes = (isset($changes['role']) && $changes['role'] !== 'admin') || !empty($changes['disabled']);
        if ($demotes && $user['role'] === 'admin' && !$user['disabled']) {
            $admins = (int) Db::value("SELECT COUNT(*) FROM admins WHERE role = 'admin' AND disabled = 0");
            if ($admins <= 1) {
                throw HttpError::conflict('This is the last active admin.');
            }
        }
        if ($changes !== []) {
            Db::update('admins', $changes, ['id' => $id]);
            $logged = $changes;
            if (isset($logged['password_hash'])) {
                $logged['password_hash'] = 'changed';
            }
            if (array_key_exists('totp_secret', $logged)) {
                unset($logged['totp_secret'], $logged['totp_last_step'], $logged['totp_recovery']);
                $logged['two_factor'] = 'reset';
            }
            Audit::log($p, 'user.updated', 'admin', $id, $logged);
        }
        return Response::json(self::presentUser(Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $id])));
    }

    /** DELETE /api/v1/admin/users/{id} */
    public static function delete(Request $req): Response
    {
        $p = AdminAuth::require($req, 'admin');
        $id = $req->intParam('id');
        if ($id === $p->adminId) {
            throw HttpError::conflict('You cannot delete yourself.');
        }
        $user = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $id]) ?? throw HttpError::notFound('User');
        if ($user['role'] === 'admin'
            && (int) Db::value("SELECT COUNT(*) FROM admins WHERE role = 'admin' AND disabled = 0") <= 1) {
            throw HttpError::conflict('This is the last active admin.');
        }
        Db::run('DELETE FROM admins WHERE id = :id', ['id' => $id]);
        Audit::log($p, 'user.deleted', 'admin', $id, ['username' => $user['username']]);
        return Response::noContent();
    }

    // ---- API tokens ---------------------------------------------------------

    /** GET /api/v1/admin/tokens  (admins see all tokens, others their own) */
    public static function listTokens(Request $req): Response
    {
        $p = AdminAuth::require($req);
        $sql = 'SELECT t.id, t.name, t.prefix, t.role, t.created_at, t.last_used_at, t.expires_at, a.username
                  FROM api_tokens t JOIN admins a ON a.id = t.admin_id';
        $rows = $p->can('admin')
            ? Db::all("$sql ORDER BY t.id DESC")
            : Db::all("$sql WHERE t.admin_id = :a ORDER BY t.id DESC", ['a' => $p->adminId]);
        return Response::json(array_map(static fn ($t) => [
            'id' => (int) $t['id'],
            'name' => $t['name'],
            'prefix' => $t['prefix'],
            'role' => $t['role'],
            'owner' => $t['username'],
            'created_at' => Util::iso($t['created_at']),
            'last_used_at' => Util::iso($t['last_used_at']),
            'expires_at' => Util::iso($t['expires_at']),
        ], $rows));
    }

    /** POST /api/v1/admin/tokens  {name, role?, expires_days?}  — the token is shown once */
    public static function createToken(Request $req): Response
    {
        $p = AdminAuth::require($req);
        if ($p->type !== 'admin') {
            throw HttpError::forbidden('Create API tokens from the web console.');
        }
        $name = trim((string) $req->input('name', ''));
        $role = (string) $req->input('role', $p->role);
        $days = $req->input('expires_days');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter a name of up to 100 characters.';
        }
        if (!isset(Principal::ROLES[$role]) || !$p->can($role)) {
            $errors['role'] = 'Choose a role no higher than your own.';
        }
        if ($days !== null && (!is_int($days) || $days < 1 || $days > 3650)) {
            $errors['expires_days'] = 'Use 1 to 3650 days, or null for no expiry.';
        }
        if ($errors !== []) {
            throw HttpError::validation($errors);
        }
        $token = AdminAuth::TOKEN_PREFIX . Util::token(32);
        $id = Db::insert('api_tokens', [
            'admin_id' => $p->adminId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'prefix' => substr($token, 0, 10),
            'role' => $role,
            'expires_at' => $days === null ? null : gmdate('Y-m-d H:i:s', time() + $days * 86400),
        ]);
        Audit::log($p, 'token.created', 'api_token', $id, ['name' => $name, 'role' => $role]);
        return Response::json(['id' => $id, 'name' => $name, 'role' => $role, 'token' => $token], 201);
    }

    /** DELETE /api/v1/admin/tokens/{id} */
    public static function deleteToken(Request $req): Response
    {
        $p = AdminAuth::require($req);
        $id = $req->intParam('id');
        $t = Db::one('SELECT * FROM api_tokens WHERE id = :id', ['id' => $id]) ?? throw HttpError::notFound('Token');
        if ((int) $t['admin_id'] !== $p->adminId) {
            $p->require('admin');
        }
        Db::run('DELETE FROM api_tokens WHERE id = :id', ['id' => $id]);
        Audit::log($p, 'token.deleted', 'api_token', $id, ['name' => $t['name']]);
        return Response::noContent();
    }
}
