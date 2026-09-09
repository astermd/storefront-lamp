<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Support\FailureDigest;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * `[20.9]`: one external call, timed, with its outcome named.
 *
 * **This class may not change what happens.** It measures a call and returns
 * whatever the call returned; an exception passes through untouched so the
 * caller's own failure policy — swallow and log for analytics (`[20.2]`), stop
 * the buyer for payment (`[20.3]`) — is exactly what it was before the
 * instrumentation existed. Every line of bookkeeping here is wrapped in its
 * own catch for the same reason: `[20.1]` says tracking must never break the
 * storefront, and observability that can throw is tracking with a wider blast
 * radius than the thing it was watching.
 *
 * **Latency is measured with {@see hrtime()}, not {@see microtime()}.** It is
 * monotonic, so a clock adjustment mid-call cannot produce a negative duration
 * or a fifty-year one, and this figure exists to be alerted on.
 *
 * **What may go into the context, and what may not** (`[20.14]`). Outcome
 * codes drawn from this application's own vocabulary, counts, latency, and
 * identifiers this codebase issued are safe and are the point. Anything
 * composed by the EMR or the provider is not — a decline message, a driver
 * error, a form definition — for the reason {@see FailureDigest} states at
 * length: neither defence at the log sink can see inside a string somebody
 * else wrote about our data. A failure is therefore named by its exception
 * class, never by its message.
 */
final class BoundaryTimer
{
    /** @var \Closure(): int monotonic nanoseconds; injectable so a test can assert an exact latency */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(private readonly OperatorLog $log, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => hrtime(true);
    }

    /**
     * Runs `$call`, records what happened, and hands back its result.
     *
     * `$describe` turns the call's own return value into the outcome fields
     * for the log line, and it runs inside this class's error boundary: a
     * describer that throws costs the detail on one line and nothing else.
     * Its absence is recorded as `undescribed` rather than as `ok`, so a
     * boundary whose describer is broken cannot masquerade as a healthy one.
     *
     * @template T
     *
     * @param \Closure(): T                                   $call
     * @param (\Closure(T): array<string, scalar|null>)|null   $describe
     * @param array<string, scalar|null>                      $context static facts about this call, redacted at the sink
     *
     * @return T
     */
    public function measure(Boundary $boundary, \Closure $call, ?\Closure $describe = null, array $context = []): mixed
    {
        $started = ($this->clock)();

        try {
            $result = $call();
        } catch (\Throwable $e) {
            $this->record($boundary, 'error', $context + [
                'outcome' => 'exception',
                'latency_ms' => $this->elapsed($started),
                'failure' => $e::class,
                'sqlstate' => FailureDigest::sqlState($e),
            ]);

            throw $e;
        }

        $latency = $this->elapsed($started);
        $described = ['outcome' => 'undescribed'];

        if ($describe !== null) {
            try {
                $described = $describe($result);
            } catch (\Throwable) {
                // Deliberately swallowed: see the class docblock. The line is
                // still written, and it says the outcome could not be read
                // rather than claiming one.
            }
        } else {
            $described = ['outcome' => 'ok'];
        }

        $this->record($boundary, 'info', $context + $described + ['latency_ms' => $latency]);

        return $result;
    }

    /**
     * Writes the line, and cannot fail.
     *
     * {@see OperatorLog} already swallows an unwritable file, but it is not
     * the only thing between here and the disk — the correlation resolver and
     * the encoder sit in that path too — and this method is on the request
     * path of every external call the storefront makes.
     *
     * @param array<string, mixed> $context
     */
    private function record(Boundary $boundary, string $level, array $context): void
    {
        try {
            $event = 'external.' . $boundary->value;
            $context = array_filter($context, static fn (mixed $value): bool => $value !== null);

            match ($level) {
                'error' => $this->log->error($event, $context),
                default => $this->log->info($event, $context),
            };
        } catch (\Throwable) {
            // See the class docblock.
        }
    }

    /** Milliseconds to one decimal place: sub-millisecond calls are real and rounding them to zero hides them. */
    private function elapsed(int $started): float
    {
        return round((($this->clock)() - $started) / 1_000_000, 1);
    }
}
