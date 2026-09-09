<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * Whatever the active adapter needs in order to charge (`[15.13]`).
 *
 * The funnel never inspects this: it is a card, a token, a prior-order
 * reference, or a handle naming an instrument the provider already holds, and
 * which one it is depends entirely on the adapter's declared reuse strategy.
 * `card()` is the only constructor that ever holds a primary account number.
 *
 * A `stored()` handle is the shape a `[15.3]` reference-order provider issues,
 * and it is deliberately structureless here: the adapter mints it from its own
 * response and reads it back, and nothing between those two points is entitled
 * to know what is inside it (`[15.13]`).
 *
 * **What is protected, exactly** (`[15.8]`). Four of PHP's five ways of
 * turning an object into text are intercepted here, and the fifth cannot be:
 *
 * - `var_dump` and `print_r` go through `__debugInfo()`;
 * - `json_encode` goes through `jsonSerialize()` -- without it, an encoder
 *   walking any structure that happens to hold a credential reads the public
 *   readonly properties directly;
 * - `serialize` goes through `__serialize()`;
 * - **`var_export` is not intercepted.** A plain object has no hook for it;
 *   it emits every property, card number included. Nothing in the storefront
 *   calls it on a credential, and nothing should.
 *
 * A card is never held beyond the request that collected it in any case, and
 * {@see self::toStorable()} makes that structural rather than conventional:
 * the only credentials that can be written durably are the ones with no card
 * in them (`[15.8]`, `[15.12]`).
 */
final class PaymentCredential implements \JsonSerializable
{
    public const string KIND_CARD = 'card';

    public const string KIND_TOKEN = 'token';

    public const string KIND_ORDER_REFERENCE = 'order_reference';

    public const string KIND_STORED_INSTRUMENT = 'stored_instrument';

    /**
     * @param array<string, string> $handle empty unless this is a stored instrument
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $number = '',
        public readonly string $expiryMonth = '',
        public readonly string $expiryYear = '',
        public readonly string $securityCode = '',
        public readonly string $reference = '',
        public readonly array $handle = [],
    ) {
    }

    public static function card(string $number, string $expiryMonth, string $expiryYear, string $securityCode): self
    {
        return new self(
            self::KIND_CARD,
            number: preg_replace('/\D/', '', $number) ?? '',
            expiryMonth: $expiryMonth,
            expiryYear: $expiryYear,
            securityCode: $securityCode,
        );
    }

    public static function token(string $reference): self
    {
        return new self(self::KIND_TOKEN, reference: $reference);
    }

    public static function orderReference(string $reference): self
    {
        return new self(self::KIND_ORDER_REFERENCE, reference: $reference);
    }

    /**
     * An instrument the provider holds in its own vault, named by whatever
     * handle that provider issues (`[15.3]`, `[15.9]`).
     *
     * The handle is a map rather than a string because the recorded provider's
     * is a **pair** -- a customer and one of that customer's cards -- and it
     * refuses either half alone. A single string could hold only one of them,
     * and the shape of a vault handle is not the funnel's business in any case
     * (`[15.13]`): the adapter mints it from its own response and the adapter
     * is the only thing that reads it back.
     *
     * @param array<string, string> $handle opaque to everything but the adapter that minted it
     */
    public static function stored(array $handle): self
    {
        $opaque = [];
        foreach ($handle as $key => $value) {
            $opaque[(string) $key] = (string) $value;
        }

        return new self(self::KIND_STORED_INSTRUMENT, handle: $opaque);
    }

    /** The last four digits, for a receipt. Never more than four. */
    public function lastFour(): string
    {
        return $this->kind === self::KIND_CARD ? substr($this->number, -4) : '';
    }

    /** Whether this credential can be used again after the request that collected it. */
    public function isReusable(): bool
    {
        return $this->kind === self::KIND_STORED_INSTRUMENT && $this->handle !== [];
    }

    /**
     * The credential in a shape journey state may persist (`[15.12]`).
     *
     * **Throws for a card, and that is the point.** Journey state is written to
     * the `sessions` table, so a card here is a card at rest in the database,
     * which `[15.8]` forbids outright. Making the refusal structural means a
     * future caller cannot persist one by accident, and cannot persist one on
     * purpose without deleting this guard and explaining why.
     *
     * @return array{kind: string, handle: array<string, string>}
     */
    public function toStorable(): array
    {
        if ($this->kind === self::KIND_CARD) {
            throw new \LogicException('A card credential is never persisted: [15.8] forbids writing one durably.');
        }

        return ['kind' => $this->kind, 'handle' => $this->handle];
    }

    /**
     * A persisted credential, or null when what was stored is not one.
     *
     * Null rather than an exception because the caller is reading a JSON column
     * written by some previous release: a shape that no longer parses must
     * degrade to "this journey has no reusable credential", which costs the
     * buyer an upsell offer, rather than taking down the receipt page that was
     * asking.
     *
     * A stored `KIND_CARD` is refused for the same reason {@see self::toStorable()}
     * refuses to write one -- if one is ever found in a column, the safe reading
     * is that it is not usable, not that the guard should be honoured in one
     * direction only.
     *
     * **Only a stored instrument round-trips, and a token deliberately does
     * not.** {@see self::token()} populates `$reference` rather than `$handle`,
     * so a tokenised credential converts to a shape this method would have to
     * read back inside out -- and one recovered that way would answer
     * {@see self::isReusable()} with false, which is the opposite of what a
     * token is for. Rather than carry a branch that cannot survive its own
     * round trip, there is none: an adapter declaring tokenisation (`[15.1]`)
     * extends both halves together, which is the only way the pair stays
     * honest.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromStorable(array $stored): ?self
    {
        if (($stored['kind'] ?? null) !== self::KIND_STORED_INSTRUMENT) {
            return null;
        }

        $handle = $stored['handle'] ?? null;

        return is_array($handle) && $handle !== [] ? self::stored($handle) : null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'number' => '[REDACTED]', 'handle' => '[REDACTED]'];
    }

    /**
     * The same redacted shape for any encoder.
     *
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Refuses to rebuild a credential from a serialised one.
     *
     * The alternative is restoring the redacted shape above, which produces an
     * object that reports `KIND_CARD`, answers `lastFour()` with nothing, and
     * is charged with `[REDACTED]` as its number -- a failure that surfaces at
     * the provider, one layer past anything that could explain it. Nothing in
     * the storefront serialises a credential (journey state holds its own
     * array), so the only caller this can have is a mistake, and a mistake is
     * better told than accommodated.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A PaymentCredential cannot be restored from a serialised copy: the card is deliberately not in it.');
    }
}
