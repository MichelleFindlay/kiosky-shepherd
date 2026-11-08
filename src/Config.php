<?php

declare(strict_types=1);

namespace Shepherd;

final class Config
{
    private const DEFAULTS = [
        'db' => [
            'dsn' => 'mysql:host=127.0.0.1;dbname=shepherd;charset=utf8mb4',
            'user' => 'shepherd',
            'password' => '',
        ],
        'base_url' => '',
        'storage_dir' => null,
        'trusted_proxies' => [],
        'client_ip_header' => 'auto',
        'session_name' => 'shepherd_sid',
        'session_lifetime' => 43200,
        'cookie_secure' => true,
        'apk_package' => 'uk.lp0.ha',
        'max_apk_mb' => 150,
        'max_screenshot_mb' => 4,
        'command_ttl_hours' => 24,
        'command_redeliver_seconds' => 300,
        'retention_days' => 90,
        'pbkdf2_iterations' => 120000,
        'setup_key' => '',
        'password_login' => true,
        'auto_migrate' => true,
        'sso' => [],
        'timezone' => 'UTC',
        'fast_checkin_seconds' => 15,
        // Lists inside 'alerts' get their defaults in Alerts, because
        // array_replace_recursive() can't replace a default list with an empty one
        'alerts' => [],
        'self_update' => [],
    ];

    /** @var array<string, mixed> */
    private static array $values = self::DEFAULTS;

    /** @var array<string, mixed> config.php merged with the defaults, before console settings */
    private static array $fileValues = self::DEFAULTS;

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Missing config file: $file (copy config/config.example.php)");
        }
        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new \RuntimeException("$file must return an array");
        }
        self::$values = array_replace_recursive(self::DEFAULTS, $loaded);
        if (empty(self::$values['storage_dir'])) {
            self::$values['storage_dir'] = SHEPHERD_ROOT . '/storage';
        }
        self::$fileValues = self::$values;
        // Settings changed in the console (Server → Settings) beat config.php
        Settings::applyStored();
    }

    /** A value as config.php (or the built-in default) sets it, ignoring console settings. */
    public static function fileValue(string $path, mixed $default = null): mixed
    {
        return self::lookup(self::$fileValues, $path, $default);
    }

    /** Replaces one value for the rest of the request (used by Settings). */
    public static function set(string $path, mixed $value): void
    {
        $ref = &self::$values;
        foreach (explode('.', $path) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
        unset($ref);
    }

    private static function lookup(array $node, string $path, mixed $default): mixed
    {
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** Reads a value by dotted path, e.g. Config::get('db.dsn'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        return self::lookup(self::$values, $path, $default);
    }

    public static function storage(string $sub = ''): string
    {
        $dir = rtrim((string) self::get('storage_dir'), '/');
        return $sub === '' ? $dir : $dir . '/' . ltrim($sub, '/');
    }
}
