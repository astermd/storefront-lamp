<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

/**
 * Whether an order takes the money now or only reserves it.
 *
 * The neutral name for what one provider spells `action: "process"` versus
 * `action: "authorize"` and another spells `/order/import/` versus
 * `/order/preauth/`. `[14.3]` forbids anything above {@see PaymentAdapter}
 * naming either, so this is what the storefront decides in and what
 * {@see OrderEnvelope} carries; translating it into a provider's own verb is
 * the adapter's job and nobody else's.
 *
 * An enum rather than a bool because the two are not "the normal thing and a
 * flag": they are two settlement models with different downstream truths — one
 * ends with money moved, the other ends with money owed and a second call
 * still to make — and a `bool $preauth` at a dozen call sites would make the
 * capture path read as a negation of the authorize path.
 *
 * **Authorize is the strictest mode, and strictness wins a cart.** A cart is
 * one order (`[13.19]`), so a cart holding both kinds has to resolve to a
 * single action, and it resolves to the one that takes no money: capturing a
 * product the deployment marked hold-until-event is an unasked-for charge,
 * while authorising one marked charge-now is a charge deferred by a step that
 * already has to happen. Only the first needs a refund to undo.
 * {@see self::strictest()} is that rule, in one place, rather than a
 * comparison repeated wherever a cart is priced.
 */
enum SettlementMode: string
{
    /** Create and charge in one call — the shipped default, and what every deployment did before this existed. */
    case Capture = 'capture';

    /** Reserve the funds and stop. Something outside this storefront decides when {@see PaymentAdapter::capture()} runs. */
    case Authorize = 'authorize';

    /**
     * One configured or catalog-authored value, or **null when it is not one
     * of these**.
     *
     * Null rather than a default, because the two callers want opposite things
     * from an unrecognised spelling: `config:validate` wants to report it, and
     * the runtime wants to carry on serving (`[20.1]`). Deciding here would
     * force one of them to un-decide it.
     *
     * Absent is not unrecognised. A product that says nothing about settlement
     * is not asking for anything, so `null` and `''` answer null the same way
     * a typo does — and the caller that distinguishes them is the validator,
     * which has the key's presence to look at and this method does not.
     */
    public static function parse(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * The mode a set of them collapses to: authorize if any one of them is.
     *
     * Variadic and total, so the empty case is answerable rather than a
     * special case at each call site — no lines asking for anything is the
     * shipped default, which is {@see self::Capture}.
     */
    public static function strictest(self ...$modes): self
    {
        foreach ($modes as $mode) {
            if ($mode === self::Authorize) {
                return self::Authorize;
            }
        }

        return self::Capture;
    }

    public function isAuthorize(): bool
    {
        return $this === self::Authorize;
    }
}
