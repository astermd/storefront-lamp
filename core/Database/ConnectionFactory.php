<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Database;

/**
 * One place where database engines differ. Everything above this class speaks
 * portable SQL through PDO. Supported drivers: sqlite (default), mysql, pgsql
 * (Supabase is pgsql). Document engines outside the SQL family are unsupported.
 */
final class ConnectionFactory
{
    private const SUPPORTED = ['sqlite', 'mysql', 'pgsql'];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config, private readonly string $rootDir)
    {
        if (!in_array($this->driver(), self::SUPPORTED, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported database driver "%s". Supported: %s.',
                $this->driver(),
                implode(', ', self::SUPPORTED),
            ));
        }
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'sqlite');
    }

    public function dsn(): string
    {
        return match ($this->driver()) {
            'sqlite' => 'sqlite:' . $this->sqlitePath(),
            'mysql' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->config['host'] ?? '127.0.0.1', (int) ($this->config['port'] ?? 3306), (string) ($this->config['database'] ?? '')),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->config['host'] ?? '127.0.0.1', (int) ($this->config['port'] ?? 5432), (string) ($this->config['database'] ?? '')),
        };
    }

    /**
     * The configured database as SQLite should receive it.
     *
     * A relative path is rooted at the application directory so a deployment
     * can name `storage/database/app.sqlite` without knowing where it is
     * installed. Three spellings are *not* paths and must survive untouched:
     * an absolute path, `:memory:`, and a `file:` URI.
     *
     * The two non-path forms matter more than they look. Rooting `:memory:`
     * produces `sqlite:<root>/:memory:`, and SQLite creates that as a real
     * file — one page, 4 KB, named `:memory:` in the application root. An
     * operator who reaches for SQLite's own name for an ephemeral database
     * then believes they are running against a throwaway while quietly
     * accumulating a persistent one, and the data they expected to vanish
     * outlives the process. Rooting a `file:` URI corrupts its query options
     * as well as its location.
     */
    private function sqlitePath(): string
    {
        $database = (string) ($this->config['database'] ?? '');

        $isPath = !str_starts_with($database, '/')
            && $database !== ':memory:'
            && !str_starts_with($database, 'file:');

        return $isPath ? $this->rootDir . '/' . $database : $database;
    }

    public function create(): \PDO
    {
        if ($this->driver() === 'sqlite') {
            $path = $this->sqlitePath();
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create database directory: %s', $dir));
            }
        }

        $pdo = new \PDO(
            $this->dsn(),
            isset($this->config['username']) ? (string) $this->config['username'] : null,
            isset($this->config['password']) ? (string) $this->config['password'] : null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        if ($this->driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    public static function autoIncrementPrimaryKey(string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'id BIGSERIAL PRIMARY KEY',
            'mysql' => 'id BIGINT AUTO_INCREMENT PRIMARY KEY',
            default => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }
}
