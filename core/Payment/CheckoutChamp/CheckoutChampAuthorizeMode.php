<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

/**
 * Which of this provider's two authorization mechanisms a deployment uses.
 *
 * They are not two spellings of one thing; they reserve different amounts of
 * money, and the difference decides whether a capture can be relied on.
 *
 * **{@see self::Preauth}** posts `/order/preauth/` and settles with
 * `/order/import/`. It validates the card by charging a nominal amount and
 * refunding it, and **does not hold the order's value** -- so by the time the
 * capture runs the funds may be gone and the settle call can decline. It is the
 * older mechanism and still supported.
 *
 * **{@see self::Qa}** posts `/order/import/` with `forceQA: 1`, which puts the
 * order in `PENDING` review with the **full order amount held** on the card, and
 * settles with `/order/qa/` and `action: "APPROVE"`. Because the amount is
 * genuinely reserved, a later capture is far less likely to be declined. The
 * provider recommends it over the older mechanism, and it is the default here
 * for the same reason: a pre-authorization that does not reserve the money is
 * not doing the one job it was asked to do.
 *
 * **Global, never per product.** Unlike {@see \AsterMD\Storefront\Payment\SettlementMode},
 * which a single product can escalate, this is a property of how the deployment
 * is set up with its provider -- the same card, the same campaign, the same
 * merchant agreement. A cart cannot be half one mechanism and half the other,
 * and nothing about a product argues for one over the other.
 *
 * It is also **this provider's alone**. The other shipped adapter has one
 * mechanism, so the setting lives under `payment.checkout_champ.*` rather than
 * at the top of `config/payment.php`, where it would read as a question every
 * deployment has to answer.
 */
enum CheckoutChampAuthorizeMode: string
{
    /** `/order/preauth/` then `/order/import/`. Validates the card; does not reserve the order amount. */
    case Preauth = 'preauth';

    /** `/order/import/` with `forceQA: 1`, then `/order/qa/`. Reserves the order amount. */
    case Qa = 'qa';

    /**
     * One configured value, or **null when it is not one of these**.
     *
     * Null rather than a default, for the reason
     * {@see \AsterMD\Storefront\Payment\SettlementMode::parse()} gives: the
     * validator wants to report an unrecognised spelling and the runtime wants
     * to carry on serving, and deciding here would force one to un-decide it.
     */
    public static function parse(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function holdsTheOrderAmount(): bool
    {
        return $this === self::Qa;
    }
}
