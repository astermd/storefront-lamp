<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Retention;

use AsterMD\Storefront\Reconciliation\ForwardReconciliation;
use AsterMD\Storefront\Reconciliation\ReverseReconciliation;
use AsterMD\Storefront\Retention\RetentionPolicy;
use AsterMD\Storefront\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * The periods `[30.5]` puts in configuration, turned into the cutoffs
 * `[30.6]`'s job compares against.
 */
final class RetentionPolicyTest extends TestCase
{
    /** 2026-08-30T00:00:00+00:00, so every expectation below can be written out longhand. */
    private const int NOW = 1_787_011_200;

    public function testEachPeriodBecomesACutoffThatManyDaysBeforeNow(): void
    {
        $policy = new RetentionPolicy(sessionDays: 90, eventDays: 365, logDays: 30);

        self::assertSame(gmdate('c', self::NOW - 90 * 86400), $policy->sessionCutoff(self::NOW));
        self::assertSame(gmdate('c', self::NOW - 365 * 86400), $policy->eventCutoff(self::NOW));
        self::assertSame(gmdate('c', self::NOW - 30 * 86400), $policy->logCutoff(self::NOW));
    }

    public function testAPeriodOfZeroOrLessDisablesThatExpiryRatherThanDeletingEverything(): void
    {
        $policy = new RetentionPolicy(sessionDays: 0, eventDays: -1, logDays: 0);

        self::assertNull($policy->sessionCutoff(self::NOW));
        self::assertNull($policy->eventCutoff(self::NOW));
        self::assertNull($policy->logCutoff(self::NOW));
    }

    /**
     * The second hard constraint: whatever the configured period says, the
     * cutoff never reaches into the window a reconciliation sweep is still
     * reading.
     *
     * A one-day session period would otherwise delete the journey behind a
     * checkout the provider charged and the reverse sweep has not yet matched
     * — the exact shape of loss the sweep exists to catch.
     */
    public function testAPeriodShorterThanTheReconciliationWindowIsHeldBackToIt(): void
    {
        $policy = new RetentionPolicy(sessionDays: 1, eventDays: 1, logDays: 1);

        $floor = gmdate('c', self::NOW - RetentionPolicy::RECONCILIATION_FLOOR_SECONDS);

        self::assertSame($floor, $policy->sessionCutoff(self::NOW));
        self::assertSame($floor, $policy->eventCutoff(self::NOW));
    }

    /**
     * The log sweep touches no row either sweep reads, so it is not held back
     * — stated as an assertion so the exemption is a decision rather than an
     * oversight.
     */
    public function testTheLogCutoffIsNotHeldBackByTheReconciliationWindow(): void
    {
        $policy = new RetentionPolicy(sessionDays: 90, eventDays: 365, logDays: 1);

        self::assertSame(gmdate('c', self::NOW - 86400), $policy->logCutoff(self::NOW));
    }

    /**
     * The floor is a number in this class, and the windows it protects are
     * defaults in two other ones. This is what stops the two drifting apart:
     * widen either sweep and this fails until the floor is widened with it.
     */
    public function testTheFloorIsAtLeastAsWideAsBothReconciliationSweepsLookBack(): void
    {
        $widest = max(
            self::constructorDefault(ReverseReconciliation::class, 'lookbackSeconds'),
            self::constructorDefault(ReverseReconciliation::class, 'graceSeconds'),
            self::constructorDefault(ForwardReconciliation::class, 'repeatFailureAfterSeconds'),
            self::constructorDefault(ForwardReconciliation::class, 'graceSeconds'),
        );

        self::assertGreaterThanOrEqual($widest, RetentionPolicy::RECONCILIATION_FLOOR_SECONDS);
    }

    public function testItReadsThePeriodsFromConfiguration(): void
    {
        $policy = RetentionPolicy::fromConfig(Config::load(dirname(__DIR__, 2) . '/config'));

        self::assertGreaterThan(0, $policy->sessionDays());
        self::assertGreaterThan($policy->sessionDays(), $policy->eventDays());
        self::assertGreaterThan(0, $policy->logDays());
    }

    private static function constructorDefault(string $class, string $parameter): int
    {
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $candidate) {
            if ($candidate->getName() === $parameter) {
                return (int) $candidate->getDefaultValue();
            }
        }

        self::fail($class . ' no longer has a ' . $parameter . ' parameter');
    }
}
