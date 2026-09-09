<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Database;

use AsterMD\Storefront\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    private function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    public function testAppliesInitialSchemaOnceAndIsIdempotent(): void
    {
        $migrator = new Migrator($this->pdo, 'sqlite', $this->migrationsDir());
        $first = $migrator->migrate();
        self::assertContains('0001_initial_schema', $first);
        self::assertSame([], $migrator->migrate());
        foreach (['sessions', 'orders', 'events', 'migrations'] as $table) {
            $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
            self::assertSame($table, $stmt->fetchColumn(), "missing table {$table}");
        }
    }

    public function testOrdersAmountIsIntegerCents(): void
    {
        (new Migrator($this->pdo, 'sqlite', $this->migrationsDir()))->migrate();
        $this->pdo->exec("INSERT INTO sessions (session_uuid, journey_state, created_at, updated_at) VALUES ('s1', '{}', '2026-01-01', '2026-01-01')");
        $this->pdo->exec("INSERT INTO orders (session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, created_at, updated_at) VALUES ('s1', 'ref1', 'semaglutide', 13800, 'USD', 'placed', '2026-01-01', '2026-01-01')");
        self::assertSame(13800, (int) $this->pdo->query('SELECT amount_cents FROM orders')->fetchColumn());
    }
}
