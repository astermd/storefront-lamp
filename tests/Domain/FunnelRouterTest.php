<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Domain;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Journey\JourneyState;
use PHPUnit\Framework\TestCase;

final class FunnelRouterTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function catalogData(): array
    {
        return [
            'otc-item' => ['slug' => 'otc-item', 'name' => 'OTC Item', 'kind' => 'otc'],
            'plain-rx' => [
                'slug' => 'plain-rx', 'name' => 'Plain Rx', 'kind' => 'rx',
                'teleform_id' => 'tf-medical',
            ],
            'prequal-rx' => [
                'slug' => 'prequal-rx', 'name' => 'Prequal Rx', 'kind' => 'rx',
                'requires_prequalification' => true,
                'prequalification_teleform_id' => 'tf-pre',
                'teleform_id' => 'tf-medical',
            ],
        ];
    }

    private function router(): FunnelRouter
    {
        return new FunnelRouter(new FakeCatalog($this->catalogData()));
    }

    /**
     * An Rx line always carries a chosen plan here, because that is the state
     * every routing question below is about. {@see self::unplannedLine()} is
     * the deliberate exception.
     */
    private static function line(string $slug, string $kind): CartLine
    {
        return new CartLine(
            slug: $slug,
            name: ucfirst($slug),
            kind: $kind,
            emrProductId: null,
            parentSlug: null,
            variantId: $kind === 'rx' ? 'plan-monthly' : null,
        );
    }

    private static function unplannedLine(string $slug): CartLine
    {
        return new CartLine(
            slug: $slug,
            name: ucfirst($slug),
            kind: 'rx',
            emrProductId: null,
            parentSlug: null,
            variantId: null,
        );
    }

    private function cartWith(FakeCatalog $catalog, string ...$slugs): Cart
    {
        $cart = new Cart();
        foreach ($slugs as $slug) {
            $kind = (string) ($catalog->product($slug)['kind'] ?? 'otc');
            $cart->put(self::line($slug, $kind));
        }

        return $cart;
    }

    private function cartWithUnplannedRx(FakeCatalog $catalog, string $slug): Cart
    {
        $cart = new Cart();
        $cart->put(self::unplannedLine($slug));

        return $cart;
    }

    public function testAnEmptyCartRoutesHome(): void
    {
        self::assertSame('home', $this->router()->nextStep(new Cart()));
    }

    public function testAProductOptingIntoPrequalificationGetsTheDedicatedStep(): void
    {
        $catalog = new FakeCatalog($this->catalogData());
        $cart = $this->cartWith($catalog, 'otc-item', 'prequal-rx');

        self::assertSame('prequalification', $this->router()->nextStep($cart));
    }

    public function testAnyPrescriptionRoutesToItsQuestionnaire(): void
    {
        $catalog = new FakeCatalog($this->catalogData());
        $cart = $this->cartWith($catalog, 'plain-rx');

        self::assertSame('intake.medical', $this->router()->nextStep($cart));
    }

    public function testACartOfAccessoriesOnlyRoutesToCheckout(): void
    {
        $catalog = new FakeCatalog($this->catalogData());
        $cart = $this->cartWith($catalog, 'otc-item');

        self::assertSame('checkout', $this->router()->nextStep($cart));
    }

    public function testPrequalificationIsOptInSoAPlainRxDoesNotGetIt(): void
    {
        $catalog = new FakeCatalog($this->catalogData());
        $cart = $this->cartWith($catalog, 'plain-rx');

        self::assertSame('intake.medical', $this->router()->nextStep($cart));
    }

    public function testACartWithNoQuestionnaireGoesStraightToCheckout(): void
    {
        // [8.3]: a product declaring no teleform has nothing to ask, so the
        // intake step is not merely satisfied -- it is not part of this funnel.
        $catalog = new FakeCatalog([
            'multivitamin' => ['slug' => 'multivitamin', 'kind' => 'rx', 'teleform_id' => null],
        ]);
        $cart = $this->cartWith($catalog, 'multivitamin');

        self::assertSame('checkout', (new FunnelRouter($catalog))->nextStep($cart, null));
    }

    public function testACartWithAnOutstandingQuestionnaireGoesToTheMedicalStep(): void
    {
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-1'],
        ]);
        $cart = $this->cartWith($catalog, 'semaglutide');

        self::assertSame('intake.medical', (new FunnelRouter($catalog))->nextStep($cart, null));
    }

    public function testACompletedQuestionnaireAdvancesToCheckout(): void
    {
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-1'],
        ]);
        $cart = $this->cartWith($catalog, 'semaglutide');
        $state = new JourneyState();
        $state->markFormCompleted('tf-1');

        self::assertSame('checkout', (new FunnelRouter($catalog))->nextStep($cart, $state));
    }

    public function testPrequalificationIsOnlyAStepWhenAFormIsAlsoNamed(): void
    {
        // [8.2]: opting in is two declarations, not one. A product that wants the
        // step but names no form has nothing to collect there, and routing to an
        // empty step is a dead end.
        $catalog = new FakeCatalog([
            'testosterone' => [
                'slug' => 'testosterone',
                'kind' => 'rx',
                'requires_prequalification' => true,
                'prequalification_teleform_id' => null,
                'teleform_id' => 'tf-1',
            ],
        ]);
        $cart = $this->cartWith($catalog, 'testosterone');

        self::assertSame('intake.medical', (new FunnelRouter($catalog))->nextStep($cart, null));
    }

    public function testPrequalificationIsTheStepWhenBothAreDeclared(): void
    {
        $catalog = new FakeCatalog([
            'testosterone' => [
                'slug' => 'testosterone',
                'kind' => 'rx',
                'requires_prequalification' => true,
                'prequalification_teleform_id' => 'tf-pre',
                'teleform_id' => 'tf-1',
            ],
        ]);
        $cart = $this->cartWith($catalog, 'testosterone');

        self::assertSame('prequalification', (new FunnelRouter($catalog))->nextStep($cart, null));
    }

    public function testAFinishedPrequalificationFallsThroughToTheMedicalStep(): void
    {
        $catalog = new FakeCatalog([
            'testosterone' => [
                'slug' => 'testosterone',
                'kind' => 'rx',
                'requires_prequalification' => true,
                'prequalification_teleform_id' => 'tf-pre',
                'teleform_id' => 'tf-1',
            ],
        ]);
        $cart = $this->cartWith($catalog, 'testosterone');
        $state = new JourneyState();
        $state->markFormCompleted('tf-pre');

        self::assertSame('intake.medical', (new FunnelRouter($catalog))->nextStep($cart, $state));
    }

    public function testAnRxLineWithNoChosenPlanRoutesHomeRatherThanIntoTheFunnel(): void
    {
        // The mini-cart drawer on the home page shows the cart intact, so the
        // visitor can see what is wrong. Routing them deeper into a funnel whose
        // next step they cannot satisfy is what produces a redirect loop.
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => null],
        ]);
        $cart = $this->cartWithUnplannedRx($catalog, 'semaglutide');

        self::assertSame('home', (new FunnelRouter($catalog))->nextStep($cart, null));
    }

    public function testAJourneyThatHasPlacedAnOrderRoutesToItsReceiptRatherThanHome(): void
    {
        // [13.32] clears the cart on a successful checkout, so a buyer who
        // re-submits arrives with nothing in it. Answering "home" for that
        // cart sends someone who has just paid to the front page; the receipt
        // is the page they are owed, and `order_placed` is satisfied for this
        // journey so the destination cannot bounce them back.
        $catalog = new FakeCatalog([]);
        $state = new JourneyState();
        $state->recordPlacedOrder('34660');

        self::assertSame('receipt', (new FunnelRouter($catalog))->nextStep(new Cart(), $state));
    }

    public function testAnEmptyCartWithNoPlacedOrderStillRoutesHome(): void
    {
        // The other half of the case above: without a placed order there is
        // nothing on the receipt to show, and `order_placed` would bounce the
        // visitor straight back off it.
        self::assertSame('home', (new FunnelRouter(new FakeCatalog([])))->nextStep(new Cart(), new JourneyState()));
    }

    public function testTheFirstOutstandingQuestionnaireWinsEvenWhenAnEarlierLinesFormIsFinished(): void
    {
        // Two lines, two intake forms, only the first one finished. `[8.0f]`
        // allows one prescription per order, but free attachments, OTC lines
        // and accepted bumps sit in the same cart and can carry a form of
        // their own, so "there is only ever one" is not a rule to lean on.
        $catalog = new FakeCatalog([
            'attachment' => ['slug' => 'attachment', 'kind' => 'otc', 'teleform_id' => 'tf-done'],
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-outstanding'],
        ]);
        $cart = $this->cartWith($catalog, 'attachment', 'semaglutide');
        $state = new JourneyState();
        $state->markFormCompleted('tf-done');

        self::assertSame('intake.medical', (new FunnelRouter($catalog))->nextStep($cart, $state));
    }

    public function testPrequalificationPairsTheOptInAndTheFormOnTheSameLine(): void
    {
        // [8.2]: one line's opt-in read against another line's form is a gate
        // no product ever declared. Neither line here declares one on its own,
        // so there is no eligibility step to route to.
        $catalog = new FakeCatalog([
            'wants-step' => ['slug' => 'wants-step', 'kind' => 'otc', 'requires_prequalification' => true],
            'names-form' => ['slug' => 'names-form', 'kind' => 'rx', 'prequalification_teleform_id' => 'tf-pre'],
        ]);
        $cart = $this->cartWith($catalog, 'wants-step', 'names-form');

        self::assertSame('checkout', (new FunnelRouter($catalog))->nextStep($cart, new JourneyState()));
    }

    public function testAVisitorWhoHasAnsweredNothingYetStillGetsTheQuestionnaireItself(): void
    {
        // Not the welcome page, however tempting: `/intake/eligibility/`
        // forwards a cart with no eligibility form to whatever this decision
        // answers, so answering "the welcome page" makes the welcome page's own
        // call to action lead back to the welcome page. `[8.1]` is asked from
        // inside the funnel as well as from outside it.
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-1'],
        ]);
        $cart = $this->cartWith($catalog, 'semaglutide');

        self::assertSame('intake.medical', (new FunnelRouter($catalog))->nextStep($cart, new JourneyState()));
    }

    public function testAJourneyPartWayThroughAQuestionnaireGetsTheQuestionnaire(): void
    {
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-1'],
        ]);
        $cart = $this->cartWith($catalog, 'semaglutide');
        $state = new JourneyState();
        $state->storeAnswers('tf-1', ['weight' => '210']);

        self::assertSame('intake.medical', (new FunnelRouter($catalog))->nextStep($cart, $state));
    }

    public function testAnUnstartedVisitorOwingAnEligibilityFormGetsThatStepFirst(): void
    {
        // The eligibility step outranks anything the intake branch could
        // answer, including for a journey that has stored nothing at all: the
        // welcome page and the medical step both declare
        // `prequalification_satisfied` among their own requirements, so naming
        // either here would name a step the guard refuses.
        $catalog = new FakeCatalog([
            'testosterone' => [
                'slug' => 'testosterone',
                'kind' => 'rx',
                'requires_prequalification' => true,
                'prequalification_teleform_id' => 'tf-pre',
                'teleform_id' => 'tf-1',
            ],
        ]);
        $cart = $this->cartWith($catalog, 'testosterone');

        self::assertSame('prequalification', (new FunnelRouter($catalog))->nextStep($cart, new JourneyState()));
    }

    public function testACartWithNothingToAskReachesCheckoutHoweverEmptyTheAnswersAre(): void
    {
        // [8.3]: the two intake branches are gated on a form being outstanding
        // and not on the journey looking untouched, so an empty answer set on a
        // cart with nothing to ask is still checkout.
        $catalog = new FakeCatalog([
            'multivitamin' => ['slug' => 'multivitamin', 'kind' => 'rx', 'teleform_id' => null],
        ]);
        $cart = $this->cartWith($catalog, 'multivitamin');

        self::assertSame('checkout', (new FunnelRouter($catalog))->nextStep($cart, new JourneyState()));
    }

    public function testAnUnstartedJourneyThatIsAlreadyDisqualifiedIsStillSentToTheTerminalPage(): void
    {
        // A termination recorded before any answer was stored -- a rule tripped
        // on the first submission, whose answers were then let go of -- must
        // not be routed back into the funnel by a branch reading the empty
        // answer set as "has not started".
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-1'],
        ]);
        $cart = $this->cartWith($catalog, 'semaglutide');
        $state = new JourneyState();
        $state->recordDisqualification('tf-1', 'bmi_low_hard_stop_notice');

        self::assertSame('not_eligible', (new FunnelRouter($catalog))->nextStep($cart, $state));
    }

    public function testAnUnknownJourneyIsNotTreatedAsAnUnstartedOne(): void
    {
        // A journey that could not be loaded is unknowable, not empty. Reading
        // the two as the same thing would put every visitor of an outage
        // wherever a never-started visitor belongs, which is not where someone
        // part-way through a questionnaire belongs.
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-1'],
        ]);
        $cart = $this->cartWith($catalog, 'semaglutide');

        self::assertSame('intake.medical', (new FunnelRouter($catalog))->nextStep($cart, null));
    }

    public function testADisqualifiedJourneyStillOutranksEverything(): void
    {
        $catalog = new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'kind' => 'rx', 'teleform_id' => 'tf-1'],
        ]);
        $cart = $this->cartWith($catalog, 'semaglutide');
        $state = new JourneyState();
        $state->markFormCompleted('tf-1');
        $state->recordDisqualification('tf-1', 'bmi_low_hard_stop_notice');

        self::assertSame('not_eligible', (new FunnelRouter($catalog))->nextStep($cart, $state));
    }
}
