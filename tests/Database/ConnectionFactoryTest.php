<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Database;

use AsterMD\Storefront\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    private function factory(): ConnectionFactory
    {
        $root = sys_get_temp_dir() . '/db-' . uniqid();
        mkdir($root . '/storage/database', 0770, true);

        return new ConnectionFactory(['driver' => 'sqlite', 'database' => 'storage/database/app.sqlite'], $root);
    }

    public function testCreatesSqlitePdoWithHardening(): void
    {
        $pdo = $this->factory()->create();
        self::assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
    }

    public function testUnsupportedDriverIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConnectionFactory(['driver' => 'mongodb'], '/tmp');
    }

    public function testMysqlAndPgsqlDsnShapes(): void
    {
        $mysql = new ConnectionFactory(['driver' => 'mysql', 'database' => 'shop', 'host' => 'db', 'port' => 3306], '/tmp');
        self::assertSame('mysql:host=db;port=3306;dbname=shop;charset=utf8mb4', $mysql->dsn());
        $pgsql = new ConnectionFactory(['driver' => 'pgsql', 'database' => 'shop', 'host' => 'db', 'port' => 5432], '/tmp');
        self::assertSame('pgsql:host=db;port=5432;dbname=shop', $pgsql->dsn());
    }

    public function testAutoIncrementPrimaryKeyForAllDrivers(): void
    {
        self::assertSame('id BIGSERIAL PRIMARY KEY', ConnectionFactory::autoIncrementPrimaryKey('pgsql'));
        self::assertSame('id BIGINT AUTO_INCREMENT PRIMARY KEY', ConnectionFactory::autoIncrementPrimaryKey('mysql'));
        self::assertSame('id INTEGER PRIMARY KEY AUTOINCREMENT', ConnectionFactory::autoIncrementPrimaryKey('sqlite'));
    }

    public function testCreatesSqliteDatabaseDirectoryIfMissing(): void
    {
        $root = sys_get_temp_dir() . '/db-missing-' . uniqid();
        $dbPath = 'storage/database/app.sqlite';
        self::assertFalse(is_dir($root . '/storage/database'));

        $factory = new ConnectionFactory(['driver' => 'sqlite', 'database' => $dbPath], $root);
        $pdo = $factory->create();

        self::assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertTrue(is_dir($root . '/storage/database'));
        self::assertTrue(is_file($root . '/' . $dbPath));
    }

    /**
     * `:memory:` is SQLite's own name for an ephemeral database, not a
     * filename, and an operator reaching for it is asking for one.
     *
     * Rooting it like a relative path produced `sqlite:<root>/:memory:`, which
     * SQLite happily creates as a real file named `:memory:` in whatever
     * directory the application is rooted at — one page, 4 KB, sitting in the
     * repository root. The operator believes they are running against a
     * throwaway database while quietly accumulating a persistent one, and the
     * data they expected to vanish outlives the process.
     */
    public function testTheInMemoryDatabaseIsNotTreatedAsARelativePath(): void
    {
        $factory = new ConnectionFactory(['driver' => 'sqlite', 'database' => ':memory:'], '/tmp/some-root');

        self::assertSame('sqlite::memory:', $factory->dsn());
    }

    /**
     * SQLite's other non-path spelling. A `file:` URI carries its own query
     * options, so rooting it corrupts the options as well as the location.
     */
    public function testAFileUriIsPassedThroughUnrooted(): void
    {
        $factory = new ConnectionFactory(
            ['driver' => 'sqlite', 'database' => 'file:app?mode=memory&cache=shared'],
            '/tmp/some-root',
        );

        self::assertSame('sqlite:file:app?mode=memory&cache=shared', $factory->dsn());
    }
}
