<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Payment\ChargeDiscrepancy;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Support\CardScrubber;

/**
 * The record of one checkout submission and what came of it.
 *
 * **Three states, because two cannot answer the question that matters.** An
 * attempt is `claimed` before anything is sent, `sent` the instant before the
 * provider is contacted, and `complete` once an outcome is known. A duplicate
 * arriving on a claimed row has found a request that demonstrably never
 * reached the provider; one arriving on a sent row has found a request that
 * may already have charged the card. Only the first may ever be taken over --
 * the provider has no idempotency of its own, and re-posting an identical
 * payload creates and charges a second order.
 *
 * The state names are {@see CheckoutAttemptRepository}'s own constants rather
 * than copies declared here. The repository writes them and this class reads
 * them, and two spellings of one state is exactly how a guard starts agreeing
 * with itself about the wrong thing.
 *
 * The stored outcome is encoded and decoded here rather than in the
 * repository, because its shape belongs to {@see PlacementOutcome} and the
 * table only ever holds it as an opaque blob to be replayed verbatim to
 * whoever lost the race.
 */
final class CheckoutAttempt
{
    /**
     * The `rawStatus` values that mean nobody can say whether money moved.
     *
     * All three describe a call that was made and not answered — a transport
     * failure, a thrown client, a body with no reference in it. Releasing the
     * key on any of them would let an identical payload be posted to a gateway
     * that would create and charge a second order. Every other decline was
     * answered by the provider itself, which means it took nothing.
     *
     * **The test is "could this have charged the card", not "did the charge
     * fail".** A refusal raised before any gateway was reached belongs with the
     * provider-answered declines, however unlike one it reads: it releases the
     * key, so the submission stays retryable and the alert that means *money
     * may be missing* is not fired for it. `no_provider_configured` is that
     * case — {@see \AsterMD\Storefront\Payment\NullPaymentAdapter} is the only
     * thing that emits it, and it exists precisely because there is no
     * gateway, so no card can have been presented. Keeping it here told the
     * buyer their card might have been charged when it provably was not, left
     * the row `sent` forever with nothing to expire it (making that cart
     * permanently unpayable), and fired the unresolved-charge alert on every
     * first submit of an unconfigured deployment. `no_chargeable_lines`, the
     * same category of refusal, has always been absent from this list.
     */
    private const array UNRESOLVED_STATUSES = [
        'transport_error',
        'exception',
        'no_reference',
    ];

    public function __construct(
        public readonly string $key,
        public readonly string $state,
        public readonly ?PlacementOutcome $outcome,
    ) {
    }

    /**
     * The attempt as {@see CheckoutAttemptRepository::outcomeFor()} returns it.
     *
     * @param array{key: string, state: string, outcome: array<string, mixed>|null, ...} $row
     */
    public static function fromRow(array $row): self
    {
        $outcome = $row['outcome'] ?? null;

        return new self(
            (string) $row['key'],
            (string) $row['state'],
            is_array($outcome) ? self::decodeOutcome($outcome) : null,
        );
    }

    /** The key is held but the provider has not been contacted: this is the only state a stale claim may be taken from. */
    public function isClaimed(): bool
    {
        return $this->state === CheckoutAttemptRepository::STATE_CLAIMED;
    }

    /**
     * The provider has been contacted and has not been heard from.
     *
     * A duplicate on this row is told its payment is being confirmed, and is
     * never re-sent however old the row is: an unanswered call is not the same
     * as a call that did not happen, and only one of those is safe to repeat.
     */
    public function isSent(): bool
    {
        return $this->state === CheckoutAttemptRepository::STATE_SENT;
    }

    /** The provider answered, and {@see self::$outcome} is what the duplicate is owed. */
    public function isComplete(): bool
    {
        return $this->state === CheckoutAttemptRepository::STATE_COMPLETE;
    }

    /**
     * Whether this outcome entitles the submission to its key back.
     *
     * Only a decline the provider itself answered with. The key is derived
     * from the cart and the buyer and deliberately excludes the card
     * (`[15.8]`), so a buyer whose card was refused derives the *same* key on
     * the retry: keeping the key would replay the stored decline and the
     * second card would never reach the provider at all. A placement and a
     * challenge both leave an order in existence at the provider and keep the
     * key forever; an unresolved outcome keeps it because nobody can say the
     * card was not charged.
     */
    public static function releasesKey(PlacementOutcome $outcome): bool
    {
        if ($outcome->state !== PlacementOutcome::DECLINED) {
            return false;
        }

        return !in_array($outcome->rawStatus, self::UNRESOLVED_STATUSES, true);
    }

    /**
     * @return array{state: string, reference: ?string, reason: ?string, raw_status: ?string, action_url: ?string, charge_discrepancy: array{expected_cents: int, charged_cents: int, provider_discount_cents: ?int}|null}
     */
    public static function encodeOutcome(PlacementOutcome $outcome): array
    {
        $discrepancy = $outcome->chargeDiscrepancy;

        return [
            'state' => $outcome->state,
            'reference' => $outcome->reference,
            // The reason can fall through to gateway free text, and this is
            // the last thing standing between that text and a durable column.
            // The adapter scrubs on the way in; a future adapter that forgets
            // must not be able to put a card number at rest (`[15.8]`).
            'reason' => $outcome->reason === null ? null : (string) CardScrubber::scrub($outcome->reason),
            'raw_status' => $outcome->rawStatus,
            // Carried so a further-action outcome can be rebuilt. Without it
            // the duplicate of a pending-action submit reads a completed
            // attempt holding nothing, and the only way to answer would be to
            // let it reach the provider again -- for an order the provider has
            // already created, against a gateway with no idempotency of its
            // own (`[13.37]`).
            //
            // Scrubbed for the same reason the reason field above is, and it
            // was the last provider free-text field reaching a durable column
            // without it: a redirect is built by the gateway, and nothing
            // constrains what it puts in a query string -- including the number
            // it was just handed (`[15.8]`).
            'action_url' => $outcome->actionUrl === null ? null : (string) CardScrubber::scrub($outcome->actionUrl),
            // Carried because this blob is replayed *verbatim*: a stored copy
            // that quietly dropped the one field saying the charged total did
            // not match what the buyer agreed to would be a durable record
            // asserting something untrue about the money.
            'charge_discrepancy' => $discrepancy === null ? null : [
                'expected_cents' => $discrepancy->expectedCents,
                'charged_cents' => $discrepancy->chargedCents,
                'provider_discount_cents' => $discrepancy->providerDiscountCents,
            ],
        ];
    }

    /** @param array<string, mixed> $stored */
    public static function decodeOutcome(array $stored): ?PlacementOutcome
    {
        $state = $stored['state'] ?? null;
        $reference = is_string($stored['reference'] ?? null) ? $stored['reference'] : null;
        $rawStatus = is_string($stored['raw_status'] ?? null) ? $stored['raw_status'] : null;
        $actionUrl = is_string($stored['action_url'] ?? null) ? $stored['action_url'] : null;

        return match ($state) {
            PlacementOutcome::PLACED => $reference === null
                ? null
                : PlacementOutcome::placed($reference, $rawStatus, self::decodeDiscrepancy($stored['charge_discrepancy'] ?? null)),
            PlacementOutcome::DECLINED => PlacementOutcome::declined($reference, (string) ($stored['reason'] ?? ''), $rawStatus),
            PlacementOutcome::PENDING_ACTION => $reference === null || $actionUrl === null
                ? null
                : PlacementOutcome::pendingAction($reference, $actionUrl, $rawStatus),
            default => null,
        };
    }

    /**
     * A stored mismatch, or null when there was none — and null too when the
     * stored shape is not the one written above, because an unreadable figure
     * is not evidence of a discrepancy.
     */
    private static function decodeDiscrepancy(mixed $stored): ?ChargeDiscrepancy
    {
        if (!is_array($stored) || !is_int($stored['expected_cents'] ?? null) || !is_int($stored['charged_cents'] ?? null)) {
            return null;
        }

        $providerDiscount = $stored['provider_discount_cents'] ?? null;

        return new ChargeDiscrepancy(
            expectedCents: $stored['expected_cents'],
            chargedCents: $stored['charged_cents'],
            providerDiscountCents: is_int($providerDiscount) ? $providerDiscount : null,
        );
    }
}
