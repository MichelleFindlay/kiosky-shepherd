<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Reads package, versionCode and versionName from an APK's binary
 * AndroidManifest.xml. Needs the zip extension; without it, manifest()
 * returns null and the uploader's own version fields are trusted.
 */
final class Apk
{
    private const RES_XML = 0x0003;
    private const RES_STRING_POOL = 0x0001;
    private const RES_XML_RESOURCE_MAP = 0x0180;
    private const RES_XML_START_ELEMENT = 0x0102;
    private const ATTR_VERSION_CODE = 0x0101021b;
    private const ATTR_VERSION_NAME = 0x0101021c;
    private const TYPE_STRING = 0x03;

    /** @return array{package: ?string, version_code: ?int, version_name: ?string}|null */
    public static function manifest(string $path): ?array
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('The file is not a valid APK (zip) archive.');
        }
        $xml = $zip->getFromName('AndroidManifest.xml');
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('The APK has no AndroidManifest.xml.');
        }
        return self::parse($xml);
    }

    /** @return array{package: ?string, version_code: ?int, version_name: ?string} */
    public static function parse(string $b): array
    {
        $result = ['package' => null, 'version_code' => null, 'version_name' => null];
        $len = strlen($b);
        if ($len < 8 || self::u16($b, 0) !== self::RES_XML) {
            throw new \RuntimeException('AndroidManifest.xml is not in binary XML format.');
        }
        $strings = [];
        $resIds = [];
        $off = self::u16($b, 2);

        while ($off + 8 <= $len) {
            $type = self::u16($b, $off);
            $headerSize = self::u16($b, $off + 2);
            $size = self::u32($b, $off + 4);
            if ($size < 8 || $off + $size > $len) {
                break;
            }
            if ($type === self::RES_STRING_POOL) {
                $strings = self::stringPool($b, $off);
            } elseif ($type === self::RES_XML_RESOURCE_MAP) {
                $count = intdiv($size - $headerSize, 4);
                for ($i = 0; $i < $count; $i++) {
                    $resIds[$i] = self::u32($b, $off + $headerSize + 4 * $i);
                }
            } elseif ($type === self::RES_XML_START_ELEMENT) {
                $ext = $off + $headerSize;
                if (($strings[self::u32($b, $ext + 4)] ?? '') === 'manifest') {
                    $attrStart = self::u16($b, $ext + 8);
                    $attrSize = self::u16($b, $ext + 10);
                    $attrCount = self::u16($b, $ext + 12);
                    for ($i = 0; $i < $attrCount; $i++) {
                        $a = $ext + $attrStart + $i * $attrSize;
                        if ($a + 20 > $len) {
                            break;
                        }
                        $nameIdx = self::u32($b, $a + 4);
                        $raw = self::u32($b, $a + 8);
                        $dataType = ord($b[$a + 15]);
                        $data = self::u32($b, $a + 16);
                        $name = $strings[$nameIdx] ?? '';
                        $resId = $resIds[$nameIdx] ?? 0;
                        $str = $raw !== 0xFFFFFFFF ? ($strings[$raw] ?? null)
                            : ($dataType === self::TYPE_STRING ? ($strings[$data] ?? null) : null);

                        if ($resId === self::ATTR_VERSION_CODE || $name === 'versionCode') {
                            $result['version_code'] = $str !== null && ctype_digit($str) ? (int) $str : $data;
                        } elseif ($resId === self::ATTR_VERSION_NAME || $name === 'versionName') {
                            $result['version_name'] = $str;
                        } elseif ($name === 'package') {
                            $result['package'] = $str;
                        }
                    }
                    return $result;
                }
            }
            $off += $size;
        }
        return $result;
    }

    /** @return array<int, string> */
    private static function stringPool(string $b, int $off): array
    {
        $headerSize = self::u16($b, $off + 2);
        $count = min(self::u32($b, $off + 8), 200000);
        $flags = self::u32($b, $off + 16);
        $stringsStart = self::u32($b, $off + 20);
        $utf8 = ($flags & 0x100) !== 0;
        $len = strlen($b);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $p = $off + $stringsStart + self::u32($b, $off + $headerSize + 4 * $i);
            if ($p >= $len) {
                $out[$i] = '';
                continue;
            }
            if ($utf8) {
                $p += (ord($b[$p]) & 0x80) ? 2 : 1;              // length in characters (unused)
                $n = ord($b[$p]);
                if ($n & 0x80) {
                    $n = (($n & 0x7F) << 8) | ord($b[$p + 1]);
                    $p += 2;
                } else {
                    $p += 1;
                }
                $out[$i] = substr($b, $p, $n);
            } else {
                $n = self::u16($b, $p);
                $p += 2;
                if ($n & 0x8000) {
                    $n = (($n & 0x7FFF) << 16) | self::u16($b, $p);
                    $p += 2;
                }
                $out[$i] = mb_convert_encoding(substr($b, $p, $n * 2), 'UTF-8', 'UTF-16LE');
            }
        }
        return $out;
    }

    private static function u16(string $b, int $o): int
    {
        return $o + 2 <= strlen($b) ? unpack('v', $b, $o)[1] : 0;
    }

    private static function u32(string $b, int $o): int
    {
        return $o + 4 <= strlen($b) ? unpack('V', $b, $o)[1] : 0;
    }
}
