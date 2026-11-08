<?php

declare(strict_types=1);

namespace Shepherd\Auth;

/**
 * Verifies signed JWTs (OpenID Connect ID tokens) against a JSON Web Key Set using
 * ext-openssl only. Supports RS256/384/512 and ES256/384/512; "none" and HMAC
 * algorithms are always refused.
 */
final class Jwt
{
    private const ALGS = [
        'RS256' => ['RSA', OPENSSL_ALGO_SHA256, 0],
        'RS384' => ['RSA', OPENSSL_ALGO_SHA384, 0],
        'RS512' => ['RSA', OPENSSL_ALGO_SHA512, 0],
        'ES256' => ['EC', OPENSSL_ALGO_SHA256, 32],
        'ES384' => ['EC', OPENSSL_ALGO_SHA384, 48],
        'ES512' => ['EC', OPENSSL_ALGO_SHA512, 66],
    ];

    // DER-encoded OIDs
    private const OID_RSA = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
    private const OID_EC = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
    private const CURVES = [
        'P-256' => "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07",
        'P-384' => "\x06\x05\x2b\x81\x04\x00\x22",
        'P-521' => "\x06\x05\x2b\x81\x04\x00\x23",
    ];

    /**
     * Splits a JWT without verifying it.
     *
     * @return array{header: array, payload: array, signed: string, signature: string}
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('The ID token is not a signed JWT.');
        }
        $header = json_decode(self::b64($parts[0]), true);
        $payload = json_decode(self::b64($parts[1]), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new \RuntimeException('The ID token could not be decoded.');
        }
        return [
            'header' => $header,
            'payload' => $payload,
            'signed' => $parts[0] . '.' . $parts[1],
            'signature' => self::b64($parts[2]),
        ];
    }

    /** True when the token was signed by one of the keys in $jwks. */
    public static function verify(array $decoded, array $jwks): bool
    {
        $alg = (string) ($decoded['header']['alg'] ?? '');
        if (!isset(self::ALGS[$alg])) {
            throw new \RuntimeException("The ID token uses an unsupported algorithm ($alg).");
        }
        [$kty, $hash, $partLen] = self::ALGS[$alg];
        $kid = $decoded['header']['kid'] ?? null;

        $signature = $decoded['signature'];
        if ($kty === 'EC') {
            if (strlen($signature) !== 2 * $partLen) {
                return false;
            }
            $signature = self::seq(self::int(substr($signature, 0, $partLen)) . self::int(substr($signature, $partLen)));
        }

        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (!is_array($jwk) || ($jwk['kty'] ?? '') !== $kty) {
                continue;
            }
            if ($kid !== null && isset($jwk['kid']) && $jwk['kid'] !== $kid) {
                continue;
            }
            if (isset($jwk['alg']) && $jwk['alg'] !== $alg) {
                continue;
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }
            $pem = self::pem($jwk);
            if ($pem === null) {
                continue;
            }
            $key = openssl_pkey_get_public($pem);
            if ($key !== false && openssl_verify($decoded['signed'], $signature, $key, $hash) === 1) {
                return true;
            }
        }
        return false;
    }

    /** True when the set has a key with this kid (or when the token names no kid). */
    public static function hasKey(array $jwks, ?string $kid): bool
    {
        if ($kid === null) {
            return true;
        }
        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                return true;
            }
        }
        return false;
    }

    /** JWK → PEM public key (SubjectPublicKeyInfo), or null if the key can't be used. */
    private static function pem(array $jwk): ?string
    {
        try {
            if ($jwk['kty'] === 'RSA' && isset($jwk['n'], $jwk['e'])) {
                $rsa = self::seq(self::int(self::b64($jwk['n'])) . self::int(self::b64($jwk['e'])));
                $spki = self::seq(self::seq(self::OID_RSA . "\x05\x00") . self::bits($rsa));
            } elseif ($jwk['kty'] === 'EC' && isset($jwk['crv'], $jwk['x'], $jwk['y'], self::CURVES[$jwk['crv']])) {
                $point = "\x04" . self::b64($jwk['x']) . self::b64($jwk['y']);
                $spki = self::seq(self::seq(self::OID_EC . self::CURVES[$jwk['crv']]) . self::bits($point));
            } elseif (isset($jwk['x5c'][0]) && is_string($jwk['x5c'][0])) {
                return "-----BEGIN CERTIFICATE-----\n" . chunk_split($jwk['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
            } else {
                return null;
            }
        } catch (\RuntimeException) {
            return null;
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function b64(mixed $s): string
    {
        if (!is_string($s)) {
            throw new \RuntimeException('Expected base64url text.');
        }
        $out = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($out === false) {
            throw new \RuntimeException('Invalid base64url.');
        }
        return $out;
    }

    // ---- DER helpers ---------------------------------------------------------

    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $bytes = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function seq(string $content): string
    {
        return "\x30" . self::len(strlen($content)) . $content;
    }

    /** Unsigned big-endian integer. */
    private static function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::len(strlen($bytes)) . $bytes;
    }

    private static function bits(string $content): string
    {
        return "\x03" . self::len(strlen($content) + 1) . "\x00" . $content;
    }
}
