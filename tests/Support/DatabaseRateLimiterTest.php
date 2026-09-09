<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * The checkout limiter, and with it the `rate_limits` table underneath it.
 *
 * `RateLimitRepository` has no test file of its own on purpose: every
 * behaviour it has — the conditional insert, the window rollover, the
 * backwards-clock rollover, the plain increment — is only ever reached through
 * this class, and testing it here keeps the assertions phrased as the policy
 * question a caller actually asks rather than as row counts.
 *
 * Every test drives a real migrated SQLite database. A fake counter would
 * prove nothing: the thing being relied on is that a durable row survives the
 * request that wrote it, which is exactly what a fake cannot be wrong about.
 */
final class DatabaseRateLimiterTest extends TestCase
{
    use TempDatabase;

    private int $now = 1_700_000_000;

    private ?\PDO $pdo = null;

    /**
     * Memoised, so two limiters built inside one test share one database in
     * the way two requests share one server's.
     */
    private function pdo(): \PDO
    {
        return $this->pdo ??= $this->tempPdo();
    }

    /**
     * One bucket named as `config/payment.php` names it, plus a second so the
     * isolation tests have something to be isolated from.
     */
    private function limiterWith(int $limit, int $window, ?CapturedLog $log = null): DatabaseRateLimiter
    {
        $pdo = $this->pdo();

        return new DatabaseRateLimiter(
            new RateLimitRepository(static fn (): \PDO => $pdo, 'sqlite'),
            [
                'checkout.submit' => ['limit' => $limit, 'window_seconds' => $window],
                'checkout.promo' => ['limit' => $limit, 'window_seconds' => $window],
            ],
            ($log ?? new CapturedLog())->log,
            // The clock is injected rather than read, so a test can jump a
            // window instead of sleeping through one.
            fn (): int => $this->now,
        );
    }

    /**
     * The table is dropped rather than the connection broken, because it fails
     * the same way from the limiter's side — a `\PDOException` out of `hit()` —
     * and does it instantly, with no port to hang on and no network involved.
     */
    private function limiterWithBrokenDatabase(?CapturedLog $log = null): DatabaseRateLimiter
    {
        $limiter = $this->limiterWith(limit: 2, window: 60, log: $log);
        $this->pdo()->exec('DROP TABLE rate_limits');

        return $limiter;
    }

    public function testTheFirstCallsInAWindowAreAllowed(): void
    {
        $limiter = $this->limiterWith(limit: 3, window: 60);

        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
    }

    public function testTheCallAfterTheLimitIsRefused(): void
    {
        $limiter = $this->limiterWith(limit: 3, window: 60);
        $limiter->allow('checkout.submit', '203.0.113.7');
        $limiter->allow('checkout.submit', '203.0.113.7');
        $limiter->allow('checkout.submit', '203.0.113.7');

        self::assertFalse($limiter->allow('checkout.submit', '203.0.113.7'));
    }

    public function testANewWindowResetsTheCount(): void
    {
        $limiter = $this->limiterWith(limit: 2, window: 60);
        $limiter->allow('checkout.submit', '203.0.113.7');
        $limiter->allow('checkout.submit', '203.0.113.7');
        self::assertFalse($limiter->allow('checkout.submit', '203.0.113.7'));

        $this->now += 60;

        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
    }

    public function testARefusalDoesNotExtendTheWindow(): void
    {
        // An honest visitor who tripped the limit recovers when the window they
        // tripped it in ends, not when they stop trying. A refusal that pushed
        // the window forward would keep a retrying browser locked out forever.
        $limiter = $this->limiterWith(limit: 1, window: 60);
        $limiter->allow('checkout.submit', '203.0.113.7');

        for ($second = 1; $second <= 59; ++$second) {
            $this->now = 1_700_000_000 + $second;
            self::assertFalse($limiter->allow('checkout.submit', '203.0.113.7'));
        }

        $this->now = 1_700_000_000 + 60;

        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
    }

    public function testTwoIdentitiesDoNotShareAnAllowance(): void
    {
        // One address spending its allowance must not refuse everybody else:
        // the counter is per address, or a single stuck browser closes the shop.
        $limiter = $this->limiterWith(limit: 1, window: 60);
        $limiter->allow('checkout.submit', '203.0.113.7');
        self::assertFalse($limiter->allow('checkout.submit', '203.0.113.7'));

        self::assertTrue($limiter->allow('checkout.submit', '198.51.100.4'));
    }

    public function testTwoBucketsDoNotShareAnAllowance(): void
    {
        // Promo attempts and submit attempts have different limits for
        // different reasons; spending one must not spend the other.
        $limiter = $this->limiterWith(limit: 1, window: 60);
        $limiter->allow('checkout.submit', '203.0.113.7');
        self::assertFalse($limiter->allow('checkout.submit', '203.0.113.7'));

        self::assertTrue($limiter->allow('checkout.promo', '203.0.113.7'));
    }

    public function testAClockThatMovesBackwardsStartsAFreshWindow(): void
    {
        // An NTP correction or a rollback moves the machine's clock behind the
        // stored window start. Treating that as "the window has not begun yet"
        // would lock the address out until real time caught up with a window
        // that never happened.
        $limiter = $this->limiterWith(limit: 1, window: 60);
        $limiter->allow('checkout.submit', '203.0.113.7');
        self::assertFalse($limiter->allow('checkout.submit', '203.0.113.7'));

        $this->now -= 3_600;

        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
    }

    public function testDiscardingTheSessionDoesNotResetTheAllowance(): void
    {
        // The session-backed limiter this replaces was bypassed by dropping a
        // cookie. This one is keyed on the client address, which the caller
        // cannot choose ([13.8], [29.23]).
        $limiter = $this->limiterWith(limit: 2, window: 60);

        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
        self::assertFalse($limiter->allow('checkout.submit', '203.0.113.7'));

        // A brand-new limiter instance, as a fresh request would build: the count
        // is in the database, not in this object.
        self::assertFalse($this->limiterWith(limit: 2, window: 60)->allow('checkout.submit', '203.0.113.7'));
    }

    public function testAnUnreachableDatabaseFailsOpenRatherThanBlockingCheckout(): void
    {
        // [20.1]: an outage must not become a storefront that cannot take money.
        // A rate limiter that fails closed turns a database blip into a total
        // outage, which is a worse failure than an unthrottled minute.
        $limiter = $this->limiterWithBrokenDatabase();

        self::assertTrue($limiter->allow('checkout.submit', '203.0.113.7'));
    }

    public function testAnUnreachableDatabaseIsLoggedOnceRatherThanPerCall(): void
    {
        // Failing open silently is how a disarmed guard stays disarmed for a
        // week. Failing open once per submit is how the log stops being
        // readable during the outage that made it interesting.
        $log = new CapturedLog();
        $limiter = $this->limiterWithBrokenDatabase($log);

        $limiter->allow('checkout.submit', '203.0.113.7');
        $limiter->allow('checkout.submit', '203.0.113.7');
        $limiter->allow('checkout.promo', '198.51.100.4');

        self::assertCount(1, $log->eventsNamed('rate_limit_unavailable'));
    }

    public function testTheUnavailableLogLineCarriesNoVisitorAddress(): void
    {
        $log = new CapturedLog();
        $this->limiterWithBrokenDatabase($log)->allow('checkout.submit', '203.0.113.7');

        self::assertStringNotContainsString('203.0.113.7', $log->contents());
    }

    public function testAnUnconfiguredBucketIsAllowedAndReported(): void
    {
        // A config typo must not close the shop -- but a guard that is silently
        // not guarding anything is the failure this whole class exists to stop,
        // so it says so in the operator log.
        $log = new CapturedLog();
        $limiter = $this->limiterWith(limit: 1, window: 60, log: $log);

        self::assertTrue($limiter->allow('checkout.nonesuch', '203.0.113.7'));
        self::assertTrue($limiter->allow('checkout.nonesuch', '203.0.113.7'));
        self::assertCount(1, $log->eventsNamed('rate_limit_bucket_unconfigured'));
    }

    public function testTheCountSurvivesInTheTableAndNotInTheObject(): void
    {
        // The row is the point: a limiter is rebuilt on every request, so a
        // count held anywhere else is a count that resets on every request.
        $this->limiterWith(limit: 5, window: 60)->allow('checkout.submit', '203.0.113.7');
        $this->limiterWith(limit: 5, window: 60)->allow('checkout.submit', '203.0.113.7');

        $statement = $this->pdo()->query(
            "SELECT hits FROM rate_limits WHERE bucket = 'checkout.submit' AND identity = '203.0.113.7'",
        );

        self::assertSame(2, (int) $statement->fetchColumn());
    }
}
