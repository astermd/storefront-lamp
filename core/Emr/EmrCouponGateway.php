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
 * **Nothing is counted.** The EMR checks the code and answers what it would
 * take off; the redemption is recorded when the paid order reaches it.
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

    public function quote(string $code, array $lines): PromotionQuote
    {
        $code = trim($code);
        $items = self::items($lines);

        if ($code === '' || $items === null || !$this->available()) {
            return PromotionQuote::rejected($code, 'unsupported');
        }

        try {
            $data = $this->client()->coupons()->validate($code, $items)->data();
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

        return PromotionQuote::accepted($code, $discount, $couponId);
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
