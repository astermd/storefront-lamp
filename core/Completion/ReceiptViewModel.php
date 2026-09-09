<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Completion;

/**
 * Everything the receipt page renders, assembled once on the server.
 *
 * The same division {@see \AsterMD\Storefront\Checkout\CheckoutViewModel}
 * draws: the template reads fields and iterates lists, and decides nothing. A
 * line total, the item count and the buyer's first name are all computed here
 * rather than in Twig, because a template that multiplied a unit price by a
 * quantity would be a second authority on money — and this page is the one a
 * buyer keeps.
 *
 * It is a projection of {@see Receipt} rather than the receipt itself so that
 * the durable snapshot stays exactly what `[4.17]` asks for — the order, and
 * nothing shaped for a particular page. Presentation that changes with the
 * theme therefore never ends up in the `journey_state` column, where it would
 * outlive the template it was built for.
 *
 * **There is no case id** (`[17.1]`'s status card renders without one). The
 * EMR's treatment record carries a null `provider_case_id` at the moment a
 * treatment is created, so a conditional render would print an empty label on
 * every real order and an unconditional one would print a fiction. Reading it
 * back once the clinical side has assigned one is §29's work.
 *
 * `$pendingReview` is the single clinical status this revision has, and it is
 * true on every receipt for the same reason.
 */
final class ReceiptViewModel
{
    /**
     * @param list<string>                                                                                                        $references         placed orders, in the order they were placed
     * @param list<string>                                                                                                        $declinedReferences orders that exist at the provider but were not charged
     * @param list<array{slug: string, name: string, kind: string, quantity: int, unit_price_cents: int, line_total_cents: int}>   $lines
     * @param array<string, string>                                                                                               $shipping           keyed as {@see Receipt::$shipping}
     */
    private function __construct(
        public readonly array $references,
        public readonly array $declinedReferences,
        public readonly array $lines,
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        public readonly int $paidTotalCents,
        public readonly string $currency,
        public readonly ?string $promotionCode,
        public readonly string $buyerEmail,
        public readonly string $buyerName,
        public readonly string $buyerFirstName,
        public readonly string $buyerPhone,
        public readonly array $shipping,
        public readonly string $shippingAddress,
        public readonly ?string $cardLastFour,
        public readonly ?string $paymentMethod,
        public readonly ?string $placedAt,
        public readonly bool $pendingReview,
        public readonly ?string $orderNumber,
        public readonly int $itemCount,
    ) {
    }

    /** The page for one completed journey. */
    public static function fromReceipt(Receipt $receipt): self
    {
        $lines = [];
        $itemCount = 0;
        foreach ($receipt->lines as $line) {
            $lines[] = $line + ['line_total_cents' => $line['unit_price_cents'] * $line['quantity']];
            $itemCount += $line['quantity'];
        }

        return new self(
            references: $receipt->references,
            declinedReferences: $receipt->declinedReferences,
            lines: $lines,
            subtotalCents: $receipt->subtotalCents,
            discountCents: $receipt->discountCents,
            paidTotalCents: $receipt->paidTotalCents,
            currency: $receipt->currency,
            promotionCode: $receipt->promotionCode,
            buyerEmail: $receipt->buyerEmail,
            buyerName: $receipt->buyerName,
            buyerFirstName: self::firstName($receipt->buyerName),
            buyerPhone: $receipt->buyerPhone,
            shipping: $receipt->shipping,
            shippingAddress: self::oneLine($receipt->shipping),
            cardLastFour: $receipt->cardLastFour,
            paymentMethod: $receipt->paymentMethod,
            placedAt: $receipt->placedAt,
            pendingReview: $receipt->pendingReview,
            orderNumber: $receipt->references[0] ?? null,
            itemCount: $itemCount,
        );
    }

    /**
     * The page for a visitor the storefront cannot prove bought anything.
     *
     * Not an error state. A journey the server never saw start, or one whose
     * local write failed after the money moved, still reaches this page — and
     * `[20.1]` says an analytics or bookkeeping failure must not lock a buyer
     * out of their own receipt. What it can honestly show is a thin page, and
     * {@see self::hasOrder()} is what the template branches on.
     */
    public static function empty(string $currency): self
    {
        return self::fromReceipt(Receipt::fromOrderRows([], [], $currency));
    }

    /** Whether there is an order to show at all, which is the template's one structural branch. */
    public function hasOrder(): bool
    {
        return $this->references !== [];
    }

    /** Whether a discount row belongs on the summary: a zero one would show the buyer a saving they did not make. */
    public function hasDiscount(): bool
    {
        return $this->discountCents > 0;
    }

    /**
     * The shipping block as one line, for the address paragraph and for the
     * map query.
     *
     * Empty fields are dropped rather than rendered as stray commas, and the
     * country is routinely one of them: `[13.36]` is a declared gap and the
     * receipt does not invent a fact about where a parcel is going.
     *
     * @param array<string, string> $shipping
     */
    private static function oneLine(array $shipping): string
    {
        $parts = [];
        foreach (['address_line', 'city', 'territory', 'postal_code', 'country'] as $field) {
            $value = trim($shipping[$field] ?? '');
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * The name to greet the buyer by.
     *
     * The first whitespace-separated word of the stored name, because that is
     * what the receipt has: the rows keep one `buyer_name` column and journey
     * state's own copy is gone by the time this renders. A single-word name
     * greets by itself, and an empty one greets by nothing rather than by a
     * placeholder.
     */
    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false || $parts === [] ? '' : $parts[0];
    }
}
