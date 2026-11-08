<?php

declare(strict_types=1);

namespace Shepherd\Auth;

use Shepherd\Config;
use Shepherd\Db;
use Shepherd\Http\HttpError;
use Shepherd\Util;

/**
 * Passkeys (WebAuthn), with PHP's openssl extension only. A passkey can be the whole
 * sign-in (with user verification: fingerprint, face or device PIN) or the second
 * step after a password. Registration uses "none" attestation; the relying party
 * is the host of base_url, and every response is checked for the right challenge,
 * origin, relying party, user-present/verified flags, signature and counter.
 */
final class Passkeys
{
    private const ALG_ES256 = -7;
    private const ALG_RS256 = -257;

    public static function rpId(): string
    {
        return strtolower((string) parse_url(Util::baseUrl(), PHP_URL_HOST));
    }

    private static function origin(): string
    {
        $p = parse_url(Util::baseUrl());
        return strtolower(($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /** @return list<array<string, mixed>> */
    public static function forAdmin(int $adminId): array
    {
        return Db::all('SELECT id, credential_id, name, created_at, last_used_at FROM webauthn_credentials WHERE admin_id = :a ORDER BY id',
            ['a' => $adminId]);
    }

    public static function count(int $adminId): int
    {
        static $cache = [];
        if (!\Shepherd\Migrator::has('webauthn_credentials')) {
            return 0;
        }
        return $cache[$adminId] ??= (int) Db::value('SELECT COUNT(*) FROM webauthn_credentials WHERE admin_id = :a', ['a' => $adminId]);
    }

    // ---- Registration ----------------------------------------------------------------

    /** Options for navigator.credentials.create(); the challenge is kept in the session. */
    public static function registrationOptions(array $admin): array
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_reg'] = ['challenge' => self::b64($challenge), 'at' => time()];
        return [
            'challenge' => self::b64($challenge),
            'rp' => ['id' => self::rpId(), 'name' => 'Kiosky Shepherd'],
            'user' => ['id' => self::b64(self::userHandle((int) $admin['id'])), 'name' => (string) $admin['username'],
                'displayName' => (string) $admin['username']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ALG_ES256], ['type' => 'public-key', 'alg' => self::ALG_RS256]],
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'attestation' => 'none',
            'timeout' => 120000,
            'excludeCredentials' => array_map(static fn ($c) => ['type' => 'public-key', 'id' => $c['credential_id']],
                self::forAdmin((int) $admin['id'])),
        ];
    }

    /** Checks navigator.credentials.create()'s result and stores the passkey. */
    public static function register(array $admin, array $cred, string $name): void
    {
        $pending = $_SESSION['webauthn_reg'] ?? null;
        unset($_SESSION['webauthn_reg']);
        if (!is_array($pending) || time() - (int) $pending['at'] > 300) {
            throw HttpError::conflict('The passkey setup took too long. Try again.');
        }
        $client = self::clientData((string) ($cred['clientDataJSON'] ?? ''), 'webauthn.create', (string) $pending['challenge']);
        try {
            $att = Cbor::decode(self::unb64((string) ($cred['attestationObject'] ?? '')));
        } catch (\UnexpectedValueException) {
            $att = null;
        }
        if (!is_array($att) || !is_string($att['authData'] ?? null)) {
            throw HttpError::validation(['passkey' => 'The browser sent an unreadable passkey.']);
        }
        $auth = self::authData($att['authData'], true);
        if (!isset($auth['credentialId'], $auth['publicKey'])) {
            throw HttpError::validation(['passkey' => 'The passkey has no key in it.']);
        }
        $pem = self::coseToPem($auth['publicKey']);
        $id = self::b64($auth['credentialId']);
        if (strlen($id) > 512 || $client['type'] !== 'webauthn.create') {
            throw HttpError::validation(['passkey' => 'The passkey ID is too long.']);
        }
        try {
            Db::insert('webauthn_credentials', [
                'admin_id' => (int) $admin['id'], 'credential_id' => $id, 'public_key' => $pem,
                'sign_count' => $auth['signCount'], 'name' => Util::clip(trim($name) ?: 'Passkey', 100),
            ]);
        } catch (\PDOException $e) {
            throw HttpError::conflict('This passkey is already registered.');
        }
    }

    // ---- Authentication --------------------------------------------------------------

    /**
     * Options for navigator.credentials.get(). With an admin id, only that user's passkeys
     * (second step); without, any passkey for this site (passkey-only sign-in).
     */
    public static function assertionOptions(?int $adminId): array
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_auth'] = ['challenge' => self::b64($challenge), 'at' => time(), 'admin' => $adminId];
        $opts = ['challenge' => self::b64($challenge), 'rpId' => self::rpId(), 'timeout' => 120000,
            'userVerification' => $adminId === null ? 'required' : 'preferred'];
        if ($adminId !== null) {
            $opts['allowCredentials'] = array_map(static fn ($c) => ['type' => 'public-key', 'id' => $c['credential_id']],
                self::forAdmin($adminId));
        }
        return $opts;
    }

    /**
     * Checks navigator.credentials.get()'s result. Returns the admins row it signs in.
     * Passkey-only sign-in demands user verification (biometric or device PIN).
     */
    public static function verify(array $cred): array
    {
        $pending = $_SESSION['webauthn_auth'] ?? null;
        unset($_SESSION['webauthn_auth']);
        if (!is_array($pending) || time() - (int) $pending['at'] > 300) {
            throw new HttpError(401, 'passkey_failed', 'The passkey sign-in took too long. Try again.');
        }
        $credId = (string) ($cred['id'] ?? '');
        $row = Db::one('SELECT * FROM webauthn_credentials WHERE credential_id = :c', ['c' => $credId]);
        if ($row === null || ($pending['admin'] !== null && (int) $row['admin_id'] !== (int) $pending['admin'])) {
            throw new HttpError(401, 'passkey_failed', 'That passkey isn\'t registered here.');
        }
        $clientJson = self::unb64((string) ($cred['clientDataJSON'] ?? ''));
        self::clientData((string) ($cred['clientDataJSON'] ?? ''), 'webauthn.get', (string) $pending['challenge']);
        $authRaw = self::unb64((string) ($cred['authenticatorData'] ?? ''));
        $auth = self::authData($authRaw, false);
        if ($pending['admin'] === null && !$auth['uv']) {
            throw new HttpError(401, 'passkey_failed', 'Signing in with only a passkey needs your fingerprint, face or device PIN.');
        }
        $sig = self::unb64((string) ($cred['signature'] ?? ''));
        if (openssl_verify($authRaw . hash('sha256', $clientJson, true), $sig, (string) $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new HttpError(401, 'passkey_failed', 'The passkey signature didn\'t check out.');
        }
        // A counter that goes backwards suggests a cloned authenticator
        if ($auth['signCount'] > 0 || (int) $row['sign_count'] > 0) {
            if ($auth['signCount'] <= (int) $row['sign_count']) {
                throw new HttpError(401, 'passkey_failed', 'This passkey\'s counter went backwards; it may have been copied. Remove it and add it again.');
            }
        }
        Db::update('webauthn_credentials', ['sign_count' => $auth['signCount'], 'last_used_at' => Util::now()], ['id' => (int) $row['id']]);
        $admin = Db::one('SELECT * FROM admins WHERE id = :id', ['id' => (int) $row['admin_id']]);
        if ($admin === null || (int) $admin['disabled'] === 1) {
            throw new HttpError(401, 'passkey_failed', 'This account is disabled.');
        }
        return $admin;
    }

    // ---- Parsing ---------------------------------------------------------------------

    private static function userHandle(int $adminId): string
    {
        // Stable per user and server, but reveals nothing
        return substr(hash_hmac('sha256', 'webauthn-user:' . $adminId, self::secret(), true), 0, 16);
    }

    private static function secret(): string
    {
        $file = Config::storage('webauthn-secret');
        $s = @file_get_contents($file);
        if (!is_string($s) || strlen($s) < 32) {
            $s = random_bytes(32);
            file_put_contents($file, $s, LOCK_EX);
            @chmod($file, 0600);
        }
        return $s;
    }

    private static function clientData(string $b64, string $type, string $challenge): array
    {
        $c = json_decode(self::unb64($b64), true);
        if (!is_array($c) || ($c['type'] ?? null) !== $type) {
            throw new HttpError(401, 'passkey_failed', 'The browser sent the wrong kind of passkey response.');
        }
        if (!hash_equals($challenge, (string) ($c['challenge'] ?? ''))) {
            throw new HttpError(401, 'passkey_failed', 'The passkey answered a different request. Try again.');
        }
        if (strtolower(rtrim((string) ($c['origin'] ?? ''), '/')) !== self::origin()) {
            throw new HttpError(401, 'passkey_failed', 'The passkey was used on another address (' . Util::clip((string) ($c['origin'] ?? ''), 100)
                . '). Open the console at ' . self::origin() . ' (base_url).');
        }
        return $c;
    }

    /** @return array{flags: int, uv: bool, signCount: int, credentialId?: string, publicKey?: array} */
    private static function authData(string $d, bool $expectCredential): array
    {
        if (strlen($d) < 37 || !hash_equals(hash('sha256', self::rpId(), true), substr($d, 0, 32))) {
            throw new HttpError(401, 'passkey_failed', 'The passkey belongs to another site.');
        }
        $flags = ord($d[32]);
        if (!($flags & 0x01)) {
            throw new HttpError(401, 'passkey_failed', 'The passkey wasn\'t confirmed by a touch.');
        }
        $out = ['flags' => $flags, 'uv' => (bool) ($flags & 0x04), 'signCount' => unpack('N', substr($d, 33, 4))[1]];
        if ($expectCredential) {
            if (!($flags & 0x40) || strlen($d) < 55) {
                throw HttpError::validation(['passkey' => 'The passkey has no credential data.']);
            }
            $len = unpack('n', substr($d, 53, 2))[1];
            $out['credentialId'] = substr($d, 55, $len);
            $offset = 55 + $len;
            try {
                $key = Cbor::decode(substr($d, $offset));
            } catch (\UnexpectedValueException) {
                $key = null;
            }
            if (!is_array($key)) {
                throw HttpError::validation(['passkey' => 'Unreadable passkey key.']);
            }
            $out['publicKey'] = $key;
        }
        return $out;
    }

    /** A COSE public key (ES256 or RS256) → PEM. */
    private static function coseToPem(array $k): string
    {
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === self::ALG_ES256 && ($k[-1] ?? null) === 1 && is_string($k[-2] ?? null) && is_string($k[-3] ?? null)
            && strlen($k[-2]) === 32 && strlen($k[-3]) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
        } elseif ($kty === 3 && $alg === self::ALG_RS256 && is_string($k[-1] ?? null) && is_string($k[-2] ?? null)) {
            $rsa = self::derSeq(self::derInt($k[-1]) . self::derInt($k[-2]));
            $der = self::derSeq(self::derSeq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00") . "\x03" . self::derLen(strlen($rsa) + 1) . "\x00" . $rsa);
        } else {
            throw HttpError::validation(['passkey' => 'This passkey uses an algorithm that isn\'t supported (use ES256 or RS256).']);
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw HttpError::validation(['passkey' => 'The passkey\'s key is invalid.']);
        }
        return $pem;
    }

    private static function derLen(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function derSeq(string $content): string
    {
        return "\x30" . self::derLen(strlen($content)) . $content;
    }

    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\0" . $bytes;
        }
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
