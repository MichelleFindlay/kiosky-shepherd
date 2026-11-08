<?php

declare(strict_types=1);

namespace Shepherd\Auth;

/**
 * A small CBOR (RFC 8949) decoder, enough for WebAuthn: integers, byte and text
 * strings, arrays, maps, booleans and null. Indefinite lengths, tags and floats
 * aren't used by authenticators and are refused.
 */
final class Cbor
{
    /** Decodes the first item; $consumed is set to how many bytes it took. */
    public static function decode(string $data, ?int &$consumed = null): mixed
    {
        $pos = 0;
        $value = self::item($data, $pos, 0);
        $consumed = $pos;
        return $value;
    }

    private static function item(string $d, int &$pos, int $depth): mixed
    {
        if ($depth > 16 || $pos >= strlen($d)) {
            throw new \UnexpectedValueException('Malformed CBOR.');
        }
        $byte = ord($d[$pos++]);
        $major = $byte >> 5;
        $info = $byte & 0x1F;
        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new \UnexpectedValueException('Unsupported CBOR simple value.'),
            };
        }
        $n = self::length($d, $pos, $info);
        switch ($major) {
            case 0:
                return $n;
            case 1:
                return -1 - $n;
            case 2:
            case 3:
                if ($pos + $n > strlen($d)) {
                    throw new \UnexpectedValueException('Truncated CBOR.');
                }
                $s = substr($d, $pos, $n);
                $pos += $n;
                return $s;
            case 4:
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = self::item($d, $pos, $depth + 1);
                }
                return $out;
            case 5:
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $k = self::item($d, $pos, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new \UnexpectedValueException('Unsupported CBOR map key.');
                    }
                    $out[$k] = self::item($d, $pos, $depth + 1);
                }
                return $out;
        }
        throw new \UnexpectedValueException('Unsupported CBOR type.');
    }

    private static function length(string $d, int &$pos, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new \UnexpectedValueException('Unsupported CBOR length.'),
        };
        if ($pos + $bytes > strlen($d)) {
            throw new \UnexpectedValueException('Truncated CBOR.');
        }
        $n = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $n = ($n << 8) | ord($d[$pos++]);
        }
        if ($n < 0 || $n > 16 * 1024 * 1024) {
            throw new \UnexpectedValueException('CBOR length out of range.');
        }
        return $n;
    }
}
