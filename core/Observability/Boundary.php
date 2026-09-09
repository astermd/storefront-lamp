<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

/**
 * The external calls this storefront makes, named once (`[20.9]`).
 *
 * The storefront sits between two systems it does not own and degrades
 * silently by design (`[20.1]`), which means a broken dependency does not
 * surface as an error — it surfaces as a funnel that quietly stops
 * converting. An enum rather than a string at each call site so the set of
 * boundaries is enumerable: a reader can ask what is instrumented without
 * grepping for a logging call, and a boundary that gains a second
 * implementation cannot end up logged under two spellings.
 *
 * The value is the log event's suffix, so every line an operator wants is
 * `external.*` and every line about one dependency is `external.emr.*` or
 * `external.provider.*`.
 */
enum Boundary: string
{
    /** Minting and reading an analytics session (`[4.3]`, `[4.12]`). */
    case EmrSession = 'emr.session';

    /** Replacing the remote cart for a session (`[7.12]`). */
    case CartMirror = 'emr.cart_mirror';

    /** The teleform metadata read and the signed-URL definition fetch behind it (`[3.6]`). */
    case FormDefinition = 'emr.form_definition';

    /** One progressive save of a visitor's answers (`[10.24]`). */
    case FormSave = 'emr.form_save';

    /** Creating or amending the opportunity a lead becomes (`[9.7]`). */
    case OpportunityWrite = 'emr.opportunity_write';

    /** Telling the EMR about orders the provider has already charged (`[17.2]`). */
    case TreatmentSync = 'emr.treatment_sync';

    /**
     * The funnel events of §17, which travel the same transport as the
     * treatment sync and fail the same way. Not named by `[20.9]` in terms,
     * but they are external calls on the checkout path and a checkout funnel
     * that has stopped reporting is exactly what `[20.11]` asks to be visible.
     */
    case CheckoutEvent = 'emr.checkout_event';

    /** Submitting an order to the payment provider (`[13.24]`). */
    case ProviderPlacement = 'provider.placement';

    /** Validating and pricing a promotion code (`[13.14]`). */
    case PromotionQuote = 'provider.promotion';

    /** Asking the provider what orders it holds, for the reverse sweep (`[21.9a]`). */
    case ProviderOrderSearch = 'provider.order_search';
}
