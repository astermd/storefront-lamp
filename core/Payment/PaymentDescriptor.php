<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

use AsterMD\Storefront\Checkout\Totals;

/**
 * How an order was paid for, in the API's payment vocabulary.
 *
 * **This is not `payment_method`.** The EMR's checkout-event record carries a
 * `payment_method` field taking `apple_pay|google_pay|card|paypal`, and this
 * block's `type` takes `paypal|apple_pay|gpay|credit_card|pre_paid`. They are
 * two vocabularies for one fact and both are sent; collapsing them would
 * silently change what the older field means to everything already reading it.
 *
 * **An absent optional is an absent key.** `pre_auth_qa: false` asserts that
 * the QA mechanism was available and not used, which is not what a Vrio order
 * or a plain capture means — those have nothing to say about it at all. The
 * same holds for a held amount on an order that held nothing.
 *
 * **Dollars leave through {@see Totals::dollars()}.** The EMR accepts a cents
 * integer where it documents a float without complaining, so a skipped
 * conversion reports every held amount at a hundred times its size and nothing
 * downstream objects.
 */
final class PaymentDescriptor
{
    /** The only surface this storefront collects on (`[15.4c]`). */
    public const string TYPE_CREDIT_CARD = 'credit_card';

    /**
     * @param bool  $preAuth            whether the money was reserved rather than taken
     * @param ?bool $preAuthQa          whether the reservation used the provider's QA mechanism;
     *                                  null where the adapter has no such mechanism
     * @param ?int  $preAuthAmountCents the reserved amount, in cents; null when nothing was reserved
     */
    public function __construct(
        public readonly string $type,
        public readonly bool $preAuth,
        public readonly ?bool $preAuthQa,
        public readonly ?int $preAuthAmountCents,
        public readonly ?CardDescriptor $card,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payment = [
            'type' => $this->type,
            'pre_auth' => $this->preAuth,
        ];

        // Only a reservation that happened under the QA mechanism is worth
        // stating; everything else has no answer rather than a negative one.
        if ($this->preAuth && $this->preAuthQa === true) {
            $payment['pre_auth_qa'] = true;
        }

        if ($this->preAuthAmountCents !== null) {
            $payment['pre_auth_amount'] = Totals::dollars($this->preAuthAmountCents);
        }

        if ($this->card !== null) {
            $payment['card'] = $this->card->toArray();
        }

        return $payment;
    }
}
