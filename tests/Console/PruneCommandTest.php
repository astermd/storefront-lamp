<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use AsterMD\Storefront\Console\PruneCommand;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Retention\LogSweep;
use AsterMD\Storefront\Retention\RetentionPolicy;
use AsterMD\Storefront\Retention\RetentionSweep;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The sweep an operator schedules.
 *
 * What is worth pinning at this level is the safety of the default, the two
 * retention floors, and the exit code, because those are what a scheduler and
 * a crontab actually act on. Which rows may be taken belongs to the two
 * repositories and is pinned in {@see \AsterMD\Storefront\Tests\Repository\ExpiryTest}.
 *
 * The `[30.6]` sweeps added on top of those two are pinned the same way — the
 * predicates live in {@see \AsterMD\Storefront\Tests\Retention\RetentionSweepTest}
 * — except for the two refusals that decide whether a charge stays
 * reconcilable, which are asserted here as well because they are what an
 * operator is actually trusting when they schedule this.
 */
final class PruneCommandTest extends TestCase
{
    use TempDatabase;

    private \PDO $pdo;

    private CheckoutAttemptRepository $attempts;

    private RateLimitRepository $limits;

    private string $logDir;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        $this->attempts = new CheckoutAttemptRepository(fn (): \PDO => $this->pdo, 'sqlite');
        $this->limits = new RateLimitRepository(fn (): \PDO => $this->pdo, 'sqlite');
        $this->logDir = sys_get_temp_dir() . '/storefront-prune-logs-' . bin2hex(random_bytes(6));
        mkdir($this->logDir, 0775, true);
        $this->registerTempDirForCleanup($this->logDir);
    }

    public function testRunningItWithNoArgumentsDeletesNothingBecauseTheDefaultIsADryRun(): void
    {
        // The absence that matters: an operator who runs this to see what it
        // would do must not discover that it did it.
        $this->seedAgedClaim('idem-old');
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertNotNull($this->attempts->outcomeFor('idem-old'));
        self::assertStringContainsString('dry run', $tester->getDisplay());
    }

    public function testApplyTakesTheAgedRowsAndReportsWhatItTook(): void
    {
        $this->seedAgedClaim('idem-old');
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertNull($this->attempts->outcomeFor('idem-old'));
        self::assertStringNotContainsString('dry run', $tester->getDisplay());
    }

    public function testDryRunOverridesApply(): void
    {
        // Same override the reconcile sweep offers, so a scheduled entry can be
        // made harmless by adding a flag rather than by editing the line.
        $this->seedAgedClaim('idem-old');
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true, '--dry-run' => true]));
        self::assertNotNull($this->attempts->outcomeFor('idem-old'));
    }

    public function testAnAttemptThatMayStandForARealChargeIsNeverSweptEvenWithApply(): void
    {
        // The assertion that protects the money. A `sent` row reached a provider
        // with no idempotency of its own and was never answered; deleting it
        // lets that payload be posted a second time.
        $this->attempts->claim('idem-sent', 'sess-1', '2020-01-01T00:00:00+00:00');
        $this->attempts->markSent('idem-sent', '2020-01-01T00:00:00+00:00');
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(
            CheckoutAttemptRepository::STATE_SENT,
            $this->attempts->outcomeFor('idem-sent')['state'],
        );
    }

    public function testAnAgedUnresolvedAttemptIsReportedSoSomebodyCanResolveIt(): void
    {
        // It cannot be swept, so the one thing the sweep owes it is visibility:
        // money may have moved with nothing local confirming it, and that cart
        // is unretryable until a person closes the question.
        $this->attempts->claim('idem-sent', 'sess-1', '2020-01-01T00:00:00+00:00');
        $this->attempts->markSent('idem-sent', '2020-01-01T00:00:00+00:00');
        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertMatchesRegularExpression('/Unresolved checkout attempts[^\n]*: 1/', $tester->getDisplay());
    }

    public function testAFreshAttemptIsNotSweptEvenWithApply(): void
    {
        // A claim minutes old may be a submission in flight right now.
        $this->attempts->claim('idem-live', 'sess-1', gmdate('c'));
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertNotNull($this->attempts->outcomeFor('idem-live'));
    }

    public function testARateLimitWindowStillOpenIsNotSweptEvenWithApply(): void
    {
        // Deleting a counter inside its window hands that address a fresh
        // allowance, which is the flood guard failing open.
        $this->limits->hit('checkout.submit', '198.51.100.7', 300, time());
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(1, $this->rateLimitRows());
    }

    public function testAClosedRateLimitWindowIsSweptWithApply(): void
    {
        $this->limits->hit('checkout.submit', '198.51.100.7', 300, time() - 86_400 * 2);
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(0, $this->rateLimitRows());
    }

    public function testARetentionBelowTheFloorIsRefusedRatherThanHonoured(): void
    {
        // A zero or negative retention would sweep rows that are still doing
        // their job, so a typo in a crontab must fail loudly and change nothing
        // rather than quietly become "delete everything".
        $this->seedAgedClaim('idem-old');
        $tester = new CommandTester($this->command());

        self::assertSame(1, $tester->execute(['--apply' => true, '--attempt-days' => '0']));
        self::assertNotNull($this->attempts->outcomeFor('idem-old'));
    }

    public function testARateLimitRetentionBelowTheFloorIsRefusedToo(): void
    {
        $this->limits->hit('checkout.submit', '198.51.100.7', 300, time());
        $tester = new CommandTester($this->command());

        self::assertSame(1, $tester->execute(['--apply' => true, '--rate-limit-hours' => '0']));
        self::assertSame(1, $this->rateLimitRows());
    }

    /**
     * The first refusal, end to end.
     *
     * `[30.6]` says operational data expires on a schedule; it does not say a
     * schedule may take the only link between a charge and the journey that
     * placed it. The session below is four hundred days past a ninety-day
     * period and still standing, because an order points at it.
     */
    public function testASessionAnOrderReferencesIsNeverPrunedAtAnyAge(): void
    {
        $this->seedSession('sess-ordered', 400 * 86_400);
        $this->seedOrderFor('sess-ordered');
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(1, $this->sessionRows());
        self::assertStringContainsString('an order references', $tester->getDisplay());
    }

    /**
     * The second refusal, end to end.
     *
     * A one-day session period is a legitimate `[30.5]` choice, and it still
     * must not reach into the two days the reverse sweep is about to ask the
     * provider about. The journey below stopped thirty-six hours ago — past
     * its configured period, inside the reconciliation window — and is kept.
     */
    public function testASessionInsideTheReconciliationWindowIsNeverPrunedHoweverShortThePeriod(): void
    {
        $this->seedSession('sess-in-flight', 36 * 3_600);
        $tester = new CommandTester($this->command(
            new RetentionPolicy(sessionDays: 1, eventDays: 1, logDays: 1),
        ));

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(1, $this->sessionRows());
    }

    public function testAnAgedSessionAndAnAgedAuditRowAreTakenWithApply(): void
    {
        $this->seedSession('sess-old', 200 * 86_400);
        $this->seedEvent('sess-old', 'cart.created', 400 * 86_400);
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(0, $this->sessionRows());
        self::assertSame(0, $this->eventRows());
    }

    public function testTheDefaultDryRunReportsTheAgedRowsWithoutTakingThem(): void
    {
        $this->seedSession('sess-old', 200 * 86_400);
        $this->seedEvent('sess-old', 'cart.created', 400 * 86_400);
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertSame(1, $this->sessionRows());
        self::assertSame(1, $this->eventRows());
        self::assertStringContainsString('would delete: 1', $tester->getDisplay());
    }

    /**
     * `config/retention.php` says a period of zero disables that expiry. The
     * failure this guards is the opposite reading — zero as "everything is old
     * enough" — which on `sessions` would be unrecoverable.
     */
    public function testAPeriodOfZeroDisablesThatExpiryRatherThanDeletingEverything(): void
    {
        $this->seedSession('sess-old', 400 * 86_400);
        $this->seedEvent('sess-old', 'cart.created', 400 * 86_400);
        $tester = new CommandTester($this->command(
            new RetentionPolicy(sessionDays: 0, eventDays: 0, logDays: 0),
        ));

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertSame(1, $this->sessionRows());
        self::assertSame(1, $this->eventRows());
        self::assertStringContainsString('disabled', $tester->getDisplay());
    }

    public function testAnAgedLogFileIsTakenAndAFreshOneIsKept(): void
    {
        file_put_contents($this->logDir . '/emr-wire-old.log', 'x');
        touch($this->logDir . '/emr-wire-old.log', time() - 90 * 86_400);
        file_put_contents($this->logDir . '/app.log', 'x');
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute(['--apply' => true]));
        self::assertFileDoesNotExist($this->logDir . '/emr-wire-old.log');
        self::assertFileExists($this->logDir . '/app.log');
    }

    /**
     * The sweep that could not look must not report a clean zero.
     *
     * This is the shape an operator is least equipped to catch: the exit code
     * is 0, the line reads `would delete: 0`, and it is word for word what a
     * healthy run against an already-swept directory prints. Meanwhile the
     * file `[30.9]` names — the wire log, holding verbatim card numbers and
     * Social Security Numbers — is still on disk, and the nightly job has been
     * reporting success over it for as long as the mode has been wrong.
     */
    public function testAnUnlistableLogDirectoryExitsNonZeroRatherThanReportingACleanZero(): void
    {
        file_put_contents($this->logDir . '/emr-wire-old.log', 'x');
        touch($this->logDir . '/emr-wire-old.log', time() - 90 * 86_400);
        chmod($this->logDir, 0100);

        if (@scandir($this->logDir) !== false) {
            chmod($this->logDir, 0775);
            self::markTestSkipped('This process can list a directory it has no read permission on.');
        }

        $tester = new CommandTester($this->command());

        try {
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('LogDirectoryUnreadable', $tester->getDisplay());
            self::assertStringNotContainsString('storage/logs files last written before', $tester->getDisplay());
        } finally {
            chmod($this->logDir, 0775);
        }
    }

    /**
     * A partial `--apply` naming the database sweeps that already ran.
     *
     * The log sweep runs last, so this failure means every delete above it has
     * already happened — and the operator's only record of the run is the
     * console.
     */
    public function testAnUnlistableLogDirectoryNamesTheDatabaseSweepsThatAlreadyRan(): void
    {
        $this->seedAgedClaim('idem-old');
        chmod($this->logDir, 0100);

        if (@scandir($this->logDir) !== false) {
            chmod($this->logDir, 0775);
            self::markTestSkipped('This process can list a directory it has no read permission on.');
        }

        $tester = new CommandTester($this->command());

        try {
            self::assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
            self::assertStringContainsString('checkout_attempts', $tester->getDisplay());
            self::assertStringContainsString('deleted: 1', $tester->getDisplay());
        } finally {
            chmod($this->logDir, 0775);
        }
    }

    public function testANonNumericRetentionIsRefusedRatherThanReadAsZero(): void
    {
        $this->seedAgedClaim('idem-old');
        $tester = new CommandTester($this->command());

        self::assertSame(1, $tester->execute(['--apply' => true, '--attempt-days' => 'thirty']));
        self::assertNotNull($this->attempts->outcomeFor('idem-old'));
    }

    public function testADatabaseFailureExitsNonZeroRatherThanReportingAnEmptySweep(): void
    {
        // A scheduler reads the exit code. "Nothing to prune" and "the database
        // was unreachable" must never look the same to it.
        $broken = new PruneCommand(
            new CheckoutAttemptRepository(static fn (): \PDO => throw new \PDOException('no database'), 'sqlite'),
            new RateLimitRepository(static fn (): \PDO => throw new \PDOException('no database'), 'sqlite'),
            new RetentionSweep(static fn (): \PDO => throw new \PDOException('no database')),
            new LogSweep($this->logDir),
            new RetentionPolicy(sessionDays: 90, eventDays: 365, logDays: 30),
        );
        $tester = new CommandTester($broken);

        self::assertSame(1, $tester->execute([]));
    }

    /**
     * A cron job's stdout reaches neither of {@see \AsterMD\Storefront\Support\OperatorLog}'s
     * defences, and MySQL's 1366 quotes the value it refused. The class and
     * the SQLSTATE answer what an outage asks; the sentence does not.
     */
    public function testAFailureReportsTheExceptionClassAndNeverItsMessage(): void
    {
        $refusal = new \PDOException(
            "SQLSTATE[22001]: Data too long for column 'idempotency_key' at row 'dana@example.com'",
        );
        $refusal->errorInfo = ['22001', 1406, "Data too long for column 'idempotency_key' at row 'dana@example.com'"];

        $broken = new PruneCommand(
            new CheckoutAttemptRepository(static fn (): \PDO => throw $refusal, 'sqlite'),
            $this->limits,
            new RetentionSweep(fn (): \PDO => $this->pdo),
            new LogSweep($this->logDir),
            new RetentionPolicy(sessionDays: 90, eventDays: 365, logDays: 30),
        );
        $tester = new CommandTester($broken);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('dana@example.com', $tester->getDisplay());
        self::assertStringContainsString('PDOException', $tester->getDisplay());
        self::assertStringContainsString('22001', $tester->getDisplay());
    }

    /**
     * The three sweeps share one `try` and the deletes are not one
     * transaction, so a throw in a later sweep leaves rows already gone. An
     * operator reading only the failure line would believe nothing happened.
     */
    public function testAPartialDeleteIsNamedWhenALaterSweepThrows(): void
    {
        $this->seedAgedClaim('idem-old');
        $broken = new PruneCommand(
            $this->attempts,
            new RateLimitRepository(static fn (): \PDO => throw new \PDOException('no database'), 'sqlite'),
            new RetentionSweep(fn (): \PDO => $this->pdo),
            new LogSweep($this->logDir),
            new RetentionPolicy(sessionDays: 90, eventDays: 365, logDays: 30),
        );
        $tester = new CommandTester($broken);

        self::assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('checkout_attempts', $tester->getDisplay());
        self::assertStringContainsString('deleted: 1', $tester->getDisplay());
    }

    private function command(?RetentionPolicy $policy = null): PruneCommand
    {
        return new PruneCommand(
            $this->attempts,
            $this->limits,
            new RetentionSweep(fn (): \PDO => $this->pdo),
            new LogSweep($this->logDir),
            $policy ?? new RetentionPolicy(sessionDays: 90, eventDays: 365, logDays: 30),
        );
    }

    /** Writes one `sessions` row whose `updated_at` is exactly `$secondsAgo` old. */
    private function seedSession(string $uuid, int $secondsAgo): void
    {
        $movedAt = gmdate('c', time() - $secondsAgo);
        $this->pdo->prepare(
            'INSERT INTO sessions (session_uuid, opportunity_id, journey_state, attribution, created_at, updated_at)
             VALUES (?, NULL, ?, NULL, ?, ?)',
        )->execute([$uuid, '{}', $movedAt, $movedAt]);
    }

    private function seedOrderFor(string $uuid): void
    {
        $placedAt = gmdate('c', time() - 400 * 86_400);
        $this->pdo->prepare(
            'INSERT INTO orders (session_uuid, provider_reference, anchor_slug, amount_cents, currency, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$uuid, 'ref-' . $uuid, 'tirzepatide', 19900, 'USD', 'placed', $placedAt, $placedAt]);
    }

    private function seedEvent(string $uuid, string $name, int $secondsAgo): void
    {
        $this->pdo->prepare('INSERT INTO events (session_uuid, name, payload, created_at) VALUES (?, ?, NULL, ?)')
            ->execute([$uuid, $name, gmdate('c', time() - $secondsAgo)]);
    }

    private function sessionRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    }

    private function eventRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    }

    private function seedAgedClaim(string $key): void
    {
        $this->attempts->claim($key, 'sess-1', '2020-01-01T00:00:00+00:00');
    }

    private function rateLimitRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();
    }
}
