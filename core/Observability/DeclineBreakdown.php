<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

/**
 * `[20.12]`'s decline rate, by reason.
 *
 * **A rate, not a count**, because the count alone answers nothing: fifty
 * declines is a bad day at one volume and a broken integration at another.
 * The denominator is every charge attempt in the window — declines plus
 * placements — read from the same trail as the numerator so the two cannot be
 * measured over different populations.
 *
 * **Null is not zero here either.** A window nobody could read reports
 * unavailable, and a window in which nothing was attempted reports no rate at
 * all rather than 0%, because "nobody tried" and "nobody was refused" are
 * different facts and only one of them is good news.
 */
final readonly class DeclineBreakdown
{
    /**
     * @param ?array<string, int> $byReason declines per reason, highest first; null when the window could not be read
     * @param ?int                $total    declines in the window
     * @param ?int                $attempts declines plus placements — every charge the provider was asked for
     * @param ?string             $failure  the exception class, when the window could not be read
     */
    private function __construct(
        public ?array $byReason,
        public ?int $total,
        public ?int $attempts,
        public ?string $failure = null,
    ) {
    }

    /** @param array<string, int> $byReason */
    public static function of(array $byReason, int $total, int $attempts): self
    {
        return new self($byReason, $total, $attempts);
    }

    public static function unavailable(string $failure): self
    {
        return new self(null, null, null, $failure);
    }

    public function available(): bool
    {
        return $this->byReason !== null;
    }

    /** Declines as a percentage of every charge attempted, or null when nothing was attempted or read. */
    public function rate(): ?float
    {
        if ($this->total === null || $this->attempts === null || $this->attempts === 0) {
            return null;
        }

        return round($this->total / $this->attempts * 100, 1);
    }

    /** @return array{by_reason: ?array<string, int>, total: ?int, attempts: ?int, rate_percent: ?float, failure: ?string} */
    public function toArray(): array
    {
        return [
            'by_reason' => $this->byReason,
            'total' => $this->total,
            'attempts' => $this->attempts,
            'rate_percent' => $this->rate(),
            'failure' => $this->failure,
        ];
    }
}
