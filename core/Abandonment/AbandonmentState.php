<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Abandonment;

/**
 * The six abandonment states `[21.10]` requires the storefront to emit, and
 * the reason they are an enum rather than six booleans or a free string.
 *
 * `[21.10]`'s whole demand is that the states be **distinguishable**: a
 * classifier that answered "abandoned" and left the caller to guess which
 * kind, or that let two of these collapse into one another, would satisfy the
 * letter of "emit a state" and none of its purpose — the consuming email
 * automation (`[21.11a]`) sends a different message for each one, and a cart
 * nudge sent to somebody whose card was declined is worse than no message at
 * all. A closed vocabulary is what makes "these six are told apart" a fact the
 * type system holds rather than a promise a docblock makes.
 *
 * The order below is funnel order, and {@see self::depth()} makes that order
 * readable by code. It matters because a stopped journey usually satisfies
 * more than one of these descriptions at once — someone who reached checkout
 * also has a populated cart — so classification is a *deepest evidence wins*
 * ladder rather than six independent predicates. {@see AbandonmentClassifier}
 * owns that ladder and states each rung's evidence.
 *
 * The string values are the wire vocabulary: they are what a polled JSON
 * document carries and what the automation platform branches on, so renaming
 * one is a breaking change to an external consumer rather than a refactor.
 */
enum AbandonmentState: string
{
    /** Cart populated, then no activity for the configured interval (`[21.10]`). */
    case Cart = 'cart_abandoned';

    /** Name and email captured, no questionnaire ever submitted (`[21.10]`). */
    case Lead = 'lead_abandoned';

    /** A questionnaire started or partly answered, never submitted (`[21.10]`). */
    case Intake = 'intake_abandoned';

    /** Checkout visited, no placement attempted (`[21.10]`). */
    case Checkout = 'checkout_abandoned';

    /** A placement was attempted and produced no order, and was never retried (`[21.10]`). */
    case Payment = 'payment_abandoned';

    /** An upsell was offered and never acted on (`[21.10]`). */
    case Upsell = 'upsell_abandoned';

    /**
     * How far down the funnel this state sits, lowest first.
     *
     * Exposed so the classification ladder and its tests can talk about
     * ordering without either of them re-encoding the order as a literal list.
     * Nothing here reads it as a score: it is an ordinal, and the gaps between
     * the numbers mean nothing.
     */
    public function depth(): int
    {
        return match ($this) {
            self::Cart => 1,
            self::Lead => 2,
            self::Intake => 3,
            self::Checkout => 4,
            self::Payment => 5,
            self::Upsell => 6,
        };
    }
}
