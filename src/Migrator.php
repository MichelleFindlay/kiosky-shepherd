<?php

declare(strict_types=1);

namespace Shepherd;

/** Applies sql/migrations/*.sql in order, once each. Used by bin/migrate.php and web setup. */
final class Migrator
{
    public static function tableExists(string $table): bool
    {
        return Db::value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $table]
        ) > 0;
    }

    /** @var array<string, bool> per-request cache for has() */
    private static array $has = [];

    /**
     * Cached tableExists(), for code that must keep working before a migration
     * that adds the table has been applied (the console's "Update database" banner).
     */
    public static function has(string $table): bool
    {
        return self::$has[$table] ??= self::tableExists($table);
    }

    /** @var array<string, bool> per-request cache for hasColumn() */
    private static array $hasColumn = [];

    /** Like has(), for a column a migration adds to an existing table. */
    public static function hasColumn(string $table, string $column): bool
    {
        return self::$hasColumn["$table.$column"] ??= Db::value(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
            ['t' => $table, 'c' => $column]
        ) > 0;
    }

    /** Throws a clear 409 when a feature's table hasn't been created yet. */
    public static function requireTable(string $table): void
    {
        if (!self::has($table)) {
            throw Http\HttpError::conflict('This needs the latest database update. An admin can apply it with the Update database button.');
        }
    }

    /** @return list<string> migration file names not yet applied */
    public static function pending(): array
    {
        $applied = self::tableExists('schema_migrations')
            ? array_column(Db::all('SELECT name FROM schema_migrations'), 'name')
            : [];
        return array_values(array_filter(
            array_map('basename', self::files()),
            static fn (string $name) => !in_array($name, $applied, true)
        ));
    }

    /**
     * Runs every pending migration.
     *
     * @param callable(string): void|null $progress called with each file name before it runs
     * @return list<string> the migrations applied
     */
    public static function run(?callable $progress = null): array
    {
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(191) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $done = [];
        foreach (self::pending() as $name) {
            if ($progress !== null) {
                $progress($name);
            }
            // MySQL commits DDL implicitly, so migrations can't be wrapped in a transaction
            foreach (self::statements((string) file_get_contents(self::dir() . '/' . $name)) as $sql) {
                $pdo->exec($sql);
            }
            Db::run('INSERT IGNORE INTO schema_migrations (name) VALUES (:n)', ['n' => $name]);
            $done[] = $name;
            self::$has = [];
            self::$hasColumn = [];
        }
        self::writeMarker(self::APPLIED_MARKER);
        @unlink(Config::storage(self::FAILED_MARKER));
        return $done;
    }

    // ---- Automatic updates ---------------------------------------------------

    /** Holds the signature of the migration files once all of them are applied. */
    private const APPLIED_MARKER = 'schema-applied.txt';
    /** Holds the signature when an automatic update failed, so it isn't retried on every request. */
    private const FAILED_MARKER = 'schema-auto-failed.txt';
    private const LOCK = 'kiosky_shepherd_migrate';

    /** Changes whenever a migration file is added or renamed. */
    private static function signature(): string
    {
        return hash('sha256', implode("\n", array_map('basename', self::files())));
    }

    private static function writeMarker(string $file): void
    {
        @file_put_contents(Config::storage($file), self::signature(), LOCK_EX);
    }

    private static function markerMatches(string $file): bool
    {
        return @file_get_contents(Config::storage($file)) === self::signature();
    }

    /**
     * Applies pending migrations on the first request after an upgrade (config
     * 'auto_migrate', on by default), so nobody has to click "Update database".
     * Normal requests only compare a marker file in storage/ and don't touch the
     * database. A MySQL lock lets one request run the update while others carry on;
     * a failed update is logged and not retried until the migration files change,
     * and the console's Update database button stays available.
     */
    public static function autoRun(): void
    {
        if (!Config::get('auto_migrate', true) || self::markerMatches(self::APPLIED_MARKER)
            || self::markerMatches(self::FAILED_MARKER)) {
            return;
        }
        try {
            // A brand-new database is installed by the first-run setup, not here
            if (!self::tableExists('schema_migrations')) {
                return;
            }
            if (self::pending() === []) {
                self::writeMarker(self::APPLIED_MARKER);
                return;
            }
            if ((int) Db::value('SELECT GET_LOCK(:l, 0)', ['l' => self::LOCK]) !== 1) {
                return; // another request is applying it right now
            }
        } catch (\Throwable $e) {
            error_log('[kiosky-shepherd] auto-migrate check failed: ' . $e->getMessage());
            return;
        }
        try {
            $done = self::run();
            if ($done !== []) {
                Audit::log(null, 'server.migrated', null, null, ['applied' => $done, 'automatic' => true]);
            }
        } catch (\Throwable $e) {
            error_log('[kiosky-shepherd] automatic database update failed: ' . $e->getMessage()
                . ' (not retried automatically; use the console\'s Update database button or php bin/migrate.php)');
            self::writeMarker(self::FAILED_MARKER);
        } finally {
            try {
                Db::value('SELECT RELEASE_LOCK(:l)', ['l' => self::LOCK]);
            } catch (\Throwable) {
                // the lock is released when the connection closes anyway
            }
        }
    }

    /** Splits a migration into statements: strips whole-line comments, splits on ; at line ends. */
    private static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*$/m', $sql) ?: []),
            static fn (string $s) => $s !== ''
        ));
    }

    private static function dir(): string
    {
        return SHEPHERD_ROOT . '/sql/migrations';
    }

    /** @return list<string> */
    private static function files(): array
    {
        $files = glob(self::dir() . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }
}
