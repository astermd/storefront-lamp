<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * One order as the provider holds it, in terms nothing above `[14.1]`'s
 * boundary has to translate.
 *
 * Deliberately thin. `[21.9a]`'s sweep asks one question -- "is there a local
 * row for this?" -- and everything here exists to answer it or to decide
 * whether the answer is worth an operator's attention. The adapter maps its
 * own projection onto these fields; the vocabulary never travels upward.
 *
 * **What is missing is recorded, not overlooked.** The provider's order search
 * carries no order total at all: the only monetary field in the projection is
 * the discount. So this object cannot say what a lost charge was worth, and
 * does not pretend to -- an operator investigating one goes to the provider's
 * own dashboard, which is the same place the `[21.9b]` user ruling of
 * 2026-08-25 already sends them.
 *
 * The identifier the storefront submits at placement (`session_id`) comes back
 * on neither the search nor the single-order read, and `connection_order_id`
 * is overwritten with the provider's own id on every one of 143 recorded
 * orders. There is therefore **no session to attribute an orphan to**, which
 * is why this object carries none: a field that could only ever be null would
 * read as an attribution that failed rather than one that was never possible.
 */
final readonly class ProviderOrder
{
    /**
     * @param string  $reference      the provider's own order identifier, as `orders.provider_reference` spells it
     * @param ?string $placedAt       the provider's `date_created`, verbatim and in its own format and clock
     * @param bool    $isTest         a sandbox order: real in the provider's records, never a real charge
     * @param bool    $isCharged      whether a card was actually charged; see {@see self::$rawStatus}
     * @param ?string $rawStatus      the provider's status vocabulary, for the operator line only
     * @param int     $discountCents  the only money in the projection, converted from the provider's decimal string
     */
    public function __construct(
        public string $reference,
        public ?string $placedAt,
        public bool $isTest,
        public bool $isCharged,
        public ?string $rawStatus,
        public int $discountCents,
    ) {
    }
}
