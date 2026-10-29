<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Catalog;

/**
 * Pure transform: the `subscriptions` of an EMR Channel Details response ->
 * the plan rows {@see CatalogBuilder::build()} sells variants on.
 *
 * The EMR lists the plans assigned to the channel that can be sold (active,
 * the org's, on the channel's payment provider), each with the whole plan. A
 * storefront that creates its own payment names a plan line by the plan's id
 * and charges the plan's first payment for it -- the price the EMR quotes a
 * discount code against -- so each row here names the product and variant the
 * plan sells and that first payment, worked out from the plan's steps exactly
 * as the EMR's sales service does (`src/lib/subscription/plan-product.ts` and
 * `plan-first-payment.ts` there; the subscription engine's rule). Keep the two
 * in step: a figure that differs from the EMR's is a discount the EMR prices
 * on a different total.
 *
 * A plan that names no product, or whose first payment cannot be priced, is
 * left out rather than repaired: a plan the storefront cannot price is one it
 * must not sell.
 */
final class SubscriptionPlans
{
    private const string OBJECT_ID = '/^[a-f\d]{24}$/i';

    /** A step that never ends is only looked at this many cycles ahead (the engine's safety stop). */
    private const int MAX_CYCLES_AHEAD = 60;

    /**
     * @param array<string, mixed> $channelData the `data` subtree of a Channel Details response
     * @return list<array{plan_id: string, name: string, display_name: string, product_id: string, variant_id: string, first_payment_cents: int, allow_coupons: bool}>
     */
    public static function fromChannelDetails(array $channelData): array
    {
        $plans = [];

        foreach (self::rows($channelData['subscriptions'] ?? null) as $row) {
            $plan = is_array($row['plan'] ?? null) ? $row['plan'] : null;
            if ($plan === null) {
                continue;
            }

            $id = self::idText($plan['_id'] ?? $row['plan_id'] ?? null);
            $ref = self::productRef($plan);
            $payment = self::firstPayment($plan);
            if ($id === null || $ref === null || $payment === null) {
                continue;
            }

            $name = is_string($plan['name'] ?? null) ? $plan['name'] : '';
            $displayName = is_string($plan['display_name'] ?? null) && $plan['display_name'] !== '' ? $plan['display_name'] : $name;

            $plans[] = [
                'plan_id' => $id,
                'name' => $name,
                'display_name' => $displayName,
                'product_id' => $ref['product_id'],
                'variant_id' => $ref['variant_id'],
                'first_payment_cents' => $payment['amount_cents'],
                'allow_coupons' => $payment['allow_coupons'],
            ];
        }

        return $plans;
    }

    /**
     * The product and variant a plan sells: its product, and the variant it
     * ships of that product -- the first item for the product with a variant
     * in the first cycle of a shipment step, in step order. A plan that ships
     * no variant of it sells the product by its own id, as a product without
     * variants is named. Null when the plan names no product.
     *
     * @param array<string, mixed> $plan
     * @return array{product_id: string, variant_id: string}|null
     */
    public static function productRef(array $plan): ?array
    {
        $productId = self::idText($plan['product_id'] ?? null);
        if ($productId === null) {
            return null;
        }

        foreach (self::rows($plan['steps'] ?? null) as $step) {
            if (($step['type'] ?? null) !== 'shipment') {
                continue;
            }
            $firstCycle = self::rows($step['items_by_cycle'] ?? null)[0] ?? null;
            foreach (self::rows($firstCycle['items'] ?? null) as $item) {
                $variantId = self::idText($item['variant_id'] ?? null);
                if ($variantId !== null && self::idText($item['product_id'] ?? null) === $productId) {
                    return ['product_id' => $productId, 'variant_id' => $variantId];
                }
            }
        }

        return ['product_id' => $productId, 'variant_id' => $productId];
    }

    /**
     * What the plan charges at checkout: cycle 1 of its first charge step, in
     * step order, due on day 0 that does not capture a pre-auth. A `fixed`
     * charge pays its own amount; any other pays for each cycle of the
     * shipments or lab tests in `pays_for_step_ids` due from day 0 until its
     * next charge. A plan with no charge due on day 0 charges nothing.
     *
     * Whether a discount code may apply is the step's `allow_coupons` -- for a
     * pre-auth, the flag of the step that captures it, since the engine applies
     * coupons when money is taken.
     *
     * Null when the plan cannot be priced (a malformed charge or price).
     *
     * @param array<string, mixed> $plan
     * @return array{amount_cents: int, allow_coupons: bool}|null
     */
    public static function firstPayment(array $plan): ?array
    {
        $steps = self::rows($plan['steps'] ?? null);

        $step = null;
        foreach ($steps as $candidate) {
            $schedule = is_array($candidate['schedule'] ?? null) ? $candidate['schedule'] : [];
            if (($candidate['type'] ?? null) === 'charge' && self::num($schedule['start_day'] ?? null) === 0.0
                && empty($candidate['captures_step_id'])) {
                $step = $candidate;
                break;
            }
        }
        if ($step === null) {
            return ['amount_cents' => 0, 'allow_coupons' => true];
        }

        $capture = null;
        if (($step['payment_mode'] ?? null) === 'pre_auth') {
            foreach ($steps as $candidate) {
                if (($candidate['type'] ?? null) === 'charge' && ($candidate['captures_step_id'] ?? null) === ($step['step_id'] ?? null)) {
                    $capture = $candidate;
                    break;
                }
            }
        }
        $allowCoupons = (($capture ?? $step)['allow_coupons'] ?? true) !== false;

        if (($step['amount_source'] ?? 'fixed') === 'fixed') {
            $amount = self::num($step['amount_cents'] ?? null);

            return self::wholeCents($amount) ? ['amount_cents' => (int) $amount, 'allow_coupons' => $allowCoupons] : null;
        }

        $charge = self::scheduleOf($step);
        if ($charge === null) {
            return null;
        }

        $amount = 0.0;
        $paysFor = is_array($step['pays_for_step_ids'] ?? null) ? $step['pays_for_step_ids'] : [];
        foreach ($paysFor as $id) {
            $paid = null;
            foreach ($steps as $candidate) {
                if (($candidate['step_id'] ?? null) === $id) {
                    $paid = $candidate;
                    break;
                }
            }
            $paidSchedule = $paid !== null ? self::scheduleOf($paid) : null;
            if ($paid === null || $paidSchedule === null) {
                continue;
            }
            foreach (self::paidCycles($charge, $paidSchedule) as $cycle) {
                $cost = self::cycleAmount($paid, $cycle);
                if ($cost === null) {
                    return null;
                }
                $amount += $cost;
            }
        }

        return self::wholeCents($amount) ? ['amount_cents' => (int) $amount, 'allow_coupons' => $allowCoupons] : null;
    }

    /**
     * @param array<string, mixed> $step
     * @return array{start_day: float, recurrence: string, interval_days: float, occurrence_count: float}|null
     */
    private static function scheduleOf(array $step): ?array
    {
        $schedule = is_array($step['schedule'] ?? null) ? $step['schedule'] : null;
        $startDay = self::num($schedule['start_day'] ?? null);
        if ($schedule === null || $startDay === null) {
            return null;
        }

        return [
            'start_day' => $startDay,
            'recurrence' => is_string($schedule['recurrence'] ?? null) ? $schedule['recurrence'] : 'once',
            'interval_days' => self::num($schedule['interval_days'] ?? null) ?? 0.0,
            'occurrence_count' => self::num($schedule['occurrence_count'] ?? null) ?? 1.0,
        ];
    }

    /**
     * Occurrence k is due on day start_day + k * interval_days.
     *
     * @param array{start_day: float, recurrence: string, interval_days: float, occurrence_count: float} $s
     */
    private static function dueDay(array $s, int $k): ?float
    {
        return match ($s['recurrence']) {
            'once' => $k === 0 ? $s['start_day'] : null,
            'fixed_count' => $k < $s['occurrence_count'] ? $s['start_day'] + $k * $s['interval_days'] : null,
            'ongoing' => $s['start_day'] + $k * $s['interval_days'],
            default => null,
        };
    }

    /**
     * The latest occurrence due on or before the day, or null when none is due yet.
     *
     * @param array{start_day: float, recurrence: string, interval_days: float, occurrence_count: float} $s
     */
    private static function latestDueBy(array $s, float $day): ?int
    {
        if ($day < $s['start_day']) {
            return null;
        }
        if ($s['recurrence'] === 'once') {
            return 0;
        }
        if ($s['interval_days'] <= 0) {
            return null;
        }
        $k = (int) floor(($day - $s['start_day']) / $s['interval_days']);

        return match ($s['recurrence']) {
            'fixed_count' => (int) min($s['occurrence_count'] - 1, $k),
            'ongoing' => $k,
            default => null,
        };
    }

    /** @param array{start_day: float, recurrence: string, interval_days: float, occurrence_count: float} $s */
    private static function finalIndex(array $s): ?int
    {
        return match ($s['recurrence']) {
            'once' => 0,
            'fixed_count' => (int) $s['occurrence_count'] - 1,
            default => null,
        };
    }

    /**
     * The cycles of `paid` that the charge's first occurrence pays for: due from its day until its next.
     *
     * @param array{start_day: float, recurrence: string, interval_days: float, occurrence_count: float} $charge
     * @param array{start_day: float, recurrence: string, interval_days: float, occurrence_count: float} $paid
     * @return list<int>
     */
    private static function paidCycles(array $charge, array $paid): array
    {
        $from = self::dueDay($charge, 0);
        if ($from === null) {
            return [];
        }
        $next = self::dueDay($charge, 1);
        $before = self::latestDueBy($paid, $from - 1);
        $first = $before === null ? 0 : $before + 1;
        $through = $next === null
            ? (self::finalIndex($paid) ?? $first + self::MAX_CYCLES_AHEAD - 1)
            : self::latestDueBy($paid, $next - 1);
        if ($through === null || $through < $first) {
            return [];
        }

        return range($first, $through);
    }

    /**
     * What a shipment or lab test step costs in one of its cycles. Null when a price is unreadable.
     *
     * @param array<string, mixed> $step
     */
    private static function cycleAmount(array $step, int $cycle): ?float
    {
        $type = $step['type'] ?? null;
        if ($type === 'lab_test') {
            return self::num($step['price_cents'] ?? null);
        }
        if ($type !== 'shipment') {
            return 0.0;
        }

        $cycles = self::rows($step['items_by_cycle'] ?? null);
        if ($cycles === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach (self::rows($cycles[min($cycle, count($cycles) - 1)]['items'] ?? null) as $item) {
            $price = self::num($item['price_cents'] ?? null);
            $quantity = self::num($item['quantity'] ?? null);
            if ($price === null || $quantity === null) {
                return null;
            }
            $sum += $price * $quantity;
        }

        return $sum;
    }

    /** A JSON number as a float; null for anything else. */
    private static function num(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    private static function wholeCents(?float $amount): bool
    {
        return $amount !== null && $amount >= 0 && floor($amount) === $amount;
    }

    /** An ObjectId as lower-case hex; null for anything else. */
    private static function idText(mixed $value): ?string
    {
        if (is_array($value) && is_string($value['$oid'] ?? null)) {
            $value = $value['$oid'];
        }

        return is_string($value) && preg_match(self::OBJECT_ID, $value) === 1 ? strtolower($value) : null;
    }

    /**
     * The array entries of a list, skipping anything that is not an array.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
