<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\ChargeDiscrepancy;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\SettlementMode;
use AsterMD\Storefront\Support\CardScrubber;

/**
 * Capability 5: the provider's response envelope → an outcome (`[13.24]`).
 *
 * **`result` is the only discriminator, and `message` is not one.** The obvious
 * reading -- string means failure, object means success -- is wrong in both
 * directions, and all four combinations are recorded:
 *
 * | `result`  | `message` | recorded example |
 * |-----------|-----------|------------------|
 * | `SUCCESS` | object    | a billed order, with `orderId`, `orderStatus`, `totalAmount` |
 * | `SUCCESS` | string    | `"Card is preauthorized"` |
 * | `ERROR`   | string    | `"Transaction Declined: Card Declined"` |
 * | `ERROR`   | object    | `{"shipAddress1": "is a required field", ...}` |
 *
 * So the verdict is read from `result` alone, and `message` is inspected for
 * its type before anything is taken out of it. Casting the object form would
 * render the word "Array" to a buyer; requiring the object form would throw
 * away the pre-authorization's answer entirely.
 *
 * `result` is the provider's own field rather than something the client derived
 * from the absence of an error key, so it can be trusted where the other
 * adapter's `success` flag cannot. What it cannot do alone is survive a body
 * that never decoded -- a proxy's HTML error page has no `result` at all --
 * which is why the check is for the literal `SUCCESS` rather than for the
 * absence of `ERROR`.
 *
 * **The order reference is minted by the lead call, not by the billing call.**
 * `POST /leads/import/` creates a PARTIAL order and answers its `orderId`;
 * everything afterwards is keyed on that. The adapter therefore already holds
 * the reference before it presents a card, which is what lets a *refused*
 * placement still carry one (`[13.26]`) even though the refusal envelope has no
 * order id in it at all.
 *
 * A pre-authorization answers `SUCCESS` with no `orderId` and no total, so it is
 * read as "the card was accepted" and the reference comes from the caller.
 */
final class CheckoutChampOutcome
{
    public const string GENERIC_DECLINE = 'We could not complete your payment. Please check your card details and try again.';

    /** The provider's own verdict field, and the only value that means success. */
    private const string RESULT_SUCCESS = 'SUCCESS';

    /**
     * @param array<string, mixed> $envelope           the `response` node of the client's `getInArray()`
     * @param int|null             $expectedTotalCents the total the storefront displayed, for reconciliation
     */
    /**
     * @param array<string, mixed> $envelope   the `response` node of the client's `getInArray()`
     * @param string               $reference  the order id the lead call minted, since the billing
     *                                         call does not always answer one and a refusal never does
     * @param bool                 $preAuthQa  whether this placement held the order amount through
     *                                         the QA mechanism rather than charging or pre-authorizing
     */
    public static function from(
        array $envelope,
        string $reference,
        ?int $expectedTotalCents = null,
        SettlementMode $settlement = SettlementMode::Capture,
        bool $preAuthQa = false,
    ): PlacementOutcome {
        // A transport failure arrives as a key, not an exception.
        if (isset($envelope['curlError'])) {
            return PlacementOutcome::declined($reference, self::GENERIC_DECLINE, 'transport_error');
        }

        // The reference is carried onto the decline (`[13.26]`): the provider
        // created the order at the lead call and it is still there, so a retry
        // that forgot it would leave an operator two partial orders and no way
        // to tell which one the buyer saw.
        if (($envelope['result'] ?? null) !== self::RESULT_SUCCESS) {
            return PlacementOutcome::declined($reference, self::reason($envelope), 'rejected');
        }

        $message = is_array($envelope['message'] ?? null) ? $envelope['message'] : [];

        return PlacementOutcome::placed(
            $reference,
            self::text($message['orderStatus'] ?? null),
            self::reconcile($message, $expectedTotalCents),
            self::reusableCredential($message),
            $settlement,
            $preAuthQa,
            // The provider's own reading of the card, which is why it is worth
            // carrying: it arrives beside a bin and an expiry the storefront
            // never sent in that form, so it is not an echo of what was posted.
            CheckoutChampCardType::brandFor($message['cardType'] ?? null),
        );
    }

    /**
     * Whether a settle call took the money.
     *
     * The same envelope rules as a placement, read for a different question. A
     * settled order answers `SUCCESS` with `orderStatus: "COMPLETE"`; a settle
     * call missing its lines answers `ERROR` with "No products exist in the
     * order" and leaves the order PARTIAL with the funds still held.
     *
     * @param array<string, mixed> $envelope
     */
    public static function capturedFrom(array $envelope): bool
    {
        return !isset($envelope['curlError']) && ($envelope['result'] ?? null) === self::RESULT_SUCCESS;
    }

    /**
     * The order the lead call created, or null when it created none.
     *
     * Separate from {@see self::from()} because it reads a *different* call's
     * response. `POST /leads/import/` creates a PARTIAL order and answers its
     * `orderId`, which every later call is keyed on -- so this runs before a
     * card has been presented to anything, and a failure here costs the buyer a
     * decline and nothing else.
     *
     * @param array<string, mixed> $envelope
     */
    public static function referenceFrom(array $envelope): ?string
    {
        if (isset($envelope['curlError']) || ($envelope['result'] ?? null) !== self::RESULT_SUCCESS) {
            return null;
        }

        $message = is_array($envelope['message'] ?? null) ? $envelope['message'] : [];

        return self::text($message['orderId'] ?? null);
    }

    /**
     * The buyer-safe reason for a refusal, shown verbatim (`[13.28]`).
     *
     * Read only when `message` is a string, which is exactly the failure case;
     * on a success it is the data object and casting it would render "Array".
     * Scrubbed at the point of extraction, on the same terms as the other
     * adapter: this text is both shown to the buyer and persisted against the
     * order, and nothing constrains what a gateway writes into it.
     *
     * @param array<string, mixed> $envelope
     */
    private static function reason(array $envelope): string
    {
        $message = $envelope['message'] ?? null;

        if (is_string($message) && trim($message) !== '') {
            return (string) CardScrubber::scrub(trim($message));
        }

        // The field-map form -- `{"shipAddress1": "is a required field"}` -- is
        // a fault in what this storefront sent, not something a buyer can act
        // on, so it is deliberately not rendered. It reaches the operator log
        // through the adapter, where it is the only useful thing about the
        // failure.
        return self::GENERIC_DECLINE;
    }

    /**
     * What the provider charged against what the storefront displayed.
     *
     * Only a placement is reconciled: a decline took no money, so reporting its
     * attempted figure as a discrepancy would send an operator after a charge
     * that does not exist. A response with no usable total is left unreconciled
     * for the same reason — an unmeasured total is not a mismatch.
     *
     * @param array<string, mixed> $message
     */
    private static function reconcile(array $message, ?int $expectedTotalCents): ?ChargeDiscrepancy
    {
        if ($expectedTotalCents === null) {
            return null;
        }

        $chargedCents = self::cents($message['totalAmount'] ?? null);

        if ($chargedCents === null || $chargedCents === $expectedTotalCents) {
            return null;
        }

        return new ChargeDiscrepancy(
            expectedCents: $expectedTotalCents,
            chargedCents: $chargedCents,
            providerDiscountCents: self::cents($message['discountAmount'] ?? null),
        );
    }

    /**
     * The handle a later charge on this journey can reuse (`[15.13]`).
     *
     * One identifier rather than the other adapter's pair, because this
     * provider bills a stored instrument by naming the customer it already
     * holds. Minted here from the provider's own response and never accepted
     * from a request, for the same reason: this class is the only thing that
     * knows how this provider spells a stored instrument.
     *
     * @param array<string, mixed> $message
     */
    private static function reusableCredential(array $message): ?PaymentCredential
    {
        $customerId = self::text($message['customerId'] ?? null);

        if ($customerId === null) {
            return null;
        }

        return PaymentCredential::stored(['customer_id' => $customerId]);
    }

    /** One of the provider's identifiers as a non-empty string, or null when it sent none. */
    private static function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** One of the provider's decimal-string amounts as cents, or null when it sent none. */
    private static function cents(mixed $amount): ?int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : null;
    }
}
