<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

/**
 * A monitored figure, or the reason there isn't one.
 *
 * **Zero and "could not be counted" are different answers**, and a status
 * surface that renders them the same is worse than no surface at all: it
 * reports an all-clear on exactly the runs that could see nothing. This
 * codebase has shipped that defect once already — a reverse sweep printing "0
 * lost charges" and exiting 0 when the local read had failed outright — so
 * every count `[20.12]` asks for travels in this object rather than as a bare
 * int.
 *
 * {@see self::$state} carries the four answers a figure can have, because two
 * of them are neither a measurement nor a failure:
 *
 * - `measured` — counted, and complete.
 * - `floor` — counted, and known to be a lower bound. A truncated provider
 *   window is the case: the number is real and the total is larger.
 * - `not_checked` — nobody asked. The provider half needs an outbound call and
 *   is opt-in, so this is its ordinary state and must never read as zero.
 * - `unavailable` — asked, and not answered.
 *
 * The failure is named by exception class, never by message, for the reason
 * {@see \AsterMD\Storefront\Support\FailureDigest} gives: a driver quotes the
 * row it refused, and these rows hold clinical answers.
 */
final readonly class Measured
{
    public const string MEASURED = 'measured';

    public const string FLOOR = 'floor';

    public const string NOT_CHECKED = 'not_checked';

    public const string UNAVAILABLE = 'unavailable';

    private function __construct(
        public ?int $value,
        public string $state,
        public ?string $failure = null,
    ) {
    }

    public static function of(int $value): self
    {
        return new self($value, self::MEASURED);
    }

    /** A real count over an incomplete window: the total is at least this. */
    public static function floor(int $value): self
    {
        return new self($value, self::FLOOR);
    }

    public static function notChecked(): self
    {
        return new self(null, self::NOT_CHECKED);
    }

    public static function unavailable(string $failure): self
    {
        return new self(null, self::UNAVAILABLE, $failure);
    }

    /** Whether a caller may draw any conclusion from {@see self::$value}. */
    public function available(): bool
    {
        return $this->value !== null;
    }

    /**
     * Whether this figure is a complete answer.
     *
     * Separate from {@see self::available()} because a floor is a usable
     * number and still not an answer — the console fails a run on one for the
     * same reason the reverse sweep does.
     */
    public function complete(): bool
    {
        return $this->state === self::MEASURED;
    }

    public function render(): string
    {
        return match ($this->state) {
            self::MEASURED => (string) $this->value,
            self::FLOOR => 'at least ' . $this->value,
            self::NOT_CHECKED => 'not checked',
            default => 'unavailable (' . ($this->failure ?? 'unknown') . ')',
        };
    }

    /** @return array{value: ?int, state: string, failure: ?string} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'state' => $this->state, 'failure' => $this->failure];
    }
}
