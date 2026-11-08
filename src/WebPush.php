<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Browser push notifications for alerts (Web Push, RFC 8030/8291/8292), with PHP's
 * openssl extension only: VAPID-signed (ES256) and aes128gcm-encrypted. The VAPID key
 * pair is created on first use and kept in storage/vapid.json.
 *
 * Subscriptions are accepted only for the well-known browser push services, so the
 * feature can't be used to make the server send requests anywhere else.
 */
final class WebPush
{
    private const KEY_FILE = 'vapid.json';
    /** Push services of Chrome/Edge/Android, Firefox, Safari/iOS and Windows */
    private const SERVICE_HOSTS = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];
    /** DER prefix of a P-256 SubjectPublicKeyInfo; the 65-byte uncompressed point follows */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function available(): bool
    {
        return function_exists('openssl_pkey_derive') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    // ---- Keys ----------------------------------------------------------------------

    /** @return array{public: string, private_pem: string} public is the raw point, base64url */
    private static function keys(): array
    {
        $file = Config::storage(self::KEY_FILE);
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data) && is_string($data['public'] ?? null) && is_string($data['private_pem'] ?? null)) {
            return $data;
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Couldn\'t create a key for push notifications (PHP openssl).');
        }
        $data = ['public' => self::b64(self::rawPoint($key)), 'private_pem' => $pem];
        file_put_contents($file, json_encode($data), LOCK_EX);
        @chmod($file, 0600);
        return $data;
    }

    /** The application server key browsers subscribe with (base64url). */
    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    // ---- Subscriptions -------------------------------------------------------------

    public static function subscriberCount(): int
    {
        static $n = null;
        return $n ??= Migrator::has('push_subscriptions') ? (int) Db::value('SELECT COUNT(*) FROM push_subscriptions') : 0;
    }

    public static function subscribe(int $adminId, string $endpoint, string $p256dh, string $auth, ?string $ua): void
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $known = false;
        foreach (self::SERVICE_HOSTS as $h) {
            $known = $known || $host === $h || str_ends_with($host, '.' . $h);
        }
        if (!str_starts_with($endpoint, 'https://') || !$known || strlen($endpoint) > 1000) {
            throw Http\HttpError::validation(['endpoint' => 'This browser\'s push service isn\'t supported.']);
        }
        if (strlen(self::unb64($p256dh)) !== 65 || strlen(self::unb64($auth)) !== 16) {
            throw Http\HttpError::validation(['keys' => 'The browser sent invalid push keys.']);
        }
        Db::run('INSERT INTO push_subscriptions (admin_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
                 VALUES (:a, :e, :h, :p, :k, :u)
                 ON DUPLICATE KEY UPDATE admin_id = :a2, p256dh = :p2, auth = :k2, user_agent = :u2',
            ['a' => $adminId, 'e' => $endpoint, 'h' => hash('sha256', $endpoint), 'p' => $p256dh, 'k' => $auth,
             'u' => Util::clip($ua, 255), 'a2' => $adminId, 'p2' => $p256dh, 'k2' => $auth, 'u2' => Util::clip($ua, 255)]);
    }

    public static function unsubscribe(int $adminId, string $endpoint): void
    {
        Db::run('DELETE FROM push_subscriptions WHERE admin_id = :a AND endpoint_hash = :h',
            ['a' => $adminId, 'h' => hash('sha256', $endpoint)]);
    }

    /**
     * Sends to every subscribed browser of users who see the whole fleet (alerts are
     * fleet-wide, so users limited to some devices don't get them).
     */
    public static function broadcast(string $title, string $body, string $url): void
    {
        $subs = Db::all('SELECT s.*, a.role, a.scope_tags, a.disabled FROM push_subscriptions s JOIN admins a ON a.id = s.admin_id');
        $subs = array_filter($subs, static fn ($s) => (int) $s['disabled'] === 0 && Auth\AdminAuth::scopeOf($s) === null);
        self::sendMany($subs, $title, $body, $url);
    }

    /** Sends to one user's browsers (the test button). Returns how many accepted it. */
    public static function sendToAdmin(int $adminId, string $title, string $body, string $url): int
    {
        return self::sendMany(Db::all('SELECT * FROM push_subscriptions WHERE admin_id = :a', ['a' => $adminId]), $title, $body, $url);
    }

    /** @param iterable<array<string, mixed>> $subs */
    private static function sendMany(iterable $subs, string $title, string $body, string $url): int
    {
        $payload = Db::json(['title' => $title, 'body' => mb_substr($body, 0, 1000), 'url' => $url, 'tag' => 'kiosky-shepherd']);
        $ok = 0;
        $errors = [];
        foreach ($subs as $s) {
            try {
                $status = self::send($s, $payload);
                if ($status === 404 || $status === 410) {
                    // The browser unsubscribed or the subscription expired
                    Db::run('DELETE FROM push_subscriptions WHERE id = :id', ['id' => (int) $s['id']]);
                } elseif ($status >= 200 && $status < 300) {
                    Db::run('UPDATE push_subscriptions SET last_ok_at = NOW() WHERE id = :id', ['id' => (int) $s['id']]);
                    $ok++;
                } else {
                    $errors[] = "HTTP $status";
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($ok === 0 && $errors !== []) {
            throw new \RuntimeException('Push failed: ' . implode('; ', array_unique($errors)));
        }
        return $ok;
    }

    // ---- Encryption and signing -------------------------------------------------------

    /** Encrypts and POSTs one message (RFC 8291 aes128gcm). Returns the push service's status. */
    private static function send(array $sub, string $payload): int
    {
        $uaPublic = self::unb64((string) $sub['p256dh']);
        $authSecret = self::unb64((string) $sub['auth']);

        $local = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($local === false) {
            throw new \RuntimeException('openssl couldn\'t create a key.');
        }
        $asPublic = self::rawPoint($local);
        $peer = openssl_pkey_get_public(self::pemFromPoint($uaPublic));
        $shared = $peer === false ? false : openssl_pkey_derive($peer, $local, 32);
        if ($shared === false) {
            throw new \RuntimeException('The browser\'s push key is invalid.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encrypting the push message failed.');
        }
        $body = $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;

        $endpoint = (string) $sub['endpoint'];
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $keys = self::keys();
        $jwt = self::vapidJwt($audience, $keys['private_pem']);
        $r = HttpClient::request('POST', $endpoint, [
            'Content-Type' => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'TTL' => '86400',
            'Urgency' => 'high',
            'Authorization' => 'vapid t=' . $jwt . ', k=' . $keys['public'],
        ], $body);
        return $r['status'];
    }

    private static function vapidJwt(string $audience, string $privatePem): string
    {
        $from = Mailer::from();
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600,
            'sub' => filter_var($from, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $from : Util::baseUrl()], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign("$header.$claims", $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing the push request failed.');
        }
        return "$header.$claims." . self::b64(self::derToRaw($der));
    }

    /** An ECDSA DER signature (SEQUENCE of two INTEGERs) → the 64-byte r||s JWT wants. */
    private static function derToRaw(string $der): string
    {
        $pos = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7F) : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    /** @param \OpenSSLAsymmetricKey $key */
    private static function rawPoint($key): string
    {
        $d = openssl_pkey_get_details($key);
        if (!is_array($d) || !isset($d['ec']['x'], $d['ec']['y'])) {
            throw new \RuntimeException('Not an EC key.');
        }
        return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function pemFromPoint(string $point): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
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
