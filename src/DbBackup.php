<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Database backups as gzipped SQL (storage/backups/db-<date>.sql.gz), written with
 * PDO alone so they work on hosts without SSH or mysqldump. Restore by importing the
 * file in phpMyAdmin, or with `gunzip -c file | mysql dbname`.
 *
 * Backups contain password hashes, TOTP secrets and API token hashes: they are kept in
 * storage/ (not web-accessible) and only admins can download them.
 */
final class DbBackup
{
    private const PREFIX = 'db-';
    /** Transient tables: structure only */
    private const NO_DATA = ['rate_limits', 'sso_logins', 'event_outbox'];
    private const LAST = 'db-backup-last.json';

    private static function dir(): string
    {
        $dir = Config::storage('backups');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        return $dir;
    }

    /** Writes a new backup. Returns its file name. */
    public static function create(): string
    {
        if (!function_exists('gzopen')) {
            throw new \RuntimeException('PHP\'s zlib extension is needed for database backups.');
        }
        @set_time_limit(600);
        $name = self::PREFIX . gmdate('Ymd-His') . '.sql.gz';
        $path = self::dir() . '/' . $name;
        $tmp = $path . '.part';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Cannot write $tmp");
        }
        $pdo = Db::pdo();
        try {
            $w = static function (string $s) use ($gz): void {
                if (gzwrite($gz, $s) === false) {
                    throw new \RuntimeException('Writing the backup failed (is the disk full?).');
                }
            };
            $w("-- Kiosky Shepherd " . SHEPHERD_VERSION . " database backup, " . gmdate('Y-m-d H:i:s') . " UTC\n"
                . "-- Restore: import in phpMyAdmin, or gunzip -c this-file | mysql <database>\n\n"
                . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
            $tables = Db::all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            foreach ($tables as $t) {
                $table = (string) array_values($t)[0];
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    continue;
                }
                $create = Db::one("SHOW CREATE TABLE `$table`");
                $w("DROP TABLE IF EXISTS `$table`;\n" . array_values($create ?? [])[1] . ";\n\n");
                if (in_array($table, self::NO_DATA, true)) {
                    continue;
                }
                $offset = 0;
                while (true) {
                    $rows = $pdo->query("SELECT * FROM `$table` LIMIT 500 OFFSET $offset")->fetchAll(\PDO::FETCH_ASSOC);
                    if ($rows === []) {
                        break;
                    }
                    $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                    $values = [];
                    foreach ($rows as $r) {
                        $values[] = '(' . implode(', ', array_map(static fn ($v) => match (true) {
                            $v === null => 'NULL',
                            is_int($v), is_float($v) => (string) $v,
                            default => $pdo->quote((string) $v),
                        }, $r)) . ')';
                    }
                    $w("INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $values) . ";\n");
                    $offset += 500;
                }
                $w("\n");
            }
            $w("SET FOREIGN_KEY_CHECKS = 1;\n");
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($tmp);
            throw $e;
        }
        gzclose($gz);
        if (!rename($tmp, $path)) {
            throw new \RuntimeException("Cannot finish $path");
        }
        @chmod($path, 0600);
        @file_put_contents(Config::storage(self::LAST), json_encode(['at' => gmdate('c'), 'name' => $name]), LOCK_EX);
        return $name;
    }

    /** @return list<array{name: string, size: int, created_at: string}> newest first */
    public static function list(): array
    {
        $out = [];
        foreach (glob(Config::storage('backups') . '/' . self::PREFIX . '*.sql.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'created_at' => gmdate('c', (int) filemtime($f))];
        }
        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Full path of a backup, or null for a name that isn't one. */
    public static function path(string $name): ?string
    {
        if (!preg_match('/^' . self::PREFIX . '\d{8}-\d{6}\.sql\.gz$/', $name)) {
            return null;
        }
        $path = Config::storage('backups/' . $name);
        return is_file($path) ? $path : null;
    }

    public static function delete(string $name): bool
    {
        $path = self::path($name);
        return $path !== null && @unlink($path);
    }

    public static function keep(): int
    {
        return max(1, min(60, (int) Config::get('backup.keep', 7)));
    }

    public static function prune(): int
    {
        $n = 0;
        foreach (array_slice(self::list(), self::keep()) as $b) {
            $n += (int) self::delete($b['name']);
        }
        return $n;
    }

    /**
     * For bin/cron.php: one backup a day, after 03:00 in the server's time zone, when
     * 'backup.nightly' is on.
     */
    public static function cron(): ?string
    {
        if (!Config::get('backup.nightly', false)) {
            return null;
        }
        $zone = new \DateTimeZone(Health::timezone());
        $now = new \DateTimeImmutable('now', $zone);
        $last = json_decode((string) @file_get_contents(Config::storage(self::LAST)), true);
        $lastDay = is_array($last) && is_string($last['at'] ?? null)
            ? (new \DateTimeImmutable($last['at']))->setTimezone($zone)->format('Y-m-d') : null;
        if ((int) $now->format('G') < 3 || $lastDay === $now->format('Y-m-d')) {
            return null;
        }
        $name = self::create();
        $pruned = self::prune();
        return "database backed up to $name" . ($pruned ? ", removed $pruned old" : '');
    }
}
