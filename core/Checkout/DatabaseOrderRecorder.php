<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Payment\ChargeDiscrepancy;
use AsterMD\Storefront\Payment\OrderEnvelope;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentCredential;
use AsterMD\Storefront\Payment\PlacementOutcome;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * {@see OrderRecorder} over the `orders` tables (`[18.1]`).
 *
 * The whole class is one translation — a provider-neutral envelope and its
 * outcome into the three rows {@see OrderRepository::insert()} writes — plus
 * the swallow that makes it safe to call after the money has moved. Nothing
 * here decides anything; the order of operations `[13.32]` fixes belongs to
 * {@see CheckoutService}, and by the time this runs the charge has already
 * settled.
 *
 * **A failed write is logged and returns null, never thrown.** `[20.1]` is
 * unambiguous about which way this fails: an exception here would surface to a
 * buyer whose card has already been charged, turning a bookkeeping problem
 * into a purchase that looks broken. The guard against charging something that
 * could never be recorded runs earlier — at the idempotency claim, before the
 * provider is called — and that one *does* refuse.
 *
 * **The card never reaches the row.** Only {@see PaymentCredential::lastFour()}
 * is taken, which is what a receipt and a support call need (`[15.8]`), and the
 * failure log carries the exception class and code rather than its message:
 * the message is built from a database driver's own text, which on a constraint
 * violation quotes the offending values back, and one of the values on this
 * insert is a buyer's email address.
 */
final class DatabaseOrderRecorder implements OrderRecorder
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OperatorLog $log,
    ) {
    }

    /** @param list<ConsentRecord> $consents */
    public function record(
        OrderEnvelope $order,
        PlacementOutcome $outcome,
        PaymentCredential $credential,
        array $consents,
        string $providerCategory,
        bool $isUpsell = false,
    ): ?int {
        $reference = $outcome->reference;

        if ($reference === null || $reference === '') {
            // Nothing to file the row under and nothing to reconcile it
            // against later, so a row would be worse than none: `[18.1]`'s
            // whole query is "which local orders never reached the EMR", and
            // that is keyed on the provider's reference.
            $this->log->warning('checkout.order_not_recorded', ['reason' => 'no_provider_reference']);

            return null;
        }

        try {
            return $this->orders->insert(
                [
                    'session_uuid' => $order->sessionUuid,
                    'provider_reference' => $reference,
                    'anchor_slug' => $order->anchorSlug,
                    'amount_cents' => self::chargedCents($order, $outcome),
                    'currency' => $order->currency,
                    'status' => $outcome->state,
                    'buyer_email' => $order->buyer->email,
                    'buyer_name' => trim($order->buyer->firstName . ' ' . $order->buyer->lastName),
                    'buyer_territory' => $order->buyer->territory,
                    'discount_cents' => $order->discountCents,
                    'promotion_code' => $order->promotionCode,
                    'payment_method' => $credential->kind,
                    'card_last_four' => self::lastFour($credential),
                    'idempotency_key' => $order->idempotencyKey,
                    'provider_category' => $providerCategory,
                    'is_upsell' => $isUpsell,
                ],
                array_map(self::line(...), $order->lines),
                array_map(static fn (ConsentRecord $c): array => $c->toArray(), $consents),
            );
        } catch (\Throwable $e) {
            $this->log->error('checkout.order_not_recorded', [
                'reference' => $reference,
                'reason' => 'write_failed',
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return null;
        }
    }

    /**
     * One neutral line as the table holds it.
     *
     * `sent_to_provider` mirrors {@see OrderLine::isChargeable()} rather than
     * the adapter's own report, because the two are the same fact and asking
     * the adapter would tie this class to a provider. A line with no provider
     * identity is kept at `0` rather than dropped (`[13.19]`): the local record
     * still says what the buyer saw.
     *
     * `kind` is the catalog's own fact, carried on the line and copied
     * through. It is not derived here: `orders.kind` defaults to the
     * unrestricted case, so a prescription that arrives without its kind is
     * recorded as an over-the-counter sale — a durable record that under-states
     * what a clinician has to review. Inferring it from the price would be a
     * guess in that same record.
     *
     * @return array{slug: string, name: string, kind: string, provider_offer: ?string, provider_item: ?string, unit_price_cents: int, quantity: int, sent_to_provider: bool}
     */
    private static function line(OrderLine $line): array
    {
        return [
            'slug' => $line->slug,
            'name' => $line->name,
            'kind' => $line->kind,
            'provider_offer' => $line->providerOffer,
            'provider_item' => $line->providerItem,
            'unit_price_cents' => $line->unitPriceCents,
            'quantity' => $line->quantity,
            'sent_to_provider' => $line->isChargeable(),
        ];
    }

    /**
     * What the provider actually took, which is not always what the buyer was
     * shown.
     *
     * The envelope's total is the figure the storefront quoted and the buyer
     * agreed to; {@see ChargeDiscrepancy} exists precisely because the
     * provider can charge a different one, and by the time it is measurable
     * the money has already moved. `amount_cents` is the column an operator
     * reconciling a card statement reads, so it holds the debit rather than
     * the quote — the alternative shows a $27.00 charge as $3.00 and makes the
     * row itself the thing that hides the problem.
     *
     * The quoted figure is not lost: the `payment.total_mismatch` line the
     * adapter raises at error level carries `expected_cents` and
     * `charged_cents` together, which is the pair a human needs and the pair
     * that says which way the gap ran. No second column is kept for a number
     * nothing queries.
     */
    private static function chargedCents(OrderEnvelope $order, PlacementOutcome $outcome): int
    {
        return $outcome->chargeDiscrepancy?->chargedCents ?? $order->totalCents;
    }

    /** Null rather than an empty string when the credential was never a card, so the column reads as "not applicable". */
    private static function lastFour(PaymentCredential $credential): ?string
    {
        $lastFour = $credential->lastFour();

        return $lastFour === '' ? null : $lastFour;
    }
}
