<?php

declare(strict_types=1);

namespace Shepherd\Auth;

use Shepherd\Config;
use Shepherd\HttpClient;
use Shepherd\Util;

/**
 * One single sign-on provider from the 'sso' section of config/config.php:
 * either 'type' => 'entra' (Microsoft Entra ID, built from the tenant ID) or
 * 'type' => 'oidc' (any OpenID Connect provider, built from its issuer URL).
 * Discovery documents and signing keys are cached under storage/cache.
 */
final class OidcProvider
{
    private const CACHE_SECONDS = 3600;
    private const ENTRA_MULTI_TENANT = ['common', 'organizations', 'consumers'];

    /** @param list<string> $scopes */
    private function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly array $scopes,
        public readonly string $roleClaim,
        /** @var array<string, list<string>> role => claim values */
        public readonly array $roleMap,
        public readonly ?string $defaultRole,
        public readonly string $usernameClaim,
        public readonly bool $useUserinfo,
        public readonly ?string $tokenAuth,
        public readonly ?string $prompt,
    ) {
    }

    /** @return array<string, self> every valid provider, keyed by id */
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $out = [];
        foreach ((array) Config::get('sso', []) as $id => $cfg) {
            if (!is_array($cfg) || !empty($cfg['disabled'])) {
                continue;
            }
            try {
                $out[(string) $id] = self::fromConfig((string) $id, $cfg);
            } catch (\InvalidArgumentException $e) {
                error_log("[kiosky-shepherd] sso provider '$id' is ignored: " . $e->getMessage());
            }
        }
        return $cache = $out;
    }

    public static function find(string $id): ?self
    {
        return self::all()[$id] ?? null;
    }

    /** Label to show for a provider id, even one that has since been removed from the config. */
    public static function labelFor(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        return self::find($id)?->label ?? $id;
    }

    public static function fromConfig(string $id, array $c): self
    {
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $id)) {
            throw new \InvalidArgumentException('Use 1–32 lower-case letters, digits, _ or - for the provider id.');
        }
        $type = (string) ($c['type'] ?? 'oidc');
        if ($type === 'entra') {
            $tenant = trim((string) ($c['tenant'] ?? ''));
            if ($tenant === '' || in_array(strtolower($tenant), self::ENTRA_MULTI_TENANT, true)
                || !preg_match('/^[A-Za-z0-9.-]+$/', $tenant)) {
                throw new \InvalidArgumentException("Set 'tenant' to your directory (tenant) ID.");
            }
            $issuer = 'https://login.microsoftonline.com/' . $tenant . '/v2.0';
            $defaults = ['label' => 'Sign in with Microsoft', 'role_claim' => 'roles', 'username_claim' => 'preferred_username',
                'scopes' => ['openid', 'profile', 'email'], 'userinfo' => false, 'token_auth' => 'client_secret_post'];
        } elseif ($type === 'oidc') {
            $issuer = rtrim(trim((string) ($c['issuer'] ?? '')), '/');
            $defaults = ['label' => 'Sign in with ' . $id, 'role_claim' => 'groups', 'username_claim' => 'preferred_username',
                'scopes' => ['openid', 'profile', 'email'], 'userinfo' => true];
        } else {
            throw new \InvalidArgumentException("'type' must be 'entra' or 'oidc'.");
        }
        $c += $defaults;

        $parts = parse_url($issuer);
        $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
        if (!isset($parts['scheme'], $parts['host']) || !($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $local))) {
            throw new \InvalidArgumentException("Set 'issuer' to the provider's https:// issuer URL.");
        }
        if (trim((string) ($c['client_id'] ?? '')) === '') {
            throw new \InvalidArgumentException("Set 'client_id'.");
        }

        $roleMap = [];
        foreach ((array) ($c['role_map'] ?? []) as $role => $values) {
            if (!isset(Principal::ROLES[$role])) {
                throw new \InvalidArgumentException("role_map keys must be viewer, operator or admin, not '$role'.");
            }
            $roleMap[$role] = array_values(array_map('strval', (array) $values));
        }
        $default = $c['default_role'] ?? null;
        if ($default !== null && !isset(Principal::ROLES[$default])) {
            throw new \InvalidArgumentException("'default_role' must be viewer, operator, admin or null.");
        }
        $tokenAuth = $c['token_auth'] ?? null;
        if ($tokenAuth !== null && !in_array($tokenAuth, ['client_secret_basic', 'client_secret_post'], true)) {
            throw new \InvalidArgumentException("'token_auth' must be client_secret_basic or client_secret_post.");
        }
        $scopes = array_values(array_unique(array_merge(['openid'], array_map('strval', (array) $c['scopes']))));

        return new self(
            $id,
            $type,
            Util::clip((string) $c['label'], 60) ?? $id,
            $issuer,
            trim((string) $c['client_id']),
            (string) ($c['client_secret'] ?? ''),
            $scopes,
            (string) $c['role_claim'],
            $roleMap,
            $default,
            (string) $c['username_claim'],
            (bool) $c['userinfo'],
            $tokenAuth,
            isset($c['prompt']) ? (string) $c['prompt'] : null,
        );
    }

    /** True when roles come from the identity provider and can't be changed in the console. */
    public function managesRoles(): bool
    {
        return $this->roleMap !== [];
    }

    public function redirectUri(): string
    {
        return Util::baseUrl() . '/sso/' . $this->id . '/callback';
    }

    /** The provider's discovery document, checked against the configured issuer. */
    public function metadata(): array
    {
        $url = $this->issuer . '/.well-known/openid-configuration';
        $doc = self::cached('discovery-' . $url, static fn () => HttpClient::json('GET', $url));
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
            if (!isset($doc[$k]) || !is_string($doc[$k])) {
                throw new \RuntimeException("The discovery document at $url has no $k.");
            }
        }
        if (str_contains($doc['issuer'], '{tenantid}')) {
            throw new \RuntimeException("Set 'tenant' to your own directory (tenant) ID, not a multi-tenant endpoint.");
        }
        // Entra may be configured with a domain name and answers with the tenant GUID
        if ($this->type === 'oidc' && rtrim($doc['issuer'], '/') !== $this->issuer) {
            throw new \RuntimeException("The provider's issuer is {$doc['issuer']}, but the config says {$this->issuer}.");
        }
        return $doc;
    }

    /** Signing keys. $refresh bypasses the cache, for when a token names a key we haven't seen. */
    public function jwks(bool $refresh = false): array
    {
        $url = $this->metadata()['jwks_uri'];
        return self::cached('jwks-' . $url, static fn () => HttpClient::json('GET', $url), $refresh);
    }

    private static function cached(string $key, callable $fetch, bool $refresh = false): array
    {
        $dir = Config::storage('cache');
        $file = $dir . '/oidc-' . hash('sha256', $key) . '.json';
        if (!$refresh && is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        $data = $fetch();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $data;
    }
}
