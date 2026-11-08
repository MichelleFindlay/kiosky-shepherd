<?php

declare(strict_types=1);

// Applies sql/migrations/*.sql in order, once each.
// Usage: php bin/migrate.php [--status]

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Shepherd\Migrator;

if (in_array('--status', $argv, true)) {
    $pending = Migrator::pending();
    echo $pending === [] ? "Database is up to date.\n" : "Pending:\n  " . implode("\n  ", $pending) . "\n";
    exit(0);
}

$done = Migrator::run(static function (string $name): void {
    echo "Applying $name\n";
});
echo $done === [] ? "Database is up to date.\n" : 'Applied ' . count($done) . " migration(s).\n";
