<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Database\ConnectionFactory;
use AsterMD\Storefront\Database\Migrator;

/**
 * Builds a throwaway migrated SQLite database for tests that need real
 * persistence rather than a fake.
 *
 * Each call gets its own file under the system temp directory, so tests
 * never share state and never touch the repository's own database. The
 * migrator is pointed at the repository's real migrations directory rather
 * than at a copy, so a schema change is picked up here automatically instead
 * of silently going uncovered.
 *
 * Temporary directories are tracked and cleaned up after each test to avoid
 * accumulating scratch SQLite files in the system temp directory.
 */
trait TempDatabase
{
    /** @var list<string> */
    private array $tempDatabaseDirs = [];

    protected function tempPdo(): \PDO
    {
        $dir = sys_get_temp_dir() . '/storefront-test-' . bin2hex(random_bytes(6));
        mkdir($dir . '/storage/database', 0775, true);
        $this->registerTempDirForCleanup($dir);

        $factory = new ConnectionFactory(
            ['driver' => 'sqlite', 'database' => 'storage/database/test.sqlite'],
            $dir,
        );
        $pdo = $factory->create();
        (new Migrator($pdo, $factory->driver(), dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        return $pdo;
    }

    protected function registerTempDirForCleanup(string $dir): void
    {
        $this->tempDatabaseDirs[] = $dir;
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function removeTempDatabases(): void
    {
        foreach ($this->tempDatabaseDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $this->removeDirectoryRecursively($dir);
        }

        $this->tempDatabaseDirs = [];
    }

    private function removeDirectoryRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $path . '/' . $entry;
            if (is_dir($fullPath)) {
                $this->removeDirectoryRecursively($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
