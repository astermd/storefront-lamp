<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

use AsterMD\Storefront\Payment\ChargeDiscrepancy;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Support\CardScrubber;

/**
 * Capability 5: the provider's response envelope → an outcome (`[13.24]`).
 *
 * Three recorded facts shape every branch here.
 *
 * **The reference lives at a different path on success and on failure**
 * (`[13.27]`). A success puts the transaction at `data`; a failure moves it to
 * `data.error.transaction`. Both carry `order_id`, and the failing one carries
 * it because the provider creates the order and *then* fails the card
 * (`[13.26]`).
 *
 * **A null order status means "never charged", not "placed"**. `[14.10]` says
 * the opposite -- "any other status, or no status at all, counts as placed" --
 * but across 1,400 real orders on this account a null status is an order
 * container with `date_ordered`, `date_authorized` and `date_capture` all
 * null, and it is 37% of them. The spec's rule would send those buyers to a
 * receipt.
 *
 * **The envelope's own `success` flag cannot be trusted alone.** The client
 * derives it from the absence of an `error` key in the decoded body, so a body
 * that is not JSON at all -- an HTML error page from a proxy -- decodes to
 * null and reads as a success with no data. Requiring an order reference is
 * what makes that a decline. `response_code` cannot carry the decision either:
 * it was 100 on the recorded approval and **200** on the recorded decline.
 *
 * **An approval is reconciled, not taken on trust.** The response says what it
 * charged, in `transaction_total`, and until that figure was compared with the
 * total the storefront displayed, a charge for a number the buyer never agreed
 * to arrived here as a plain success. The comparison comes back as a
 * {@see ChargeDiscrepancy} on a *placed* outcome, because by the time it can
 * be measured the card has already been debited.
 *
 * **A placement hands back the handle a later charge can reuse.** Both halves
 * of it are on the placement response itself, so a reference-order charge
 * (`[15.3]`) costs no second read. Minting it here is what keeps `[15.13]`'s
 * promise that the flow asks the adapter for a handle and never inspects one:
 * this class is the only thing that knows the provider spells it as a customer
 * and one of that customer's cards.
 *
 * **A handle the provider will not accept is its own status, not a decline
 * like any other.** That refusal is answered before any gateway is reached and
 * creates no order, so it must not be read as a call that went unanswered
 * ({@see self::CREDENTIAL_REFUSED}).
 *
 * **The free text this class hands upwards is scrubbed.** The reason paths fall
 * through to `gateway_response_text`, `processor_response_text` and
 * `gateway_response_description` -- gateway free text, rendered to the buyer
 * and persisted against the order. Nothing constrains what a gateway writes
 * there, including the number it was just handed.
 */
final class VrioOutcome
{
    public const string GENERIC_DECLINE = 'We could not complete your payment. Please check your card details and try again.';

    /**
     * The buyer-safe reason for a refused vault handle.
     *
     * The provider's own text is refused here rather than shown, which is the
     * one deliberate exception to `[13.28]`'s show-it-verbatim rule. It reads
     * "Customer Card ID does not belong to Customer", which tells a buyer
     * nothing and names an internal identifier of theirs. The buyer's original
     * order is genuinely unaffected, so saying so is both kinder and truer.
     */
    public const string STORED_CREDENTIAL_DECLINE = 'We could not charge your saved card. Your original order is unaffected.';

    /**
     * The status for a vault handle the provider would not accept.
     *
     * Its own status rather than `no_reference`, and the distinction decides
     * whether an alert fires. A refusal of this shape carries
     * `success: false`, no order id and **no transaction node at all** -- the
     * provider validated the request and created nothing, so no card was
     * presented and no charge can be outstanding. Read as `no_reference` it
     * would join {@see \AsterMD\Storefront\Checkout\CheckoutAttempt}'s
     * unresolved set, keep the idempotency key forever and fire the
     * money-may-be-missing alert at error level for a call that provably cost
     * nothing.
     */
    public const string CREDENTIAL_REFUSED = 'credential_refused';

    /** The provider's error code for a handle it will not accept, whichever half is wrong. */
    private const string REFUSED_HANDLE_CODE = 'customer_card_invalid';

    /** Order statuses that are terminal: none of them is a charge that stands. */
    private const array TERMINAL_STATUSES = [2, 5, 7, 8];

    /** Buyer-safe reason paths, in the order `[13.28]` asks for them to be tried. */
    private const array REASON_PATHS = [
        ['message'],
        ['data', 'error', 'message'],
        ['data', 'error', 'transaction', 'gateway_response_text'],
        ['data', 'error', 'transaction', 'processor_response_text'],
        ['data', 'error', 'transaction', 'gateway_response_description'],
    ];

    /**
     * @param array<string, mixed> $envelope           the `response` node of the client's `getInArray()`
     * @param int|null             $expectedTotalCents the total the storefront displayed, for reconciliation; null
     *                                                 when the caller has nothing to reconcile against, which
     *                                                 leaves the outcome unreconciled rather than reporting a
     *                                                 mismatch nobody measured
     */
    public static function from(array $envelope, ?int $expectedTotalCents = null): PlacementOutcome
    {
        // A transport failure arrives as a key, not an exception.
        if (isset($envelope['curlError'])) {
            return PlacementOutcome::declined(null, self::GENERIC_DECLINE, 'transport_error');
        }

        // A refused vault handle, before the missing-reference branch below
        // could read it as a call that was never answered. Three recorded
        // variants -- a crossed pair, an unknown card, an unknown customer --
        // all arrive with this code, no order id and no transaction node.
        if (($envelope['data']['error']['code'] ?? null) === self::REFUSED_HANDLE_CODE) {
            return PlacementOutcome::declined(null, self::STORED_CREDENTIAL_DECLINE, self::CREDENTIAL_REFUSED);
        }

        $transaction = self::transaction($envelope);
        $reference = self::reference($transaction);

        // A further-action response is neither placed nor declined
        // (`[14.22]`): the buyer has a challenge to complete.
        $responseCode = $transaction['response_code'] ?? null;
        if ($responseCode === 101 && $reference !== null) {
            $redirect = $transaction['post_data'] ?? null;
            if (is_string($redirect) && $redirect !== '') {
                return PlacementOutcome::pendingAction($reference, $redirect, '101');
            }
        }

        // A missing reference is a failed placement, not an exception
        // (`[13.25]`), and it is also what catches a body that never decoded.
        if ($reference === null) {
            return PlacementOutcome::declined(null, self::reason($envelope), 'no_reference');
        }

        $status = self::orderStatus($transaction);
        $rawStatus = $status === null ? null : (string) $status;

        if (($envelope['success'] ?? false) !== true) {
            return PlacementOutcome::declined($reference, self::reason($envelope), $rawStatus);
        }

        if ($status === null || in_array($status, self::TERMINAL_STATUSES, true)) {
            return PlacementOutcome::declined($reference, self::reason($envelope), $rawStatus);
        }

        return PlacementOutcome::placed(
            $reference,
            $rawStatus,
            self::reconcile($transaction, $expectedTotalCents),
            self::reusableCredential($envelope),
        );
    }

    /**
     * The vault handle this placement left behind, or null when it left none.
     *
     * **The pair, or nothing.** The provider refuses a card id whose customer
     * does not own it, so half a handle is not a credential -- it is a request
     * that will be refused, and minting one would turn a missing field into a
     * charge attempt that fails in front of a buyer.
     *
     * Read from `data.customer_id` and `data.order.customer_card_id`, the two
     * paths a recorded placement actually puts them at, with the nested
     * spellings as fallbacks. The **top-level** `data.customer_card_id` is
     * deliberately not read: it is absent from every recorded placement
     * response and present only on a later order read, so accepting it here
     * would mint a handle from a shape this call never returns.
     *
     * @param array<string, mixed> $envelope
     */
    private static function reusableCredential(array $envelope): ?PaymentCredential
    {
        $data = $envelope['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $order = is_array($data['order'] ?? null) ? $data['order'] : [];
        $card = is_array($order['customer_card'] ?? null) ? $order['customer_card'] : [];

        $customerId = self::identifier($data['customer_id'] ?? ($order['customer_id'] ?? null));
        $cardId = self::identifier($order['customer_card_id'] ?? ($card['customer_card_id'] ?? null));

        if ($customerId === null || $cardId === null) {
            return null;
        }

        return PaymentCredential::stored([
            'customer_id' => $customerId,
            'customer_card_id' => $cardId,
        ]);
    }

    /** One of the provider's integer ids as a non-empty string, or null when it sent none. */
    private static function identifier(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * What the provider charged against what the storefront displayed.
     *
     * Only a placement is reconciled: a decline took no money, so its
     * `transaction_total` is the amount that was attempted, and reporting that
     * as a discrepancy would send an operator after a charge that does not
     * exist. A response with no usable `transaction_total` is left unreconciled
     * for the same reason -- an unmeasured total is not a mismatch.
     *
     * @param array<string, mixed> $transaction
     */
    private static function reconcile(array $transaction, ?int $expectedTotalCents): ?ChargeDiscrepancy
    {
        if ($expectedTotalCents === null) {
            return null;
        }

        $chargedCents = self::cents($transaction['transaction_total'] ?? null);
        if ($chargedCents === null || $chargedCents === $expectedTotalCents) {
            return null;
        }

        return new ChargeDiscrepancy(
            expectedCents: $expectedTotalCents,
            chargedCents: $chargedCents,
            providerDiscountCents: self::cents($transaction['order']['order_discount'] ?? null),
        );
    }

    /** One of the provider's decimal-string amounts as cents, or null when it sent none. */
    private static function cents(mixed $amount): ?int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : null;
    }

    /**
     * The transaction node, wherever this response put it.
     *
     * @param  array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private static function transaction(array $envelope): array
    {
        $data = $envelope['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $nested = $data['error']['transaction'] ?? null;

        return is_array($nested) ? $nested : $data;
    }

    /** @param array<string, mixed> $transaction */
    private static function reference(array $transaction): ?string
    {
        $id = $transaction['order_id'] ?? ($transaction['order']['order_id'] ?? null);

        if (is_int($id)) {
            return (string) $id;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @param array<string, mixed> $transaction */
    private static function orderStatus(array $transaction): ?int
    {
        $status = $transaction['order']['status_type_id'] ?? null;

        return is_int($status) ? $status : null;
    }

    /**
     * The buyer-safe reason, scrubbed at the point it is extracted.
     *
     * Three of the five paths are gateway free text, and this string is both
     * shown to the buyer and written to the order row -- so the scrub happens
     * here, once, rather than at each of the places that go on to use it.
     *
     * @param array<string, mixed> $envelope
     */
    private static function reason(array $envelope): string
    {
        foreach (self::REASON_PATHS as $path) {
            /** @var mixed $value */
            $value = $envelope;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    $value = null;

                    break;
                }
                $value = $value[$key];
            }

            if (is_string($value) && trim($value) !== '') {
                return (string) CardScrubber::scrub(trim($value));
            }
        }

        return self::GENERIC_DECLINE;
    }
}
