<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Abandonment;

use AsterMD\Sdk\Enum\CheckoutEvent;
use AsterMD\Storefront\Journey\JourneyState;

/**
 * Which of `[21.10]`'s six states one stopped journey is in, decided from
 * durable server-side state alone.
 *
 * **Deepest evidence wins, and that is the whole design.** A stopped journey
 * almost always satisfies several of the six descriptions at once: someone
 * whose card was declined also has a populated cart, a captured lead, a
 * submitted questionnaire and a visited checkout, and every one of those
 * facts is still sitting on the row. Six independent predicates would
 * therefore answer "yes" five times and leave the caller to guess, so the
 * rungs below are ordered by {@see AbandonmentState::depth()} and the deepest
 * one that holds is the answer. The ladder reads `depth()` rather than
 * re-encoding the order as a literal list, so the enum stays the single place
 * funnel order is written down.
 *
 * **Nothing here is time-based.** "Has this journey stopped moving" is
 * `sessions.updated_at` and belongs to {@see AbandonmentSweep}; this class
 * answers only "where had it got to". Splitting them is what lets the six
 * states be tested without a clock, and what keeps `[21.12]`'s
 * server-side-and-time-based rule in one place instead of six.
 *
 * `[20.1]`: read-only. It mutates nothing on the state it is handed and
 * writes nothing anywhere.
 */
final class AbandonmentClassifier
{
    /**
     * The state this journey stopped in, or null when it is not abandoned at
     * all.
     *
     * Null is an ordinary, frequent answer and never an error: a visitor who
     * landed and left took no step to abandon, a journey the server ruled
     * ineligible is not waiting to be nudged, and a completed purchase is the
     * opposite of an abandonment. Every one of those must produce **no signal
     * at all** rather than a signal the email automation would then have to
     * learn to ignore.
     */
    public function classify(JourneyState $state): ?AbandonmentState
    {
        // `[17.1]`: a journey with a completion timestamp bought and finished.
        // It also still carries the checkout visit and the placed order that
        // several rungs below read, so this guard comes before the ladder
        // rather than being folded into it.
        if ($state->isComplete()) {
            return null;
        }

        // `[10.46]`: a disqualification is the server's standing verdict that
        // this visitor may not proceed. Inviting them back to a funnel that
        // will stop them again is worse than sending nothing, and the verdict
        // is durable precisely so a resumed journey still finds it.
        if ($state->isDisqualified()) {
            return null;
        }

        // A placed order settles every rung below the upsell at once: the
        // buyer reached the end of the purchase, so nothing earlier was
        // abandoned — even though the cart, lead, checkout-visit and consent
        // evidence for those rungs is all still standing on this row. Without
        // this branch a buyer who simply never loaded an offer page would be
        // reported as having abandoned checkout, and be sent a "you left
        // something behind" email about an order they paid for.
        if ($state->placedOrders !== []) {
            return self::offerLeftUnanswered($state) ? AbandonmentState::Upsell : null;
        }

        $deepest = null;

        foreach (self::evidence($state) as [$candidate, $holds]) {
            if ($holds && ($deepest === null || $candidate->depth() > $deepest->depth())) {
                $deepest = $candidate;
            }
        }

        return $deepest;
    }

    /**
     * What each rung's condition actually reads on this journey, in the
     * enum's own order.
     *
     * Every entry names the durable field that stands in for the spec's
     * wording, because in several cases the wording and the field are not
     * obviously the same thing:
     *
     * - **Cart** — `[21.10]`'s "cart populated". The durable copy (`[19.8]`),
     *   not the PHP-session working copy, which no sweep can see.
     * - **Lead** — "name and email captured". Read as *the EMR holds an
     *   opportunity for this journey*, because `[11.6]` is the rule that
     *   decides when one is created and its threshold is exactly a first name
     *   and an email. That makes this rung definition-free: it needs no
     *   teleform `db_fields` map, so a sweep never has to load a form to know
     *   whether a lead exists.
     *
     *   Its second clause, "form never completed", is **not** read as a
     *   condition, and the difference is stated rather than hidden. A journey
     *   that submitted every questionnaire and then never opened checkout
     *   satisfies no other rung: the six states have no name for it. Falling
     *   back to `cart_abandoned` there would send a "you left something in
     *   your basket" email to somebody who had just finished a medical
     *   questionnaire, so this rung takes it — a captured lead that went no
     *   further is the nearest true description the closed vocabulary offers,
     *   and `furthest_step` on the emitted signal carries the detail the state
     *   cannot.
     * - **Intake** — "started or partially completed, never submitted" is
     *   `formStatus` holding `in_progress` for some teleform.
     *   {@see JourneyState::storeAnswers()} writes that status on the first
     *   answer and {@see JourneyState::markFormCompleted()} replaces it on
     *   submission, so the map is authoritative and the answers themselves are
     *   never read — which is also what keeps clinical content out of this
     *   class entirely.
     * - **Checkout** — "checkout visited" is the `checkout_visited` funnel
     *   event, recorded once per journey on the first render of the page
     *   (`[4.13]`'s deduplication) and durable in `emrEvents`.
     * - **Payment** — "placement attempted and declined", read from the
     *   `order_declined` funnel event rather than inferred. This rung stood on
     *   the recorded consents until that event began being stamped locally,
     *   and the event is strictly the better marker: consents are written the
     *   moment a checkout submission passes validation, so they were also true
     *   of a submission refused before the provider was ever reached — a geo
     *   block, a rate limit, an attempt that could not be recorded. None of
     *   those is a decline, and each one would have been chased as one.
     *   `order_declined` is written only where the provider actually answered.
     *   `placedOrders` being empty is what makes it *declined* rather than
     *   *paid*, and it is guaranteed by the branch above.
     * - **Upsell** — see {@see self::offerLeftUnanswered()}.
     *
     * @return list<array{0: AbandonmentState, 1: bool}>
     */
    private static function evidence(JourneyState $state): array
    {
        return [
            [AbandonmentState::Cart, $state->cart !== []],
            [AbandonmentState::Lead, $state->opportunityId !== null],
            [AbandonmentState::Intake, in_array(JourneyState::FORM_IN_PROGRESS, $state->formStatus, true)],
            [AbandonmentState::Checkout, $state->hasEmrEvent(CheckoutEvent::CheckoutVisited->value)],
            [AbandonmentState::Payment, $state->hasEmrEvent(CheckoutEvent::OrderDeclined->value)],
        ];
    }

    /**
     * Whether an offer was shown and never answered (`[21.10]`'s "upsell
     * offered, never acted on").
     *
     * `offered` is written when the offer page renders and is *replaced* by
     * the outcome the moment the buyer answers — accepted, declined, skipped,
     * or charge-declined — so a surviving `offered` is precisely an offer that
     * was put in front of someone who then walked away. An empty outcome map
     * means no offer was ever shown, which is not an abandonment: a buyer owed
     * no offer and a buyer who was shown one and ignored it must not receive
     * the same email.
     */
    private static function offerLeftUnanswered(JourneyState $state): bool
    {
        return in_array(JourneyState::UPSELL_OFFERED, $state->upsellOutcomes, true);
    }
}
