<?php

declare(strict_types=1);

namespace Shepherd;

final class Util
{
    // Crockford-style alphabet without I, L, O, U so codes read back cleanly
    private const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** URL-safe random token with $bytes of entropy. */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Random code from an unambiguous alphabet, e.g. for enrollment codes and device ids. */
    public static function code(int $length, bool $lower = false): string
    {
        $out = '';
        $max = strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        return $lower ? strtolower($out) : $out;
    }

    /** Normalises a typed enrollment code: upper-case, no separators, confusable letters mapped. */
    public static function normaliseCode(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s\-]/', '', $code) ?? '');
        return strtr($code, ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V']);
    }

    /** Formats a code in groups of four for display: ABCD-EFGH-JKMN. */
    public static function formatCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** DATETIME column value → ISO 8601 UTC string for API output. */
    public static function iso(?string $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        return str_replace(' ', 'T', $dt) . 'Z';
    }

    public static function baseUrl(): string
    {
        $configured = rtrim((string) Config::get('base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return 'https://' . $host;
    }

    /**
     * Runs $fn after the response has been sent (PHP-FPM), or at the end of the request
     * elsewhere. For slow work a visitor shouldn't wait for: emails, webhooks.
     */
    public static function afterResponse(callable $fn): void
    {
        if (PHP_SAPI === 'cli') {
            $fn();
            return;
        }
        register_shutdown_function(static function () use ($fn): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                $fn();
            } catch (\Throwable $e) {
                error_log('[kiosky-shepherd] after response: ' . $e->getMessage());
            }
        });
    }

    /** Truncates a string to $max characters (multibyte-safe). */
    public static function clip(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }
        $s = (string) $value;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }
}
