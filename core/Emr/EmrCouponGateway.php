<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Sdk\Exception\NotFoundException;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PromotionQuote;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see CouponGateway} over the EMR's `POST /coupons/validate`.
 *
 * Each line is named the way the EMR's order will name it -- by the plan it is
 * sold on, or by the EMR's product and variant -- and never by an amount: the
 * EMR prices the cart from its own catalogue, which is why the discount it
 * answers is the one it will honour when the order is read back.
 *
 * **The buyer is named by email when the journey holds one**, so a coupon
 * restricted to particular patients can match them; without one the field is
 * left out rather than sent empty, and only a coupon open to anyone applies.
 *
 * **Nothing is counted.** The EMR checks the code and answers what it would
 * take off; the redemption is recorded when the paid order reaches it.
 *
 * **The shopper is named when they can be.** A coupon for some patients, or
 * with a per-patient limit, is checked against the patient the EMR finds for
 * the buyer's email, or else for the journey's opportunity; with neither it
 * fits only a coupon open to anyone. Each is sent only in a shape the EMR
 * accepts, because a malformed one is a 400 for the whole request -- a
 * half-typed email would make every code unavailable rather than leave the
 * shopper unnamed.
 *
 * **Its refusals are shown as they are written.** A 404 is an unknown code. A
 * 422 is every other reason -- expired, used up, not for this product, a plan
 * whose first payment takes no coupons -- and the EMR's message for it is
 * written for the buyer, so it is their notice. Anything else is the EMR
 * being unreachable, which is a code that did not apply this time rather than
 * an error page (`[13.14]`), and its message is not shown or logged: it is the
 * EMR's own free text (`[20.6]`).
 *
 * Unavailable until the SDK this deployment runs offers the coupons resource,
 * so a storefront on an older SDK hides the control rather than refusing every
 * code typed into it.
 */
final class EmrCouponGateway implements CouponGateway
{
    private const string UNKNOWN_CODE = "That discount code isn't valid.";

    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function quote(string $code, array $lines, ?string $email = null, ?string $opportunityId = null): PromotionQuote
    {
        $code = trim($code);
        $email = $email === null || trim($email) === '' ? null : trim($email);
        $items = self::items($lines);

        if ($code === '' || $items === null || !$this->available()) {
            return PromotionQuote::rejected($code, 'unsupported');
        }

        try {
            $data = $this->client()->coupons()
                ->validate($code, $items, null, self::email($email), self::opportunityId($opportunityId))
                ->data();
        } catch (NotFoundException) {
            return PromotionQuote::rejected($code, 'unknown_code', self::UNKNOWN_CODE);
        } catch (ApiException $e) {
            if ($e->statusCode() === 422) {
                $message = $e->envelope()['message'] ?? null;

                return PromotionQuote::rejected($code, 'denied', is_string($message) && $message !== '' ? $message : null);
            }

            $this->unavailable($e);

            return PromotionQuote::rejected($code, 'unavailable');
        } catch (\Throwable $e) {
            $this->unavailable($e);

            return PromotionQuote::rejected($code, 'unavailable');
        }

        $discount = $data['discount_cents'] ?? null;
        $couponId = $data['coupon_id'] ?? null;

        if (!is_int($discount) || !is_string($couponId) || $couponId === '') {
            $this->log->warning('coupon.unreadable_quote', ['code' => $code]);

            return PromotionQuote::rejected($code, 'unreadable');
        }

        return PromotionQuote::accepted($code, $discount, $couponId, $this->lineDiscounts($data['items'] ?? null, $lines, $discount));
    }

    /**
     * How the EMR spread the discount across the lines, by line slug, or none.
     *
     * The EMR answers with the lines it discounted, named the way they were
     * sent: by plan, or by product and variant. Each is matched back to the
     * cart line it names. An answer that names a line the cart does not have,
     * or whose parts do not add up to the discount, is not a breakdown of this
     * one, so the summary shows the total alone rather than two figures that
     * disagree. The total is what is charged either way.
     *
     * @param list<OrderLine> $lines
     * @return array<string, int>
     */
    private function lineDiscounts(mixed $items, array $lines, int $total): array
    {
        if (!is_array($items) || $items === []) {
            return [];
        }

        $discounts = [];
        foreach ($items as $item) {
            $cents = is_array($item) ? ($item['discount_cents'] ?? null) : null;
            $line = is_array($item) ? self::lineNamedBy($item, $lines) : null;

            if (!is_int($cents) || $cents < 0 || $line === null) {
                $this->log->warning('coupon.unmatched_lines', ['lines' => count($lines), 'items' => count($items)]);

                return [];
            }

            $discounts[$line->slug] = ($discounts[$line->slug] ?? 0) + $cents;
        }

        if (array_sum($discounts) !== $total) {
            $this->log->warning('coupon.unmatched_lines', ['discount_cents' => $total, 'lines_cents' => array_sum($discounts)]);

            return [];
        }

        return $discounts;
    }

    /**
     * The cart line an answered item names, by the same ids {@see self::items()}
     * sent it with: a plan line by its plan, any other by product and variant.
     *
     * @param array<mixed> $item
     * @param list<OrderLine> $lines
     */
    private static function lineNamedBy(array $item, array $lines): ?OrderLine
    {
        foreach ($lines as $line) {
            if ($line->planId !== null) {
                if (($item['plan_id'] ?? null) === $line->planId) {
                    return $line;
                }

                continue;
            }

            if (($item['product_id'] ?? null) === $line->emrProductId && ($item['variant_id'] ?? null) === $line->emrVariantId) {
                return $line;
            }
        }

        return null;
    }

    public function available(): bool
    {
        return method_exists(AsterMDClient::class, 'coupons');
    }

    /**
     * The lines as the EMR names them, or null when one cannot be named: a
     * code priced against part of the cart is a discount on some other cart.
     *
     * @param list<OrderLine> $lines
     * @return list<array<string, mixed>>|null
     */
    private static function items(array $lines): ?array
    {
        $items = [];

        foreach ($lines as $line) {
            if ($line->planId !== null) {
                $items[] = ['plan_id' => $line->planId, 'quantity' => $line->quantity];

                continue;
            }

            if ($line->emrProductId === null || $line->emrVariantId === null) {
                return null;
            }

            $items[] = [
                'product_id' => $line->emrProductId,
                'variant_id' => $line->emrVariantId,
                'quantity' => $line->quantity,
            ];
        }

        return $items === [] ? null : $items;
    }

    private static function email(?string $email): ?string
    {
        $email = trim((string) $email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private static function opportunityId(?string $opportunityId): ?string
    {
        return $opportunityId !== null && preg_match('/^[a-f\d]{24}$/i', $opportunityId) === 1 ? $opportunityId : null;
    }

    private function unavailable(\Throwable $e): void
    {
        $this->log->info('coupon.unavailable', [
            'exception' => $e::class,
            'status' => $e->getCode(),
        ]);
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
