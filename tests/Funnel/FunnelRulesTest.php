<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Funnel\FunnelRules;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The one place the funnel answers "which questionnaire does this cart call
 * for", asserted directly.
 *
 * Three collaborators depend on these answers and must never disagree: the
 * routing decision picks the step, the step guard decides whether a visitor
 * may stay on it, and the questionnaire page decides which form to draw. Two
 * of the three agreeing is not enough — a guard that waits for a form the page
 * never serves strands the visitor at a different URL on every hop, which no
 * browser reports as a loop.
 *
 * The cases below are deliberately built on carts with more than one form in
 * them. `[8.0f]` allows one prescription per order, but free attachments, OTC
 * lines and accepted order bumps share the cart and can each name a
 * questionnaire, so "there is only ever one line to look at" is the assumption
 * these rules exist to remove.
 */
final class FunnelRulesTest extends TestCase
{
    /**
     * The page must draw the form the guard is waiting on, not the first one
     * the cart happens to name.
     */
    public function testTheFormToCollectIsTheOneStillOutstanding(): void
    {
        $rules = self::rules([
            'rx-a' => ['teleform_id' => 'tf-a'],
            'otc-b' => ['teleform_id' => 'tf-b'],
        ]);
        $cart = self::cart('rx-a', 'otc-b');

        $state = new JourneyState();
        $state->markFormCompleted('tf-a');

        self::assertSame(['tf-a', 'tf-b'], $rules->intakeForms($cart));
        self::assertSame('tf-b', $rules->outstandingIntakeForm($cart, $state));
        self::assertSame('tf-b', $rules->intakeFormToCollect($cart, $state), 'the page and the guard name the same form');
    }

    /**
     * Nothing left to ask is an answer, not a fallback. A step reached with
     * every form it collects finished has no identifier to hand its renderer,
     * and manufacturing one would file answers against a questionnaire nobody
     * asked for (`[20.8]`, `[21.9b]`).
     */
    public function testThereIsNoFormToCollectOnceEveryFormIsFinished(): void
    {
        $rules = self::rules(['rx-a' => ['teleform_id' => 'tf-a']]);
        $cart = self::cart('rx-a');

        $state = new JourneyState();
        $state->markFormCompleted('tf-a');

        self::assertNull($rules->outstandingIntakeForm($cart, $state));
        self::assertNull($rules->intakeFormToCollect($cart, $state));
    }

    /**
     * A standing hard stop outranks completion, because `[10.43]` makes a
     * termination correctable: the terminal page sends the visitor back to the
     * questionnaire that stopped them, and a form already marked complete when
     * the stop fired would otherwise leave that step with nothing to show.
     *
     * The guard is deliberately *not* moved by this — it refuses a disqualified
     * journey outright — so this clause opens the form without opening checkout.
     */
    public function testAStandingHardStopIsTheFormToCollectEvenWhenComplete(): void
    {
        $rules = self::rules([
            'rx-a' => ['teleform_id' => 'tf-a'],
            'otc-b' => ['teleform_id' => 'tf-b'],
        ]);
        $cart = self::cart('rx-a', 'otc-b');

        $state = new JourneyState();
        $state->markFormCompleted('tf-a');
        $state->markFormCompleted('tf-b');
        $state->recordDisqualification('tf-a', 'rule-1');

        self::assertNull($rules->outstandingIntakeForm($cart, $state), 'nothing is owed');
        self::assertSame('tf-a', $rules->intakeFormToCollect($cart, $state), 'but the stopped form is correctable');
    }

    /** A stop recorded against a form this step does not collect leaves the step's own answer alone. */
    public function testAHardStopOnAnotherStepsFormDoesNotChangeThisStep(): void
    {
        $rules = self::rules([
            'rx-a' => [
                'requires_prequalification' => true,
                'prequalification_teleform_id' => 'tf-elig',
                'teleform_id' => 'tf-med',
            ],
        ]);
        $cart = self::cart('rx-a');

        $state = new JourneyState();
        $state->recordDisqualification('tf-elig', 'rule-1');

        self::assertSame('tf-elig', $rules->prequalificationFormToCollect($cart, $state));
        self::assertSame('tf-med', $rules->intakeFormToCollect($cart, $state));
    }

    /**
     * Pre-qualification is opt-in twice (`[8.2]`). A form named without the
     * opt-in is not a gate, and so is not a form the step may collect either —
     * serving it would let a hard rule inside it terminate a journey the funnel
     * never required to take that questionnaire.
     */
    public function testAFormNamedWithoutTheOptInIsNeverCollected(): void
    {
        $rules = self::rules([
            'rx-a' => ['prequalification_teleform_id' => 'tf-elig', 'teleform_id' => 'tf-med'],
        ]);
        $cart = self::cart('rx-a');

        self::assertSame([], $rules->prequalificationForms($cart));
        self::assertNull($rules->prequalificationFormToCollect($cart, null));
        self::assertFalse($rules->collectedAtPrequalification('tf-elig', $cart));
    }

    /**
     * Both declarations have to come from the same cart line, so an earlier
     * line's unopted form does not become what the eligibility step asks.
     */
    public function testTheOptInAndTheFormItNamesComeFromOneLine(): void
    {
        $rules = self::rules([
            'otc-a' => ['prequalification_teleform_id' => 'tf-a'],
            'rx-b' => ['requires_prequalification' => true, 'prequalification_teleform_id' => 'tf-b'],
        ]);
        $cart = self::cart('otc-a', 'rx-b');

        self::assertSame(['tf-b'], $rules->prequalificationForms($cart));
        self::assertSame('tf-b', $rules->prequalificationFormToCollect($cart, null));
        self::assertFalse($rules->collectedAtPrequalification('tf-a', $cart));
        self::assertTrue($rules->collectedAtPrequalification('tf-b', $cart));
    }

    /**
     * With no journey to read, every named form counts as unfinished — so the
     * step draws its first form rather than forwarding a visitor whose progress
     * an outage has made unknowable.
     */
    public function testAnUnknowableJourneyStillHasAFormToCollect(): void
    {
        $rules = self::rules([
            'rx-a' => ['teleform_id' => 'tf-a'],
            'otc-b' => ['teleform_id' => 'tf-b'],
        ]);

        self::assertSame('tf-a', $rules->intakeFormToCollect(self::cart('rx-a', 'otc-b'), null));
    }

    /**
     * Which step collects a given form, asked the way the terminal page asks it
     * — it has a form id from the journey and needs the step that put it on
     * screen.
     */
    public function testTheStepThatCollectsAFormIsResolvedFromTheCart(): void
    {
        $rules = self::rules([
            'rx-a' => [
                'requires_prequalification' => true,
                'prequalification_teleform_id' => 'tf-elig',
                'teleform_id' => 'tf-med',
            ],
        ]);
        $cart = self::cart('rx-a');

        self::assertTrue($rules->collectedAtPrequalification('tf-elig', $cart));
        self::assertFalse($rules->collectedAtPrequalification('tf-med', $cart));
        self::assertFalse($rules->collectedAtPrequalification('tf-unknown', $cart));
    }

    /** @param array<string, array<string, mixed>> $products */
    private static function rules(array $products): FunnelRules
    {
        return new FunnelRules(new FakeCatalog($products));
    }

    private static function cart(string ...$slugs): Cart
    {
        $cart = new Cart();
        foreach ($slugs as $index => $slug) {
            $cart->put(new CartLine($slug, ucfirst($slug), $index === 0 ? 'rx' : 'otc', null, null, 1, 1000, $slug . '-v1'));
        }

        return $cart;
    }
}
