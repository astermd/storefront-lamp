<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Completion;

use AsterMD\Storefront\Payment\PlacementOutcome;

/**
 * The retained order receipt (`[4.17]`).
 *
 * Built from the **local order rows** at completion, before the journey is
 * wiped, and then held in journey state as the only thing a refresh reads.
 * The rows are the source rather than the live cart or the provider, for two
 * reasons: the cart is already gone by the time an order exists (`[13.32]`),
 * and the rows are what an operator and a support agent see, so a receipt
 * built from anything else could show the buyer a figure that no record backs.
 *
 * `[16.10]`'s "folds into the running totals" is this class's arithmetic: the
 * paid total is the sum of the placed orders' `amount_cents`, computed here
 * rather than accumulated in journey state, because a running counter would be
 * a second authority on money and could disagree with the first.
 *
 * **Where each field comes from is one rule.** Every figure and every
 * reference comes from the rows. The buyer's contact and shipping details come
 * from journey state, because the rows keep only an email, a name and a
 * territory — there is no street address in the `orders` table and a parcel
 * label cannot be reconstructed from one. Email and name fall back to the row
 * when journey state no longer holds them.
 *
 * **Only a placed order is money.** A declined placement still gets a
 * provider reference and still gets a row (`[13.26]`), and so does one left
 * pending a challenge; both are kept here, separately from the placed ones, and
 * neither is counted in the total, because nobody was charged.
 *
 * They are kept rather than rendered. The design carries no slot for a
 * reference nobody was charged for, and inventing one would put a failed
 * attempt in front of a buyer who has just successfully paid — so what they are
 * for is the operator answering a support call, who reaches them through the
 * order rows this was built from rather than through the page. `subtotalCents` is the placed rows' line prices, so it can differ
 * from `paidTotalCents` by more than the discount on an order whose charged
 * figure did not match the quote — that difference is the discrepancy an
 * operator is already being alerted about, and hiding it here would remove the
 * one place a buyer-visible figure and a charged figure can be compared.
 *
 * The clinical status is a fixed "pending review" this revision. The EMR's own
 * treatment record carries a richer state -- and carries a null case id at the
 * moment a treatment is created -- so rendering one would print an empty
 * label on every real order. Reading the state back is §29's work.
 *
 * No card reaches here. The rows carry four digits and nothing more
 * (`[15.8]`), and the buyer map is read field by named field rather than
 * copied, because this object is the one part of a journey that is *kept*: a
 * card that got in would be a card at rest in the `sessions` table for as long
 * as the row lives.
 */
final class Receipt
{
    /** The shipping block's field set, so it is a named shape rather than whatever the buyer map happened to hold. */
    private const array SHIPPING_FIELDS = ['name', 'address_line', 'city', 'territory', 'postal_code', 'country'];

    /**
     * @param list<string>                                                                                     $references         placed orders, in the order they were placed
     * @param list<string>                                                                                     $declinedReferences orders that exist at the provider but were not charged
     * @param list<array{slug: string, name: string, kind: string, quantity: int, unit_price_cents: int}>       $lines
     * @param array<string, string>                                                                            $shipping           keyed by {@see self::SHIPPING_FIELDS}
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
        public readonly string $buyerPhone,
        public readonly array $shipping,
        public readonly ?string $cardLastFour,
        public readonly ?string $paymentMethod,
        public readonly ?string $placedAt,
        public readonly bool $pendingReview,
    ) {
    }

    /**
     * The receipt for one journey's orders.
     *
     * An empty row set is an ordinary input, not an error: the money moves
     * before the local write happens, so a write that failed leaves a charged
     * buyer with no row, and that buyer is still entitled to a page. They get
     * a receipt with zero totals and the configured currency, which is honest
     * about what the storefront can prove.
     *
     * @param list<array<string, mixed>> $orders  as {@see \AsterMD\Storefront\Repository\OrderRepository::findByReference()} returns them
     * @param array<string, string>      $buyer   as {@see \AsterMD\Storefront\Journey\JourneyState::$buyer} holds it
     * @param string                     $currency the configured currency, used when no row can name one
     */
    public static function fromOrderRows(array $orders, array $buyer, string $currency): self
    {
        $placed = [];
        $declined = [];
        foreach ($orders as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (self::text($row['status'] ?? null) === PlacementOutcome::PLACED) {
                $placed[] = $row;
            } else {
                $declined[] = $row;
            }
        }

        $lines = [];
        $subtotal = 0;
        $paid = 0;
        $discount = 0;
        foreach ($placed as $row) {
            $paid += self::number($row['amount_cents'] ?? null);
            $discount += self::number($row['discount_cents'] ?? null);

            foreach (is_array($row['lines'] ?? null) ? $row['lines'] : [] as $line) {
                $parsed = self::line($line);
                if ($parsed === null) {
                    continue;
                }

                $lines[] = $parsed;
                $subtotal += $parsed['unit_price_cents'] * $parsed['quantity'];
            }
        }

        $name = trim(self::text($buyer['first_name'] ?? null) . ' ' . self::text($buyer['last_name'] ?? null));

        return new self(
            references: array_map(static fn (array $row): string => self::text($row['provider_reference'] ?? null), $placed),
            declinedReferences: array_map(static fn (array $row): string => self::text($row['provider_reference'] ?? null), $declined),
            lines: $lines,
            subtotalCents: $subtotal,
            discountCents: $discount,
            paidTotalCents: $paid,
            currency: self::firstOf($placed, 'currency') ?? $currency,
            promotionCode: self::firstOf($placed, 'promotion_code'),
            buyerEmail: self::text($buyer['email'] ?? null) ?: (self::firstOf($placed, 'buyer_email') ?? ''),
            buyerName: $name !== '' ? $name : (self::firstOf($placed, 'buyer_name') ?? ''),
            buyerPhone: self::text($buyer['phone'] ?? null),
            shipping: [
                'name' => $name,
                'address_line' => self::text($buyer['address_line'] ?? null),
                'city' => self::text($buyer['city'] ?? null),
                'territory' => self::text($buyer['territory'] ?? null) ?: (self::firstOf($placed, 'buyer_territory') ?? ''),
                'postal_code' => self::text($buyer['postal_code'] ?? null),
                // `[13.36]` is a declared gap: the country is not collected and
                // the adapter hardcodes it. Left empty rather than assumed here,
                // because a receipt is the wrong place to invent a fact about
                // where a parcel is going.
                'country' => self::text($buyer['country'] ?? null),
            ],
            cardLastFour: self::firstOf($placed, 'card_last_four'),
            paymentMethod: self::firstOf($placed, 'payment_method'),
            placedAt: self::firstOf($placed, 'placed_at'),
            pendingReview: true,
        );
    }

    /**
     * The durable shape journey state holds.
     *
     * Snake-cased to match the rest of the `journey_state` blob, and flat
     * enough that a template can read it without this class being present —
     * which matters because a refresh of the receipt renders from the stored
     * copy, not from a rebuild.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'references' => $this->references,
            'declined_references' => $this->declinedReferences,
            'lines' => $this->lines,
            'subtotal_cents' => $this->subtotalCents,
            'discount_cents' => $this->discountCents,
            'paid_total_cents' => $this->paidTotalCents,
            'currency' => $this->currency,
            'promotion_code' => $this->promotionCode,
            'buyer_email' => $this->buyerEmail,
            'buyer_name' => $this->buyerName,
            'buyer_phone' => $this->buyerPhone,
            'shipping' => $this->shipping,
            'card_last_four' => $this->cardLastFour,
            'payment_method' => $this->paymentMethod,
            'placed_at' => $this->placedAt,
            'pending_review' => $this->pendingReview,
        ];
    }

    /**
     * A stored receipt, read back defensively.
     *
     * Every field degrades rather than throws, in the same spirit as
     * {@see \AsterMD\Storefront\Journey\JourneyState::fromArray()}: the input
     * is a JSON column written by whatever release last touched it, and the
     * page this feeds is the one a buyer who has just been charged is entitled
     * to see. An empty receipt renders as a thin page; an exception renders as
     * nothing.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromArray(array $stored): self
    {
        $shipping = [];
        $raw = is_array($stored['shipping'] ?? null) ? $stored['shipping'] : [];
        foreach (self::SHIPPING_FIELDS as $field) {
            $shipping[$field] = self::text($raw[$field] ?? null);
        }

        return new self(
            references: self::references($stored['references'] ?? null),
            declinedReferences: self::references($stored['declined_references'] ?? null),
            lines: self::lines($stored['lines'] ?? null),
            subtotalCents: self::number($stored['subtotal_cents'] ?? null),
            discountCents: self::number($stored['discount_cents'] ?? null),
            paidTotalCents: self::number($stored['paid_total_cents'] ?? null),
            currency: self::text($stored['currency'] ?? null),
            promotionCode: self::nullableText($stored['promotion_code'] ?? null),
            buyerEmail: self::text($stored['buyer_email'] ?? null),
            buyerName: self::text($stored['buyer_name'] ?? null),
            buyerPhone: self::text($stored['buyer_phone'] ?? null),
            shipping: $shipping,
            cardLastFour: self::nullableText($stored['card_last_four'] ?? null),
            paymentMethod: self::nullableText($stored['payment_method'] ?? null),
            placedAt: self::nullableText($stored['placed_at'] ?? null),
            // Not read back from the blob: there is exactly one clinical status
            // this revision, so a stored `false` could only be a shape from a
            // release that had more of them, and "not pending review" is not a
            // claim this code can currently substantiate.
            pendingReview: true,
        );
    }

    /**
     * One line as the receipt holds it, or null when what arrived is not a
     * line.
     *
     * A line with no slug is dropped rather than shown blank: the slug is what
     * ties a row on the receipt to something in the catalog, and a nameless
     * charge on a receipt is worse than a total that does not itemise.
     *
     * @return array{slug: string, name: string, kind: string, quantity: int, unit_price_cents: int}|null
     */
    private static function line(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $slug = self::text($raw['slug'] ?? null);
        if ($slug === '') {
            return null;
        }

        return [
            'slug' => $slug,
            'name' => self::text($raw['name'] ?? null),
            'kind' => self::text($raw['kind'] ?? null),
            'quantity' => self::number($raw['quantity'] ?? null),
            'unit_price_cents' => self::number($raw['unit_price_cents'] ?? null),
        ];
    }

    /** @return list<array{slug: string, name: string, kind: string, quantity: int, unit_price_cents: int}> */
    private static function lines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $lines = [];
        foreach ($raw as $line) {
            $parsed = self::line($line);
            if ($parsed !== null) {
                $lines[] = $parsed;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function references(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => self::text($value), $raw),
            static fn (string $reference): bool => $reference !== '',
        ));
    }

    /**
     * The first non-empty value of one column across the placed rows.
     *
     * The main order is the first of them, so this is "what the checkout said"
     * with an upsell as the fallback: the promotion, the card's last four and
     * the payment method all belong to the placement that collected the card,
     * and an upsell charged against a stored handle can name none of them.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function firstOf(array $rows, string $column): ?string
    {
        foreach ($rows as $row) {
            $value = self::text($row[$column] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === '' ? null : $text;
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
