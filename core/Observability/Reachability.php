<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

/**
 * `[20.15]`'s EMR and payment-provider reachability, without turning the
 * health endpoint into an outage of its own.
 *
 * **`/health/` is polled by load balancers.** A probe that called the EMR and
 * the payment provider on every hit would multiply the poll rate of every
 * monitor and every balancer in front of every worker into two external
 * systems — and would do it hardest at exactly the moment those systems were
 * already struggling. So this class answers two different questions:
 *
 * - {@see self::cached()} makes **no outbound call at all** and reports the
 *   last recorded result with its age. This is what an ordinary probe gets.
 * - {@see self::probe()} is the explicit opt-in, and is **rate-limited on top
 *   of being opt-in**: inside {@see self::$minimumIntervalSeconds} of the last
 *   real probe it serves the recorded result instead of calling. The endpoint
 *   is unauthenticated, so opt-in alone would leave anyone able to drive two
 *   external systems from a public URL.
 *
 * A never-probed deployment reports `unknown`, which is neither `ok` nor
 * `unreachable` — the same distinction {@see \AsterMD\Storefront\Payment\OrderSearchResult}
 * draws between an empty answer and no answer, and for the same reason: a
 * dependency nobody has asked about must not read as a healthy one.
 *
 * **The limit's only state is the cache file, so a probe that cannot write it
 * does not run.** This is the whole of {@see self::probe()}'s precondition and
 * it is not a tidiness rule. Reading an unwritable cache returns an empty
 * record forever; an empty record passes the interval test; so every hit on a
 * public unauthenticated URL called both dependencies, which is precisely the
 * amplification this class was written to prevent — twenty probes in one second
 * becoming twenty EMR and twenty provider calls against a limit of one per
 * thirty seconds. The slot is therefore **claimed before either call** and the
 * calls happen only if the claim landed. A limiter that cannot remember it ran
 * cannot promise it will not run again, and the honest answer to "is the EMR
 * reachable" is then `unknown` rather than a figure obtained by ignoring the
 * limit.
 *
 * **Refusing the probe, never the endpoint.** Every answer therefore carries a
 * `probe` field naming what this class did — {@see self::PROBE_NOT_ATTEMPTED},
 * {@see self::PROBE_RAN}, {@see self::PROBE_RATE_LIMITED},
 * {@see self::PROBE_REFUSED} — because a refusal that showed up only as
 * `unknown` would be indistinguishable from a dependency nobody has ever asked
 * about, and the two need different actions from an operator (a `chmod`, or
 * nothing). It cannot be a `checks` entry: dropping `/health/` to 503 over a
 * cache directory would pull every worker out of rotation at once and turn a
 * degraded probe into a site-wide outage, which is worse than the amplification
 * and is what `[20.1]` forbids. So it is reported beside the verdict, not
 * inside it.
 *
 * **A probe that throws is `unreachable`, and its message is dropped.** The
 * exception class is recorded and nothing else: a transport error names hosts,
 * URLs and occasionally credentials, and `/health/` is a public unauthenticated
 * document (`[20.14]`).
 */
final class Reachability
{
    private const string UNKNOWN = 'unknown';

    /**
     * What this class did, reported on every answer.
     *
     * Present even on the paths where it is uninteresting, so its absence never
     * has to be interpreted: an operator reading `unknown` gets the reason in
     * the same document rather than having to know which shapes carry one.
     *
     * `NOT_ATTEMPTED` is an ordinary hit — no probe was asked for, and no call
     * was made. `RAN` is a live probe that just happened. `RATE_LIMITED` is a
     * probe that was asked for and served from the record, which is the limit
     * working. `REFUSED` is a probe that was asked for and **did not run**,
     * because the limit's own state could not be written and nothing would have
     * stopped the next hit doing the same.
     */
    public const string PROBE_NOT_ATTEMPTED = 'not-attempted';
    public const string PROBE_RAN = 'ran';
    public const string PROBE_RATE_LIMITED = 'rate-limited';
    public const string PROBE_REFUSED = 'refused-rate-limit-unrecordable';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param \Closure(): bool $emr                     reaches the EMR the way `emr:ping` does, and answers whether it replied
     * @param \Closure(): bool $provider                asks the configured payment adapter whether its credentials reach the provider
     * @param string           $cacheFile               where a probe result is recorded so an ordinary probe can report it
     * @param int              $minimumIntervalSeconds  the floor between two real probes, whoever asks
     * @param (\Closure(): int)|null $clock             injectable so a test can age a result without waiting
     */
    public function __construct(
        private readonly \Closure $emr,
        private readonly \Closure $provider,
        private readonly string $cacheFile,
        private readonly int $minimumIntervalSeconds = 30,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The last recorded result, or `unknown`. Never calls out.
     *
     * @return array{probe: string, emr: array<string, mixed>, provider: array<string, mixed>}
     */
    public function cached(): array
    {
        return $this->render($this->read(), self::PROBE_NOT_ATTEMPTED);
    }

    /**
     * Probes both dependencies, unless one ran recently enough — or unless the
     * limit cannot be recorded, in which case it does not probe at all.
     *
     * **The claim is written before the calls.** Recording the result only
     * afterwards meant the whole probe sat inside the window where the limit
     * still read as open, so concurrent hits all passed the interval test
     * together and the floor held only against traffic slow enough to
     * self-serialise. Claiming first closes that: a probe in flight already
     * looks recent to everybody else. The cost is that a worker killed
     * mid-probe leaves a claim with no result behind it, which reports
     * `unknown` for one interval — the fail-closed direction, and the right one
     * for a guard whose failure mode is flooding two external systems.
     *
     * And it doubles as the proof the limit is enforceable at all: if the claim
     * cannot be written then nothing recorded would stop the next hit either,
     * so the probe is refused rather than run unbounded.
     *
     * @return array{probe: string, emr: array<string, mixed>, provider: array<string, mixed>}
     */
    public function probe(): array
    {
        $recorded = $this->read();
        $checkedAt = $recorded['checked_at'] ?? null;
        $now = ($this->clock)();

        if (is_int($checkedAt) && $now - $checkedAt < $this->minimumIntervalSeconds) {
            return $this->render($recorded, self::PROBE_RATE_LIMITED);
        }

        if (!$this->write(['checked_at' => $now])) {
            return $this->render($recorded, self::PROBE_REFUSED);
        }

        $result = [
            'checked_at' => $now,
            'emr' => $this->run($this->emr),
            'provider' => $this->run($this->provider),
        ];

        $this->write($result);

        return $this->render($result, self::PROBE_RAN);
    }

    /**
     * One dependency, timed, with every failure flattened to "unreachable".
     *
     * `hrtime()` for the same reason {@see BoundaryTimer} uses it: the figure
     * is meant to be compared across probes, and a wall clock that steps
     * backwards would produce a negative one.
     *
     * @param \Closure(): bool $call
     *
     * @return array{status: string, latency_ms: float, failure: ?string}
     */
    private function run(\Closure $call): array
    {
        $started = hrtime(true);

        try {
            $ok = $call();
            $failure = null;
        } catch (\Throwable $e) {
            $ok = false;
            $failure = $e::class;
        }

        return [
            'status' => $ok ? 'ok' : 'unreachable',
            'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
            'failure' => $failure,
        ];
    }

    /**
     * @param array<string, mixed> $recorded
     * @param string               $probe    one of the `PROBE_*` constants: what this class did to obtain $recorded
     *
     * @return array{probe: string, emr: array<string, mixed>, provider: array<string, mixed>}
     */
    private function render(array $recorded, string $probe): array
    {
        $checkedAt = $recorded['checked_at'] ?? null;
        $checkedAt = is_int($checkedAt) ? $checkedAt : null;

        return [
            'probe' => $probe,
            'emr' => $this->dependency($recorded['emr'] ?? null, $checkedAt),
            'provider' => $this->dependency($recorded['provider'] ?? null, $checkedAt),
        ];
    }

    /** @return array{status: string, latency_ms: ?float, failure: ?string, checked_at: ?string, age_seconds: ?int} */
    private function dependency(mixed $recorded, ?int $checkedAt): array
    {
        if (!is_array($recorded) || !is_string($recorded['status'] ?? null) || $checkedAt === null) {
            return [
                'status' => self::UNKNOWN,
                'latency_ms' => null,
                'failure' => null,
                'checked_at' => null,
                'age_seconds' => null,
            ];
        }

        return [
            'status' => $recorded['status'],
            'latency_ms' => is_numeric($recorded['latency_ms'] ?? null) ? (float) $recorded['latency_ms'] : null,
            'failure' => is_string($recorded['failure'] ?? null) ? $recorded['failure'] : null,
            'checked_at' => gmdate('c', $checkedAt),
            // Never negative: a cache file written by a host whose clock is
            // ahead would otherwise report an age from the future, which reads
            // as a bug in this endpoint rather than in that host.
            'age_seconds' => max(0, ($this->clock)() - $checkedAt),
        ];
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->cacheFile)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($this->cacheFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Records the state, and reports whether it landed.
     *
     * Still silent — an unwritable cache must not raise on the endpoint whose
     * job is to say whether the application is up (`[20.1]`) — but no longer
     * *unobserved*. This used to return void, on the reasoning that a
     * deployment which cannot remember the answer merely reports `unknown` and
     * that was honest degradation. It was not: this file is the only state the
     * rate limit has, so a failure here did not degrade the probe, it removed
     * the limit, and {@see self::probe()} is the caller that has to know.
     *
     * @param array<string, mixed> $result
     *
     * @return bool whether the state is now on disk
     */
    private function write(array $result): bool
    {
        $directory = dirname($this->cacheFile);

        if (!is_dir($directory) && !@mkdir($directory, 0o770, true) && !is_dir($directory)) {
            return false;
        }

        return @file_put_contents($this->cacheFile, (string) json_encode($result, JSON_UNESCAPED_SLASHES)) !== false;
    }
}
