<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Database;

use AsterMD\Storefront\Database\Migrator;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use PHPUnit\Framework\TestCase;

/**
 * The checkout migrations, and the three things they have to keep being true.
 *
 * They **must stay re-runnable**. `0002` relaxes a NOT NULL, adds columns to a
 * table `0001` already created, and on SQLite rebuilds `orders` outright —
 * none of which any engine spells "if it is not already done", so every step
 * guards itself. A migration that only works on a fresh database is a
 * migration nobody can safely re-apply to a half-migrated one.
 *
 * The attempt rows the earlier two-state shape left behind have to reach the
 * three-state lifecycle, in the direction that cannot cause a second charge.
 *
 * And that promotion has to run **where an operator will actually be standing**
 * — on a database that already recorded the file introducing the new states as
 * applied. `Migrator` keys on filename, so a statement added to such a file
 * runs nowhere, which is why the promotion lives in its own file and why the
 * test for it goes in through `migrate()` rather than calling a closure by
 * hand.
 */
final class CheckoutMigrationTest extends TestCase
{
    /** The state the two-state lifecycle wrote, and which no code writes any more. */
    private const string LEGACY_IN_FLIGHT = 'in_flight';

    private \PDO $pdo;

    /** @var list<string> staged migration directories, removed after each test */
    private array $staged = [];

    protected function tearDown(): void
    {
        foreach ($this->staged as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        $this->staged = [];
    }

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        (new Migrator($this->pdo, 'sqlite', dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    }

    public function testALegacyInFlightAttemptBecomesSentRatherThanClaimed(): void
    {
        // A row written by the two-state shape may or may not have reached the
        // provider, and nothing in the row says which. `sent` refuses to be
        // taken over and `claimed` invites it, so the unknowable case has to
        // land on `sent`: the cost of being wrong is a buyer told to wait,
        // against a second order the provider creates and charges.
        //
        // This covers the statement inside `0002`, which reaches a row only on
        // a database that had not yet recorded that file. The migration that
        // reaches the rows on every *other* database is `0003`, and its own
        // test walks that sequence through `migrate()`.
        $this->insertAttempt('legacy-1', self::LEGACY_IN_FLIGHT);

        $this->reapply();

        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $this->stateOf('legacy-1'));
    }

    public function testAnAlreadyCompletedAttemptIsLeftExactlyWhereItIs(): void
    {
        $this->insertAttempt('done-1', CheckoutAttemptRepository::STATE_COMPLETE);
        $this->insertAttempt('fresh-1', CheckoutAttemptRepository::STATE_CLAIMED);

        $this->reapply();

        self::assertSame(CheckoutAttemptRepository::STATE_COMPLETE, $this->stateOf('done-1'));
        self::assertSame(CheckoutAttemptRepository::STATE_CLAIMED, $this->stateOf('fresh-1'));
    }

    public function testTheMigrationSurvivesBeingRunThreeTimesOverASeededDatabase(): void
    {
        // The property an operator relies on when a deploy is retried, and the
        // one every guard inside the file exists for.
        $this->pdo->exec("INSERT INTO sessions (session_uuid, journey_state, created_at, updated_at) VALUES ('s1', '{}', '2026-01-01', '2026-01-01')");
        $this->pdo->exec("INSERT INTO orders (session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, created_at, updated_at)
            VALUES ('s1', 'ref1', 'tirzepatide', 13800, 'USD', 'placed', '2026-01-01', '2026-01-01')");
        $this->insertAttempt('legacy-1', self::LEGACY_IN_FLIGHT);

        $this->reapply();
        $this->reapply();
        $this->reapply();

        self::assertSame('ref1', $this->pdo->query('SELECT provider_reference FROM orders')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $this->stateOf('legacy-1'));

        // On SQLite this file rebuilds `orders` outright, which would take the
        // later migration's column with it. The rebuild is skipped once the
        // NOT NULL it exists to relax is already relaxed, and this is what says
        // so: a retried deploy of an earlier file must not undo a later one.
        self::assertSame(0, (int) $this->pdo->query('SELECT is_upsell FROM orders')->fetchColumn());
    }

    public function testAnOrderCanStillBeRecordedWithNoAnalyticsSession(): void
    {
        // Why `orders.session_uuid` is relaxed at all: inventing an identifier
        // to satisfy a NOT NULL is the synthetic identifier `[20.8]` forbids.
        $this->reapply();

        $this->pdo->exec("INSERT INTO orders (session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, created_at, updated_at)
            VALUES (NULL, 'ref-no-session', 'tirzepatide', 13800, 'USD', 'placed', '2026-01-01', '2026-01-01')");

        self::assertNull($this->pdo->query("SELECT session_uuid FROM orders WHERE provider_reference = 'ref-no-session'")->fetchColumn());
    }

    public function testTheLegacyStatePromotionRunsOnAnEnvironmentThatAlreadyAppliedTheEarlierFile(): void
    {
        // The promotion an operator actually gets. `Migrator` records a
        // migration by filename, so every environment holding rows in the old
        // state had already recorded the file that introduced the new states as
        // applied -- and a statement added to an applied file is a statement
        // nobody executes. This walks that exact sequence: migrate as far as
        // that file, acquire the legacy row, then deploy the next one and go in
        // through `migrate()` rather than through a closure by hand.
        $dir = $this->stagedMigrations('0001_initial_schema', '0002_checkout');
        $pdo = $this->freshPdo();

        (new Migrator($pdo, 'sqlite', $dir))->migrate();
        $this->insertAttemptInto($pdo, 'legacy-1', self::LEGACY_IN_FLIGHT);

        $this->stageAlso($dir, '0003_upsells');
        $applied = (new Migrator($pdo, 'sqlite', $dir))->migrate();

        self::assertSame(['0003_upsells'], $applied, 'the earlier file is recorded, so only the new one runs');
        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $this->stateOfIn($pdo, 'legacy-1'));
    }

    public function testTheNewMigrationSurvivesBeingRunTwiceOverASeededDatabase(): void
    {
        $this->insertAttempt('legacy-1', self::LEGACY_IN_FLIGHT);

        $migration = require dirname(__DIR__, 2) . '/database/migrations/0003_upsells.php';
        $migration($this->pdo, 'sqlite');
        $migration($this->pdo, 'sqlite');

        self::assertSame(CheckoutAttemptRepository::STATE_SENT, $this->stateOf('legacy-1'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM orders WHERE is_upsell <> 0')->fetchColumn());
    }

    /**
     * Runs `0002`'s own closure again.
     *
     * This is what a *retried* deploy of that file does — the property the
     * guards inside it exist for — and not what a later deploy does to an
     * environment that has already recorded it. Only
     * {@see self::testTheLegacyStatePromotionRunsOnAnEnvironmentThatAlreadyAppliedTheEarlierFile()}
     * covers that, and it has to go in through `migrate()` to do so.
     */
    private function reapply(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/0002_checkout.php';
        $migration($this->pdo, 'sqlite');
    }

    /** A migrations directory holding copies of only the named files, so `migrate()` can be stopped part-way. */
    private function stagedMigrations(string ...$names): string
    {
        $dir = sys_get_temp_dir() . '/storefront-migrations-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->staged[] = $dir;

        foreach ($names as $name) {
            $this->stageAlso($dir, $name);
        }

        return $dir;
    }

    private function stageAlso(string $dir, string $name): void
    {
        copy(dirname(__DIR__, 2) . '/database/migrations/' . $name . '.php', $dir . '/' . $name . '.php');
    }

    private function freshPdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $pdo;
    }

    private function insertAttemptInto(\PDO $pdo, string $key, string $state): void
    {
        $pdo->prepare('INSERT INTO checkout_attempts (idempotency_key, session_key, state, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$key, 'sess-1', $state, '2026-08-24T00:00:00+00:00']);
    }

    private function stateOfIn(\PDO $pdo, string $key): string
    {
        $statement = $pdo->prepare('SELECT state FROM checkout_attempts WHERE idempotency_key = ?');
        $statement->execute([$key]);

        return (string) $statement->fetchColumn();
    }

    private function insertAttempt(string $key, string $state): void
    {
        $this->pdo->prepare('INSERT INTO checkout_attempts (idempotency_key, session_key, state, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$key, 'sess-1', $state, '2026-08-24T00:00:00+00:00']);
    }

    private function stateOf(string $key): string
    {
        $statement = $this->pdo->prepare('SELECT state FROM checkout_attempts WHERE idempotency_key = ?');
        $statement->execute([$key]);

        return (string) $statement->fetchColumn();
    }
}
