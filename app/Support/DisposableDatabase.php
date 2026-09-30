<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Only anonymous memory databases or explicitly named temporary test files. */
final class DisposableDatabase
{
    public static function allowsDestructiveCommands(?string $connection = null): bool
    {
        if (! app()->environment('testing')) {
            return false;
        }

        // Resolves database URLs through Laravel without opening a PDO connection.
        $config = DB::connection($connection)->getConfig();
        if (($config['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $database = $config['database'] ?? null;
        if ($database === ':memory:') {
            return true;
        }
        if (! is_string($database) || str_starts_with($database, 'file:')) {
            return false;
        }

        // Match SQLiteConnector's relative-path resolution; realpath also
        // rejects symlinks pointing outside the disposable directory.
        $path = realpath($database) ?: realpath(base_path($database));
        $development = realpath(database_path('database.sqlite'));
        if ($path === false || $path === $development) {
            return false;
        }

        // A hard link to the real DB is no more disposable than a symlink.
        $targetStat = stat($path);
        $devStat = $development === false ? false : stat($development);
        if ($targetStat !== false && $devStat !== false
            && $targetStat['dev'] === $devStat['dev'] && $targetStat['ino'] === $devStat['ino']) {
            return false;
        }

        return dirname($path) === realpath(sys_get_temp_dir())
            && str_starts_with(basename($path), 'career_toolkit_test_');
    }
}
