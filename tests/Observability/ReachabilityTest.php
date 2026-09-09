<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Observability;

use AsterMD\Storefront\Observability\Reachability;
use PHPUnit\Framework\TestCase;

final class ReachabilityTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/reachability-' . bin2hex(random_bytes(6)) . '/state.json';
    }

    protected function tearDown(): void
    {
        // Restored first: a test that takes write permission away to reach the
        // refusal branch would otherwise leave its directory behind.
        @chmod(dirname($this->cacheFile), 0770);

        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }

        if (is_dir(dirname($this->cacheFile))) {
            rmdir(dirname($this->cacheFile));
        }
    }

    /**
     * @param \Closure(): bool $emr
     * @param \Closure(): bool $provider
     */
    private function reachability(\Closure $emr, \Closure $provider, int $now = self::NOW): Reachability
    {
        return new Reachability(
            emr: $emr,
            provider: $provider,
            cacheFile: $this->cacheFile,
            minimumIntervalSeconds: 30,
            clock: static fn (): int => $now,
        );
    }

    public function testTheDefaultReadMakesNoOutboundCallAtAll(): void
    {
        $calls = 0;
        $reachability = $this->reachability(
            static function () use (&$calls): bool {
                ++$calls;

                return true;
            },
            static fn (): bool => true,
        );

        $snapshot = $reachability->cached();

        self::assertSame(0, $calls);
        self::assertSame('unknown', $snapshot['emr']['status']);
        self::assertNull($snapshot['emr']['checked_at']);
    }

    public function testAnExplicitProbeReachesBothDependenciesAndRecordsLatency(): void
    {
        $snapshot = $this->reachability(static fn (): bool => true, static fn (): bool => false)->probe();

        self::assertSame('ok', $snapshot['emr']['status']);
        self::assertSame('unreachable', $snapshot['provider']['status']);
        self::assertIsFloat($snapshot['emr']['latency_ms']);
        self::assertSame(gmdate('c', self::NOW), $snapshot['emr']['checked_at']);
    }

    public function testAProbeThatThrowsIsUnreachableRatherThanAnError(): void
    {
        $snapshot = $this->reachability(
            static fn (): never => throw new \RuntimeException('connection refused to secret.host'),
            static fn (): bool => true,
        )->probe();

        self::assertSame('unreachable', $snapshot['emr']['status']);
        self::assertSame('RuntimeException', $snapshot['emr']['failure']);
        self::assertStringNotContainsString('secret.host', (string) json_encode($snapshot));
    }

    /**
     * The endpoint is unauthenticated and public, so the opt-in parameter
     * cannot be allowed to become an amplifier for two external systems.
     */
    public function testASecondProbeInsideTheMinimumIntervalServesTheCachedResultInstead(): void
    {
        $calls = 0;
        $probe = function () use (&$calls): bool {
            ++$calls;

            return true;
        };

        $this->reachability($probe, static fn (): bool => true)->probe();
        $this->reachability($probe, static fn (): bool => true, self::NOW + 10)->probe();

        self::assertSame(1, $calls);
    }

    public function testAProbeAfterTheIntervalRunsAgainAndReportsTheAgeOfWhatItRead(): void
    {
        $calls = 0;
        $probe = function () use (&$calls): bool {
            ++$calls;

            return true;
        };

        $this->reachability($probe, static fn (): bool => true)->probe();
        $snapshot = $this->reachability($probe, static fn (): bool => true, self::NOW + 31)->probe();

        self::assertSame(2, $calls);
        self::assertSame(0, $snapshot['emr']['age_seconds']);
    }

    public function testACachedReadAfterAProbeCarriesTheResultAndItsAge(): void
    {
        $this->reachability(static fn (): bool => true, static fn (): bool => true)->probe();
        $snapshot = $this->reachability(
            static fn (): never => throw new \LogicException('must not be called'),
            static fn (): never => throw new \LogicException('must not be called'),
            self::NOW + 120,
        )->cached();

        self::assertSame('ok', $snapshot['emr']['status']);
        self::assertSame(120, $snapshot['emr']['age_seconds']);
    }

    /**
     * `[20.1]`: the endpoint answers even when this class cannot do its job.
     *
     * A cache it cannot write is a degraded probe, not a broken endpoint. What
     * it must still produce is a well-formed snapshot in the documented
     * vocabulary — and `unknown` rather than a figure, because no figure was
     * obtained.
     */
    public function testAnUnwritableCacheDirectoryStillProducesAnAnswer(): void
    {
        $reachability = new Reachability(
            emr: static fn (): bool => true,
            provider: static fn (): bool => true,
            cacheFile: '/proc/nonexistent-directory/state.json',
            minimumIntervalSeconds: 30,
            clock: static fn (): int => self::NOW,
        );

        self::assertSame('unknown', $reachability->probe()['emr']['status']);
        self::assertSame('unknown', $reachability->cached()['emr']['status']);
    }

    /**
     * A probe that cannot bound itself does not run at all.
     *
     * The rate limit's only state is the cache file, so a directory this
     * process cannot write means every hit reads an empty record, passes the
     * interval test and calls out. `/health/` is unauthenticated and public,
     * which turns that into an amplifier into the EMR and the payment provider
     * — twenty probes in a second becoming twenty calls each, against a limit
     * of one per thirty seconds, and hardest at the moment those systems are
     * already struggling. It is the exact outage this class exists to prevent.
     *
     * So the proof is the precondition: the slot is claimed before either call
     * and the calls only happen if the claim landed. A limiter that cannot
     * remember it ran cannot promise it will not run again, and the honest
     * answer to "is the EMR reachable" is then `unknown`, not a figure obtained
     * by ignoring the limit.
     */
    public function testAProbeThatCannotRecordItsRateLimitRefusesToCallOutAtAll(): void
    {
        $calls = 0;
        $probe = function () use (&$calls): bool {
            ++$calls;

            return true;
        };

        $this->makeCacheDirectoryUnwritable();

        for ($hit = 0; $hit < 20; $hit++) {
            $snapshot = $this->reachability($probe, $probe, self::NOW + $hit)->probe();
            self::assertSame(Reachability::PROBE_REFUSED, $snapshot['probe']);
            self::assertSame('unknown', $snapshot['emr']['status']);
            self::assertSame('unknown', $snapshot['provider']['status']);
        }

        self::assertSame(0, $calls, 'a probe that cannot prove it is within its limit must not run');
    }

    /**
     * Every answer says what the probe did, so a stale figure is never
     * unexplained.
     *
     * Without this an operator who asks for a live check and gets `unknown`
     * cannot tell a refusal from a dependency nobody has ever asked about —
     * and the refusal is the one that needs a `chmod`. `checks` cannot carry
     * it: dropping `/health/` to 503 over a cache directory would take the
     * whole site out of rotation, which is worse than the amplification and
     * is what `[20.1]` forbids. So it is reported beside the verdict.
     */
    public function testEveryAnswerSaysWhatTheProbeDidSoAStaleFigureIsNeverUnexplained(): void
    {
        $up = static fn (): bool => true;

        self::assertSame(
            Reachability::PROBE_NOT_ATTEMPTED,
            $this->reachability($up, $up)->cached()['probe'],
            'an ordinary hit makes no outbound call, and says so',
        );
        self::assertSame(Reachability::PROBE_RAN, $this->reachability($up, $up)->probe()['probe']);
        self::assertSame(
            Reachability::PROBE_RATE_LIMITED,
            $this->reachability($up, $up, self::NOW + 10)->probe()['probe'],
            'served from the record rather than by calling out',
        );
    }

    /**
     * The claim is written before the calls, not after them.
     *
     * Recording the result only afterwards left the whole probe inside the
     * window in which the limit reads as open, so concurrent hits all passed
     * the interval test together. Claiming first closes it: a probe still in
     * flight already looks recent to everybody else, and a worker that dies
     * mid-probe costs one interval of `unknown` rather than opening the gate.
     */
    public function testTheSlotIsClaimedBeforeTheCallsSoAProbeInFlightAlreadyLooksRecent(): void
    {
        $observedDuringTheCall = null;
        $reachability = $this->reachability(
            function () use (&$observedDuringTheCall): bool {
                $observedDuringTheCall = $this->reachability(
                    static fn (): never => throw new \LogicException('the limit was open during a probe'),
                    static fn (): never => throw new \LogicException('the limit was open during a probe'),
                    self::NOW + 5,
                )->probe();

                return true;
            },
            static fn (): bool => true,
        );

        $reachability->probe();

        self::assertIsArray($observedDuringTheCall);
        self::assertSame(Reachability::PROBE_RATE_LIMITED, $observedDuringTheCall['probe']);
    }

    /**
     * Takes write permission off the cache directory while leaving the path
     * readable, which is the state the silent write could not report.
     *
     * Skipped rather than asserted where the mode does not bite — a process
     * running as root ignores it — because a test that passes for that reason
     * is worse than one that does not run.
     */
    private function makeCacheDirectoryUnwritable(): void
    {
        $directory = dirname($this->cacheFile);
        mkdir($directory, 0770, true);
        chmod($directory, 0500);

        if (@file_put_contents($this->cacheFile, 'x') !== false) {
            self::markTestSkipped('This process can write to a directory it has no write permission on.');
        }
    }
}
