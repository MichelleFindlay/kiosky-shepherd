<?php

declare(strict_types=1);

namespace Shepherd\Auth;

use Shepherd\Audit;
use Shepherd\Db;
use Shepherd\HttpClient;
use Shepherd\Http\Request;
use Shepherd\Util;

/**
 * OpenID Connect authorization code flow with PKCE, state and nonce.
 *
 * The console's session cookie is SameSite=Strict, so browsers don't send it when
 * the identity provider redirects back. The pending sign-in is instead bound to the
 * browser by a separate short-lived SameSite=Lax cookie that only /sso/ sees.
 */
final class Sso
{
    public const COOKIE = 'shepherd_sso';
    private const MAX_AGE = 600;
    private const LEEWAY = 120;

    /** Builds the provider's authorization URL and the browser-binding cookie value. */
    public static function begin(OidcProvider $p): array
    {
        $meta = $p->metadata();
        $state = Util::token(32);
        $browser = Util::token(32);
        $nonce = Util::token(32);
        $verifier = Util::token(48);

        Db::run('DELETE FROM sso_logins WHERE created_at < NOW() - INTERVAL 15 MINUTE');
        Db::insert('sso_logins', [
            'state_hash' => hash('sha256', $state),
            'provider' => $p->id,
            'browser_hash' => hash('sha256', $browser),
            'nonce' => $nonce,
            'code_verifier' => $verifier,
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $p->clientId,
            'redirect_uri' => $p->redirectUri(),
            'scope' => implode(' ', $p->scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        if ($p->prompt !== null) {
            $params['prompt'] = $p->prompt;
        }
        $url = $meta['authorization_endpoint'];
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return ['url' => $url, 'browser' => $browser];
    }

    /**
     * Handles the redirect back from the provider and returns the admins row to sign in.
     * Throws SsoError with a code the console turns into a message.
     */
    public static function complete(OidcProvider $p, Request $req, ?string $browserCookie): array
    {
        $state = (string) ($req->query['state'] ?? '');
        $code = (string) ($req->query['code'] ?? '');

        if ($state === '' || $browserCookie === null || $browserCookie === '') {
            throw new SsoError('sso_expired', 'No sign-in is in progress in this browser (missing state or cookie).');
        }
        $pending = Db::transaction(static function () use ($state): ?array {
            $row = Db::one('SELECT * FROM sso_logins WHERE state_hash = :h FOR UPDATE', ['h' => hash('sha256', $state)]);
            if ($row !== null) {
                Db::run('DELETE FROM sso_logins WHERE state_hash = :h', ['h' => $row['state_hash']]);
            }
            return $row;
        });
        if ($pending === null || $pending['provider'] !== $p->id
            || !hash_equals((string) $pending['browser_hash'], hash('sha256', $browserCookie))
            || strtotime($pending['created_at'] . ' UTC') < time() - self::MAX_AGE) {
            throw new SsoError('sso_expired', 'Unknown, expired or already-used state, or it was started in another browser.');
        }

        if (isset($req->query['error'])) {
            $err = preg_replace('/[^a-z_]/', '', strtolower((string) $req->query['error']));
            $desc = Util::clip((string) ($req->query['error_description'] ?? ''), 300);
            throw new SsoError('sso_denied', "The provider returned $err: $desc");
        }
        if ($code === '') {
            throw new SsoError('sso_failed', 'The provider sent no authorization code.');
        }

        $claims = self::exchange($p, $code, (string) $pending['code_verifier'], (string) $pending['nonce']);
        return self::provision($p, $claims, $req);
    }

    /** Swaps the code for tokens and returns the verified ID token claims (plus userinfo). */
    private static function exchange(OidcProvider $p, string $code, string $verifier, string $nonce): array
    {
        $meta = $p->metadata();
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $p->redirectUri(),
            'code_verifier' => $verifier,
        ];
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $supported = (array) ($meta['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
        $auth = $p->tokenAuth ?? (in_array('client_secret_basic', $supported, true) ? 'client_secret_basic' : 'client_secret_post');
        if ($p->clientSecret === '') {
            $form['client_id'] = $p->clientId; // public client, PKCE only
        } elseif ($auth === 'client_secret_basic') {
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($p->clientId) . ':' . urlencode($p->clientSecret));
        } else {
            $form['client_id'] = $p->clientId;
            $form['client_secret'] = $p->clientSecret;
        }
        $tokens = HttpClient::json('POST', $meta['token_endpoint'], $headers, http_build_query($form, '', '&'));
        if (!isset($tokens['id_token']) || !is_string($tokens['id_token'])) {
            throw new SsoError('sso_failed', 'The token response has no id_token. Is the "openid" scope allowed?');
        }

        $jwt = Jwt::decode($tokens['id_token']);
        $kid = isset($jwt['header']['kid']) ? (string) $jwt['header']['kid'] : null;
        $jwks = $p->jwks();
        if (!Jwt::hasKey($jwks, $kid)) {
            $jwks = $p->jwks(true); // the provider rotated its keys
        }
        if (!Jwt::verify($jwt, $jwks)) {
            throw new SsoError('sso_failed', 'The ID token signature is not valid.');
        }

        $c = $jwt['payload'];
        $now = time();
        $aud = (array) ($c['aud'] ?? []);
        if (($c['iss'] ?? null) !== $meta['issuer']) {
            throw new SsoError('sso_failed', 'The ID token issuer is ' . json_encode($c['iss'] ?? null) . ", expected {$meta['issuer']}.");
        }
        if (!in_array($p->clientId, $aud, true) || (count($aud) > 1 && ($c['azp'] ?? null) !== $p->clientId)) {
            throw new SsoError('sso_failed', 'The ID token was issued for a different client.');
        }
        if (!is_numeric($c['exp'] ?? null) || (int) $c['exp'] < $now - self::LEEWAY
            || (isset($c['nbf']) && (int) $c['nbf'] > $now + self::LEEWAY)
            || (isset($c['iat']) && (int) $c['iat'] > $now + self::LEEWAY)) {
            throw new SsoError('sso_failed', 'The ID token has expired or is not valid yet. Check the server clock.');
        }
        if (!is_string($c['nonce'] ?? null) || !hash_equals($nonce, $c['nonce'])) {
            throw new SsoError('sso_failed', 'The ID token nonce does not match.');
        }
        $sub = $c['sub'] ?? null;
        if (!is_string($sub) || !preg_match('/^[\x21-\x7e]{1,255}$/', $sub)) {
            throw new SsoError('sso_failed', 'The ID token has no usable sub claim.');
        }

        // Generic providers often put groups only in userinfo
        $needsUserinfo = $p->useUserinfo && self::claim($c, $p->roleClaim) === null;
        if ($needsUserinfo && isset($meta['userinfo_endpoint'], $tokens['access_token']) && is_string($tokens['access_token'])) {
            $info = HttpClient::json('GET', (string) $meta['userinfo_endpoint'], ['Authorization' => 'Bearer ' . $tokens['access_token']]);
            if (($info['sub'] ?? null) === $sub) {
                $c += $info; // claims already in the ID token win
            }
        }
        return $c;
    }

    /** Finds or creates the console user for these claims and applies the mapped role. */
    private static function provision(OidcProvider $p, array $claims, Request $req): array
    {
        $role = self::mapRole($p, $claims);
        if ($role === null) {
            if ($p->roleClaim === 'groups' && self::claim($claims, 'groups') === null
                && isset($claims['_claim_names']['groups'])) {
                throw new SsoError('groups_overage', 'The user is in too many groups for Entra ID to put them in the token.');
            }
            $seen = self::claim($claims, $p->roleClaim);
            throw new SsoError('no_role', "No role matched. {$p->roleClaim} = " . Util::clip(json_encode($seen, JSON_UNESCAPED_SLASHES), 300));
        }

        $sub = (string) $claims['sub'];
        $email = self::firstString($claims, ['email', 'upn']);
        $email = $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? Util::clip($email, 255) : null;

        return Db::transaction(static function () use ($p, $claims, $sub, $email, $role, $req): array {
            $admin = Db::one('SELECT * FROM admins WHERE sso_provider = :p AND sso_subject = :s FOR UPDATE',
                ['p' => $p->id, 's' => $sub]);
            if ($admin !== null) {
                if ((int) $admin['disabled'] === 1) {
                    throw new SsoError('disabled', "User {$admin['username']} is disabled in Kiosky Shepherd.");
                }
                $changes = ['email' => $email ?? $admin['email']];
                if ($p->managesRoles() && $admin['role'] !== $role) {
                    $changes['role'] = $role;
                    Audit::log(null, 'user.role_synced', 'admin', (int) $admin['id'],
                        ['username' => $admin['username'], 'from' => $admin['role'], 'to' => $role, 'provider' => $p->id], $req->ip());
                }
                Db::update('admins', $changes, ['id' => (int) $admin['id']]);
                return Db::one('SELECT * FROM admins WHERE id = :id', ['id' => (int) $admin['id']]);
            }

            $username = self::uniqueUsername(self::firstString($claims, [$p->usernameClaim, 'preferred_username', 'email', 'upn'])
                ?? $p->id . '-' . substr(hash('sha256', $sub), 0, 10));
            $id = Db::insert('admins', [
                'username' => $username,
                'password_hash' => null,
                'sso_provider' => $p->id,
                'sso_subject' => $sub,
                'email' => $email,
                'role' => $role,
            ]);
            Audit::log(null, 'user.provisioned', 'admin', $id,
                ['username' => $username, 'role' => $role, 'provider' => $p->id], $req->ip());
            return Db::one('SELECT * FROM admins WHERE id = :id', ['id' => $id]);
        });
    }

    /** Highest role whose mapped values appear in the role claim, else the default role. */
    public static function mapRole(OidcProvider $p, array $claims): ?string
    {
        $have = array_map('strval', array_filter((array) (self::claim($claims, $p->roleClaim) ?? []), 'is_scalar'));
        $best = null;
        foreach ($p->roleMap as $role => $values) {
            if (array_intersect($values, $have) !== [] && ($best === null || Principal::ROLES[$role] > Principal::ROLES[$best])) {
                $best = $role;
            }
        }
        return $best ?? $p->defaultRole;
    }

    /** Reads a claim by name, or by dotted path for nested claims such as realm_access.roles. */
    public static function claim(array $claims, string $path): mixed
    {
        if (array_key_exists($path, $claims)) {
            return $claims[$path];
        }
        $node = $claims;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }

    private static function firstString(array $claims, array $names): ?string
    {
        foreach ($names as $n) {
            $v = self::claim($claims, $n);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }

    /** Turns a claim into a valid, unused console username: alice@example.com, then alice@example.com-2… */
    private static function uniqueUsername(string $wanted): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9._@\-]+/', '-', $wanted), '-');
        if (strlen($base) < 2) {
            $base = 'sso-' . substr(hash('sha256', $wanted), 0, 8);
        }
        $base = substr($base, 0, 64);
        for ($i = 1; $i < 100; $i++) {
            $name = $i === 1 ? $base : substr($base, 0, 64 - strlen("-$i")) . "-$i";
            if ((int) Db::value('SELECT COUNT(*) FROM admins WHERE username = :u', ['u' => $name]) === 0) {
                return $name;
            }
        }
        return 'sso-' . Util::code(12, true);
    }
}
