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
 * **The envelope is two keys and `message` changes type between them.** A
 * refusal is `{"result": "ERROR", "message": "<sentence>"}` — recorded, four
 * variants: "No products exist in the order", "Customer not found", "First and
 * last name are required fields.", "No orders matching those parameters could
 * be found". A success is `{"result": "SUCCESS", "message": {...}}` with the
 * data nested inside. So `message` is a string exactly when the call failed,
 * and that is load-bearing: {@see self::reason()} reads it only in the string
 * case, because `(string)` on the success array would produce the word
 * "Array" and show it to a buyer.
 *
 * **`result` is the decision, and unlike the other adapter's `success` flag it
 * can be trusted** — it is the provider's own field rather than something the
 * client derived from the absence of an error key. What it cannot do alone is
 * survive a body that never decoded: a proxy's HTML error page decodes to null
 * and has no `result` at all, which is why the check is for the literal
 * `SUCCESS` rather than for the absence of `ERROR`.
 *
 * **An order reference is still required on a success.** The recorded provider
 * for the other adapter creates an order and then fails the card, so `[13.26]`
 * is written around a reference surviving a decline; this provider is not known
 * to do that, and no reference is read from a refusal for that reason. What
 * stays the same is that a success without one cannot be recorded, reconciled
 * or captured, so it is a decline rather than a placement.
 *
 * **Unverified**: no order has been placed through this adapter, so the success
 * shape below — where the order id, the customer id and the charged total sit
 * inside `message` — is the provider's documented one rather than a recording.
 * The refusal shape is recorded. `docs/INTEGRATION-NOTES.md` says which is
 * which; a success path that is wrong fails as a missing reference, which is a
 * decline and not a charge nobody recorded.
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
    public static function from(
        array $envelope,
        ?int $expectedTotalCents = null,
        SettlementMode $settlement = SettlementMode::Capture,
    ): PlacementOutcome {
        // A transport failure arrives as a key, not an exception.
        if (isset($envelope['curlError'])) {
            return PlacementOutcome::declined(null, self::GENERIC_DECLINE, 'transport_error');
        }

        if (($envelope['result'] ?? null) !== self::RESULT_SUCCESS) {
            return PlacementOutcome::declined(null, self::reason($envelope), 'rejected');
        }

        $message = is_array($envelope['message'] ?? null) ? $envelope['message'] : [];
        $reference = self::identifier($message['orderId'] ?? null);

        // A success with nothing to file the order under cannot be recorded,
        // reconciled or captured, so it is treated as a decline rather than as
        // a placement nobody can find again.
        if ($reference === null) {
            return PlacementOutcome::declined(null, self::GENERIC_DECLINE, 'no_reference');
        }

        return PlacementOutcome::placed(
            $reference,
            self::identifier($message['orderStatus'] ?? null),
            self::reconcile($message, $expectedTotalCents),
            self::reusableCredential($message),
            $settlement,
        );
    }

    /**
     * The session this provider will bill an order against, or null when the
     * lead call did not answer one.
     *
     * Separate from {@see self::from()} because it reads a *different* call's
     * response: `POST /leads/import/` runs first and its only job is to hand
     * back this identifier.
     *
     * @param array<string, mixed> $envelope
     */
    public static function sessionFrom(array $envelope): ?string
    {
        if (isset($envelope['curlError']) || ($envelope['result'] ?? null) !== self::RESULT_SUCCESS) {
            return null;
        }

        $message = is_array($envelope['message'] ?? null) ? $envelope['message'] : [];

        return self::identifier($message['sessionId'] ?? null);
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
        $customerId = self::identifier($message['customerId'] ?? null);

        if ($customerId === null) {
            return null;
        }

        return PaymentCredential::stored(['customer_id' => $customerId]);
    }

    /** One of the provider's identifiers as a non-empty string, or null when it sent none. */
    private static function identifier(mixed $value): ?string
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
