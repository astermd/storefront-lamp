<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Funnel;

use AsterMD\Storefront\Journey\JourneyState;

/**
 * The one place that decides whether a journey has got further than it had,
 * and the only writer of {@see JourneyState::$furthestStep}'s advance rule
 * (`[21.7]`).
 *
 * **Why the order is declared here rather than read from the flow.**
 * `config/funnel.php` is the step *vocabulary* — every name below is one of
 * its keys, and {@see self::ORDER} may never contain a spelling it does not
 * declare — but its array order is not a progression and nothing in
 * {@see FlowDefinition} treats it as one. `not_eligible` sits between
 * `checkout` and `upsell` there while being a terminal off-ramp reachable from
 * anywhere, which is the plainest proof that declaration order and funnel
 * order are different facts. Ranking by array position would therefore make a
 * monotonicity rule out of an incidental detail of a config file, and a later
 * reordering of that file — a refactor by every appearance — would silently
 * change which steps can overwrite which.
 *
 * `not_eligible` is deliberately absent for the same reason it cannot be
 * ranked: it is not a step anybody completes. A disqualified journey is
 * excluded from abandonment by its own verdict rather than by how far it got
 * (`[10.46]`, {@see \AsterMD\Storefront\Abandonment\AbandonmentClassifier::classify()}),
 * so nothing here needs a rung for it.
 *
 * **Monotonic, because a visitor who reached checkout and went back to edit
 * their questionnaire has not un-reached checkout.** Every writer calls
 * {@see self::advance()} rather than assigning, so "only ever forwards" is a
 * property of this class instead of a habit four call sites have to keep.
 *
 * `[20.1]`: this is bookkeeping on paths that handle money. Every method here
 * is pure array work on an in-memory object — it opens nothing, reports
 * nothing and cannot throw — which is what lets a charge path call it without
 * a guard around it.
 */
final class FurthestStep
{
    /**
     * The funnel's steps in the order a journey completes them.
     *
     * Every entry is a key of `config/funnel.php`'s `steps` table, which
     * {@see \AsterMD\Storefront\Tests\Funnel\FurthestStepTest} holds to: a name that
     * is not a declared step is a private spelling, and the abandonment
     * signal, the router and the guard all read this field.
     *
     * @var list<string>
     */
    public const array ORDER = [
        'home',
        'prequalification',
        'intake',
        'intake.medical',
        'verify',
        'checkout',
        'upsell',
        'receipt',
    ];

    /**
     * Records that this journey has now completed `$step`, if that is further
     * than it had already got.
     *
     * Takes a nullable state so a caller on a journey the server never saw
     * start — a checkout placed with no session (`[20.8]`) — needs no branch of
     * its own. Nothing about a missing journey is an error here: there is
     * simply nowhere to write.
     */
    public static function advance(?JourneyState $state, string $step): void
    {
        if ($state === null || !self::isBeyond($state->furthestStep, $step)) {
            return;
        }

        $state->furthestStep = $step;
    }

    /**
     * Whether `$candidate` is further along the funnel than `$current`.
     *
     * Two answers here are decided rather than obvious:
     *
     * - **An unrecognised candidate is refused outright.** Writing a name the
     *   funnel does not declare would put a step into the abandonment signal
     *   that no consumer can turn back into a URL, and a silent disagreement
     *   with `config/funnel.php` is exactly what {@see self::ORDER} exists to
     *   prevent. Refusing leaves the last true value standing.
     * - **An unrecognised current value is overwritten.** It can only be a
     *   spelling from an earlier release or a hand-edited row, so it ranks
     *   nowhere and blocking on it would freeze the field permanently. A known
     *   step is a better answer than an unplaceable one.
     */
    public static function isBeyond(?string $current, string $candidate): bool
    {
        $rank = array_search($candidate, self::ORDER, true);
        if ($rank === false) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        $held = array_search($current, self::ORDER, true);

        return $held === false || $rank > $held;
    }
}
