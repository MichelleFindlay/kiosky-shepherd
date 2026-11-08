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
        }
        return $done;
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
