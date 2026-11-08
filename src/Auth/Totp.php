<?php

declare(strict_types=1);

namespace Shepherd\Auth;

use Shepherd\Util;

/**
 * Time-based one-time passwords (RFC 6238: SHA-1, 6 digits, 30-second steps), the
 * kind every authenticator app understands, plus single-use recovery codes.
 */
final class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const STEP = 30;
    public const RECOVERY_CODES = 10;

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=\-]/', '', $s) ?? '');
        $bits = '';
        foreach (str_split($s) as $c) {
            $i = strpos(self::BASE32, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Not base32.');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /** The 6-digit code for a time step. */
    public static function code(string $secret, int $step): string
    {
        $hmac = hash_hmac('sha1', pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF), self::base32Decode($secret), true);
        $o = ord($hmac[19]) & 0x0F;
        $n = ((ord($hmac[$o]) & 0x7F) << 24) | (ord($hmac[$o + 1]) << 16) | (ord($hmac[$o + 2]) << 8) | ord($hmac[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $now = null): int
    {
        return intdiv($now ?? time(), self::STEP);
    }

    /**
     * Checks a code against the current step and one step either side (clock drift).
     * Steps at or before $lastStep are refused, so a code can't be replayed.
     *
     * @return int|null the matching step, or null
     */
    public static function verify(string $secret, string $code, ?int $lastStep, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $t = self::currentStep($now);
        foreach ([0, -1, 1] as $d) {
            $step = $t + $d;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** otpauth:// URI for the QR code that authenticator apps scan. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?'
            . http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => 6,
                'period' => self::STEP,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return list<string> readable codes such as 7KQ2M-X9D4F */
    public static function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $c = Util::code(10);
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return $codes;
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', Util::normaliseCode($code));
    }

    /** True when $input looks like a recovery code rather than a 6-digit TOTP code. */
    public static function looksLikeRecoveryCode(string $input): bool
    {
        return strlen(Util::normaliseCode($input)) === 10;
    }
}
