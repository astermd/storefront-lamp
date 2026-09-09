<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Database;

/**
 * Applies pending migration files exactly once each, tracked via a
 * `migrations` bookkeeping table keyed by filename (not by file contents or
 * a hash), created on demand from whichever driver is active. A migration
 * file is a plain PHP file returning a `function(PDO $pdo, string $driver)`
 * closure — the driver is passed through so a migration can special-case
 * sqlite/mysql/pgsql DDL differences itself (see database/migrations for an
 * example). Migrations run in filename sort order and are recorded
 * immediately after they run, so a failure partway through a batch leaves
 * everything before it marked applied and everything from that point on
 * untouched — re-running `migrate()` resumes rather than re-applying.
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $driver,
        private readonly string $migrationsDir,
    ) {
    }

    /** @return list<string> names newly applied, in order */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->applied();
        $newlyApplied = [];

        foreach ($this->discover() as $name => $file) {
            if (in_array($name, $applied, true)) {
                continue;
            }
            $migration = require $file;
            $migration($this->pdo, $this->driver);
            $stmt = $this->pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, ?)');
            $stmt->execute([$name, gmdate('Y-m-d H:i:s')]);
            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureMigrationsTable();

        return $this->pdo->query('SELECT name FROM migrations ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /** @return array<string, string> name => path, sorted by filename */
    private function discover(): array
    {
        $files = glob($this->migrationsDir . '/*.php') ?: [];
        sort($files);
        $map = [];
        foreach ($files as $file) {
            $map[basename($file, '.php')] = $file;
        }

        return $map;
    }

    private function ensureMigrationsTable(): void
    {
        $id = ConnectionFactory::autoIncrementPrimaryKey($this->driver);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS migrations ({$id}, name VARCHAR(191) NOT NULL UNIQUE, applied_at VARCHAR(32) NOT NULL)");
    }
}
