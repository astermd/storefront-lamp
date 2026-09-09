<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Emr\CartGateway;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\IntakeGateway;
use AsterMD\Storefront\Forms\LeadGateway;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Payment\PaymentAdapter;

/**
 * Wraps each port `[20.9]` names in the decorator that times it.
 *
 * **One place rather than ten call-site edits, and one place rather than ten
 * `new` expressions in the container.** Instrumentation that lives at the call
 * sites drifts: the eleventh caller does not add it, and the boundary that
 * stops reporting is indistinguishable from the boundary that stopped being
 * called — which on this application is the same thing as a funnel that
 * quietly stopped converting (`[20.11]`).
 *
 * Every decorator here implements the port it wraps and returns exactly what
 * the port returned. Substituting one must be invisible to every caller, which
 * is what makes it safe to leave switched on in production and what stops the
 * instrumentation from becoming the failure (`[20.1]`).
 */
final class Instrumentation
{
    public function __construct(private readonly BoundaryTimer $timer)
    {
    }

    public function sessionGateway(SessionGateway $inner): SessionGateway
    {
        return new InstrumentedSessionGateway($inner, $this->timer);
    }

    public function cartGateway(CartGateway $inner): CartGateway
    {
        return new InstrumentedCartGateway($inner, $this->timer);
    }

    public function teleformGateway(TeleformGateway $inner): TeleformGateway
    {
        return new InstrumentedTeleformGateway($inner, $this->timer);
    }

    public function intakeGateway(IntakeGateway $inner): IntakeGateway
    {
        return new InstrumentedIntakeGateway($inner, $this->timer);
    }

    public function leadGateway(LeadGateway $inner): LeadGateway
    {
        return new InstrumentedLeadGateway($inner, $this->timer);
    }

    public function paymentAdapter(PaymentAdapter $inner): PaymentAdapter
    {
        return new InstrumentedPaymentAdapter($inner, $this->timer);
    }

    public function checkoutEventReporter(CheckoutEventReporter $inner): CheckoutEventReporter
    {
        return new InstrumentedCheckoutEventReporter($inner, $this->timer);
    }
}
