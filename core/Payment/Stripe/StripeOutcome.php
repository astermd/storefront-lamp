<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Stripe;

use AsterMD\Storefront\Payment\CardBrand;
use AsterMD\Storefront\Payment\ChargeDiscrepancy;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Payment\SettlementMode;

/**
 * A PaymentIntent, as a {@see PlacementOutcome}.
 *
 * Read from the PaymentIntent's array form (`StripeObject::toArray()`), so the
 * mapping is a pure function a test can drive from a recorded response.
 *
 * **Its status is the whole answer**, and each one means what the EMR will
 * read it as when the order is synced:
 *
 * - `succeeded`: charged.
 * - `requires_capture`: held, to be captured by the EMR when the doctor
 *   completes the case -- placed, with {@see SettlementMode::Authorize}.
 * - `processing`: accepted and still settling; the EMR books it unpaid until
 *   Stripe says otherwise, and so is it placed here.
 * - `requires_action`: the bank wants the buyer to verify (`[14.22]`). Pending,
 *   carrying the client secret Stripe's script needs to run that step -- in
 *   memory only, see {@see PlacementOutcome}.
 * - Anything else -- `requires_payment_method` above all, which is a refused
 *   card or a failed verification -- took no money: a decline, with Stripe's
 *   own message when it gave one, because Stripe writes that message for the
 *   buyer.
 *
 * **What survives to pay for the next charge is the customer and the card
 * together** ({@see PaymentCredential::stored()}). Stripe only lets a saved
 * card be charged against the customer it is attached to, so the pair is what
 * an upsell on this journey submits, and it is minted here from Stripe's own
 * answer rather than ever taken from a request.
 */
final class StripeOutcome
{
    /** What the buyer is told when Stripe gave nothing better. */
    public const string GENERIC_DECLINE = 'We could not complete your payment. Please check your card details and try again.';

    /** Stripe's card brand → the storefront's. A brand not listed is no brand rather than a guess. */
    private const array BRANDS = [
        'visa' => CardBrand::Visa,
        'mastercard' => CardBrand::Mastercard,
        'amex' => CardBrand::Amex,
        'discover' => CardBrand::Discover,
        'diners' => CardBrand::DinersClub,
        'jcb' => CardBrand::Jcb,
    ];

    /**
     * @param array<string, mixed> $paymentIntent the PaymentIntent, `payment_method` expanded where available
     * @param ?int $expectedTotalCents what the buyer was shown, when there is a figure to hold it to
     */
    public static function from(array $paymentIntent, ?int $expectedTotalCents = null): PlacementOutcome
    {
        $reference = self::text($paymentIntent['id'] ?? null);
        $status = self::text($paymentIntent['status'] ?? null);

        if ($reference === null) {
            // A body with no id is a payment nobody can find again, which is
            // the one shape that cannot rule out a charge.
            return PlacementOutcome::declined(null, self::GENERIC_DECLINE, 'no_reference');
        }

        return match ($status) {
            'succeeded', 'processing' => self::placed($paymentIntent, $reference, $status, SettlementMode::Capture, $expectedTotalCents),
            'requires_capture' => self::placed($paymentIntent, $reference, $status, SettlementMode::Authorize, $expectedTotalCents),
            'requires_action' => PlacementOutcome::pendingAction(
                $reference,
                self::redirectUrl($paymentIntent) ?? '',
                $status,
                self::text($paymentIntent['client_secret'] ?? null),
            ),
            default => PlacementOutcome::declined(
                $reference,
                self::buyerMessage($paymentIntent['last_payment_error'] ?? null) ?? self::GENERIC_DECLINE,
                $status ?? 'unknown',
            ),
        };
    }

    /**
     * A refusal Stripe answered with an error rather than a PaymentIntent --
     * a declined card on the confirming call.
     *
     * Stripe's message on a card error is written for the buyer ("Your card
     * has insufficient funds."), so it is shown as it is (`[13.28]`); the
     * decline code is the operator's.
     *
     * @param ?string $reference the PaymentIntent the error was raised on, when Stripe named one
     */
    public static function fromCardError(?string $reference, ?string $message, ?string $declineCode): PlacementOutcome
    {
        $message = $message === null ? '' : trim($message);

        return PlacementOutcome::declined(
            $reference,
            $message !== '' ? $message : self::GENERIC_DECLINE,
            $declineCode !== null && $declineCode !== '' ? $declineCode : 'card_declined',
        );
    }

    /** @param array<string, mixed> $paymentIntent */
    private static function placed(
        array $paymentIntent,
        string $reference,
        string $status,
        SettlementMode $settlement,
        ?int $expectedTotalCents,
    ): PlacementOutcome {
        $amount = $paymentIntent['amount'] ?? null;
        $discrepancy = $expectedTotalCents !== null && is_int($amount) && $amount !== $expectedTotalCents
            ? new ChargeDiscrepancy(expectedCents: $expectedTotalCents, chargedCents: $amount)
            : null;

        $card = self::card($paymentIntent['payment_method'] ?? null);

        return PlacementOutcome::placed(
            $reference,
            $status,
            $discrepancy,
            self::reusable($paymentIntent),
            $settlement,
            null,
            $card === null ? null : (self::BRANDS[(string) ($card['brand'] ?? '')] ?? null),
            $card === null ? null : self::expiry($card),
        );
    }

    /**
     * The customer and card a later charge may use, or null when Stripe kept
     * no card against a customer for this payment.
     *
     * @param array<string, mixed> $paymentIntent
     */
    private static function reusable(array $paymentIntent): ?PaymentCredential
    {
        $customer = self::idOf($paymentIntent['customer'] ?? null);
        $method = self::idOf($paymentIntent['payment_method'] ?? null);

        if ($customer === null || $method === null) {
            return null;
        }

        return PaymentCredential::stored(['customer' => $customer, 'payment_method' => $method]);
    }

    /** @return array<string, mixed>|null the expanded PaymentMethod's card */
    private static function card(mixed $paymentMethod): ?array
    {
        $card = is_array($paymentMethod) ? ($paymentMethod['card'] ?? null) : null;

        return is_array($card) ? $card : null;
    }

    /** @param array<string, mixed> $card */
    private static function expiry(array $card): ?string
    {
        $month = $card['exp_month'] ?? null;
        $year = $card['exp_year'] ?? null;

        if (!is_int($month) || !is_int($year) || $month < 1 || $month > 12) {
            return null;
        }

        return sprintf('%02d/%02d', $month, $year % 100);
    }

    /** @param array<string, mixed> $paymentIntent */
    private static function redirectUrl(array $paymentIntent): ?string
    {
        $next = $paymentIntent['next_action'] ?? null;
        $redirect = is_array($next) ? ($next['redirect_to_url'] ?? null) : null;

        return is_array($redirect) ? self::text($redirect['url'] ?? null) : null;
    }

    private static function buyerMessage(mixed $error): ?string
    {
        return is_array($error) ? self::text(is_string($error['message'] ?? null) ? trim($error['message']) : null) : null;
    }

    /** An id, whether Stripe answered with the id or the expanded object. */
    private static function idOf(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return self::text($value);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
