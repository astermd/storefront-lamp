<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StepPreconditionsTest extends TestCase
{
    private const string INTAKE_FORM = 'tf-intake';
    private const string PREQUAL_FORM = 'tf-prequal';

    /** @param array<string, array<string, mixed>> $products */
    private static function preconditions(array $products): StepPreconditions
    {
        return new StepPreconditions(new FakeCatalog($products));
    }

    private static function cartOf(string ...$slugs): Cart
    {
        $cart = new Cart();
        foreach ($slugs as $slug) {
            $cart->put(new CartLine($slug, ucfirst($slug), 'rx', 'emr-' . $slug, null, 1, 5000, $slug . '-1m'));
        }

        return $cart;
    }

    // ---- intake_satisfied ---------------------------------------------------

    public function testIntakeIsSatisfiedWhenNoCartProductAsksForAForm(): void
    {
        $preconditions = self::preconditions(['organizer' => ['slug' => 'organizer', 'kind' => 'otc']]);
        $cart = new Cart();
        $cart->put(new CartLine('organizer', 'Pill Organizer', 'otc', 'emr-org', null, 1, 1200));

        self::assertTrue(
            $preconditions->satisfied('intake_satisfied', $cart, new JourneyState()),
            '[8.3]: nothing to collect means nothing to wait for',
        );
    }

    public function testIntakeIsUnsatisfiedWhileTheDeclaredFormIsUncompleted(): void
    {
        $preconditions = self::preconditions(['sema' => ['slug' => 'sema', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM]]);

        self::assertFalse($preconditions->satisfied('intake_satisfied', self::cartOf('sema'), new JourneyState()));
    }

    public function testIntakeIsSatisfiedOnceTheDeclaredFormIsCompleted(): void
    {
        $preconditions = self::preconditions(['sema' => ['slug' => 'sema', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM]]);
        $state = new JourneyState();
        $state->markFormCompleted(self::INTAKE_FORM);

        self::assertTrue($preconditions->satisfied('intake_satisfied', self::cartOf('sema'), $state));
    }

    public function testCompletingAnotherFormDoesNotSatisfyThisOne(): void
    {
        $preconditions = self::preconditions(['sema' => ['slug' => 'sema', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM]]);
        $state = new JourneyState();
        $state->markFormCompleted('tf-something-else');

        self::assertFalse($preconditions->satisfied('intake_satisfied', self::cartOf('sema'), $state));
    }

    public function testADisqualifiedJourneyIsNeverSatisfiedEvenWithTheFormCompleted(): void
    {
        $preconditions = self::preconditions(['sema' => ['slug' => 'sema', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM]]);
        $state = new JourneyState();
        $state->markFormCompleted(self::INTAKE_FORM);
        $state->recordDisqualification(self::INTAKE_FORM, 'pregnancy_hard_stop_notice');

        self::assertFalse(
            $preconditions->satisfied('intake_satisfied', self::cartOf('sema'), $state),
            'a hard stop is the server verdict; finishing the form first must not buy passage',
        );
    }

    public function testIntakeIsUnsatisfiedWhenNoJourneyCouldBeLoaded(): void
    {
        $preconditions = self::preconditions(['sema' => ['slug' => 'sema', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM]]);

        self::assertFalse($preconditions->satisfied('intake_satisfied', self::cartOf('sema'), null));
    }

    public function testAnUnknownProductCannotDeclareAFormAndSoDoesNotBlock(): void
    {
        self::assertTrue(self::preconditions([])->satisfied('intake_satisfied', self::cartOf('ghost'), new JourneyState()));
    }

    // ---- prequalification_satisfied ----------------------------------------

    public function testPrequalificationIsSatisfiedWhenNoProductAsksForADedicatedStep(): void
    {
        $preconditions = self::preconditions(['sema' => ['slug' => 'sema', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM]]);

        self::assertTrue($preconditions->satisfied('prequalification_satisfied', self::cartOf('sema'), new JourneyState()));
    }

    public function testPrequalificationIsSatisfiedWhenTheStepIsWantedButNoFormIsNamed(): void
    {
        // The common real configuration: eligibility questions folded into the
        // intake form, so there is no separate questionnaire to complete.
        $preconditions = self::preconditions(['sema' => [
            'slug' => 'sema', 'kind' => 'rx', 'requires_prequalification' => true,
        ]]);

        self::assertTrue(
            $preconditions->satisfied('prequalification_satisfied', self::cartOf('sema'), new JourneyState()),
            'blocking on a form that does not exist would strand the journey',
        );
    }

    public function testPrequalificationIsUnsatisfiedWhileItsNamedFormIsUncompleted(): void
    {
        $preconditions = self::preconditions(['sema' => [
            'slug' => 'sema', 'kind' => 'rx',
            'requires_prequalification' => true,
            'prequalification_teleform_id' => self::PREQUAL_FORM,
        ]]);

        self::assertFalse($preconditions->satisfied('prequalification_satisfied', self::cartOf('sema'), new JourneyState()));
    }

    public function testPrequalificationIsSatisfiedOnceItsNamedFormIsCompleted(): void
    {
        $preconditions = self::preconditions(['sema' => [
            'slug' => 'sema', 'kind' => 'rx',
            'requires_prequalification' => true,
            'prequalification_teleform_id' => self::PREQUAL_FORM,
        ]]);
        $state = new JourneyState();
        $state->markFormCompleted(self::PREQUAL_FORM);

        self::assertTrue($preconditions->satisfied('prequalification_satisfied', self::cartOf('sema'), $state));
    }

    public function testPrequalificationIsUnsatisfiedWhenNoJourneyCouldBeLoaded(): void
    {
        $preconditions = self::preconditions(['sema' => [
            'slug' => 'sema', 'kind' => 'rx',
            'requires_prequalification' => true,
            'prequalification_teleform_id' => self::PREQUAL_FORM,
        ]]);

        self::assertFalse($preconditions->satisfied('prequalification_satisfied', self::cartOf('sema'), null));
    }

    // ---- the router and the guard answer from one rule ----------------------

    public function testAnOptInOnOneLineDoesNotAdoptAnotherLinesForm(): void
    {
        // The stranding case. One line asks for the dedicated step and names
        // no form; another names a form and never asks for the step. Pairing
        // them across lines invents a gate no product declared, and because
        // the router does not invent it the visitor ping-pongs between
        // /checkout/ and /intake/eligibility/ forever -- a different URL each
        // hop, so no browser ever detects the loop.
        $preconditions = self::preconditions([
            'wants-step' => ['slug' => 'wants-step', 'kind' => 'otc', 'requires_prequalification' => true],
            'names-form' => ['slug' => 'names-form', 'kind' => 'rx', 'prequalification_teleform_id' => self::PREQUAL_FORM],
        ]);

        self::assertTrue(
            $preconditions->satisfied('prequalification_satisfied', self::cartOf('wants-step', 'names-form'), new JourneyState()),
            '[8.2]: both declarations have to come from the same line',
        );
    }

    public function testACompletedFormOnAnotherLineDoesNotOpenTheEligibilityStep(): void
    {
        // The unguarded-step case, the same drift pointed the other way: the
        // first line's *completed* form was being read as the answer to the
        // second line's opt-in, so /intake/eligibility/ and /checkout/ both
        // served 200 while the router still considered the real form
        // outstanding.
        $preconditions = self::preconditions([
            'already-done' => ['slug' => 'already-done', 'kind' => 'otc', 'prequalification_teleform_id' => 'tf-done'],
            'still-owed' => [
                'slug' => 'still-owed', 'kind' => 'rx',
                'requires_prequalification' => true,
                'prequalification_teleform_id' => self::PREQUAL_FORM,
            ],
        ]);
        $state = new JourneyState();
        $state->markFormCompleted('tf-done');

        self::assertFalse(
            $preconditions->satisfied('prequalification_satisfied', self::cartOf('already-done', 'still-owed'), $state),
            'the eligibility questionnaire this cart actually calls for is still outstanding',
        );
    }

    public function testAFinishedIntakeOnOneLineDoesNotSatisfyAnOutstandingOneOnAnother(): void
    {
        $preconditions = self::preconditions([
            'already-done' => ['slug' => 'already-done', 'kind' => 'otc', 'teleform_id' => 'tf-done'],
            'still-owed' => ['slug' => 'still-owed', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM],
        ]);
        $state = new JourneyState();
        $state->markFormCompleted('tf-done');

        self::assertFalse(
            $preconditions->satisfied('intake_satisfied', self::cartOf('already-done', 'still-owed'), $state),
            'first-match-wins let a finished form stand in for an unfinished one',
        );
    }

    /**
     * The property the shared rule exists to guarantee, asserted directly:
     * whenever the routing decision answers `checkout`, checkout's own
     * preconditions must agree — otherwise the guard bounces the visitor to a
     * step the router will send them straight back from.
     *
     * @param array<string, array<string, mixed>> $products
     * @param list<string>                        $completedForms
     */
    #[DataProvider('driftCases')]
    public function testTheRouterAndTheGuardNeverDisagree(array $products, array $slugs, array $completedForms): void
    {
        $state = new JourneyState();
        foreach ($completedForms as $formId) {
            $state->markFormCompleted($formId);
        }

        $cart = self::cartOf(...$slugs);
        $preconditions = self::preconditions($products);
        $routed = (new FunnelRouter(new FakeCatalog($products)))->nextStep($cart, $state);

        $satisfied = $preconditions->satisfied('prequalification_satisfied', $cart, $state)
            && $preconditions->satisfied('intake_satisfied', $cart, $state);

        self::assertSame(
            $routed === 'checkout',
            $satisfied,
            sprintf('router said "%s" while checkout\'s own preconditions said %s', $routed, $satisfied ? 'yes' : 'no'),
        );
    }

    /** @return array<string, array{0: array<string, array<string, mixed>>, 1: list<string>, 2: list<string>}> */
    public static function driftCases(): array
    {
        return [
            'opt-in and form on different lines' => [
                [
                    'wants-step' => ['slug' => 'wants-step', 'kind' => 'otc', 'requires_prequalification' => true],
                    'names-form' => ['slug' => 'names-form', 'kind' => 'rx', 'prequalification_teleform_id' => self::PREQUAL_FORM],
                ],
                ['wants-step', 'names-form'],
                [],
            ],
            'a finished eligibility form on a line that never asked for the step' => [
                [
                    'already-done' => ['slug' => 'already-done', 'kind' => 'otc', 'prequalification_teleform_id' => 'tf-done'],
                    'still-owed' => [
                        'slug' => 'still-owed', 'kind' => 'rx',
                        'requires_prequalification' => true,
                        'prequalification_teleform_id' => self::PREQUAL_FORM,
                    ],
                ],
                ['already-done', 'still-owed'],
                ['tf-done'],
            ],
            'a finished intake form standing in front of an unfinished one' => [
                [
                    'already-done' => ['slug' => 'already-done', 'kind' => 'otc', 'teleform_id' => 'tf-done'],
                    'still-owed' => ['slug' => 'still-owed', 'kind' => 'rx', 'teleform_id' => self::INTAKE_FORM],
                ],
                ['already-done', 'still-owed'],
                ['tf-done'],
            ],
        ];
    }

    // ---- an unknowable journey is not an unsatisfied one --------------------

    /**
     * `[20.1]`. A journey that could not be loaded is *unknowable*, not
     * known-empty, and the two questions have different right answers. The
     * questionnaire gates stay shut — an outage does not make an uncollected
     * medical form safe (`[8.6]`) — but the order is already placed and paid
     * for by the time the receipt is asked for, and locking the buyer out of
     * it because the database blinked is the worse failure.
     */
    public function testAPlacedOrderIsAssumedWhenNoJourneyCouldBeLoaded(): void
    {
        self::assertTrue(self::preconditions([])->satisfied('order_placed', new Cart(), null));
    }

    public function testAPlacedOrderIsStillRequiredOfAJourneyThatCouldBeLoaded(): void
    {
        self::assertFalse(self::preconditions([])->satisfied('order_placed', new Cart(), new JourneyState()));
    }

    // ---- the existing guarantee --------------------------------------------

    public function testAnUnknownRequirementStillFailsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown funnel precondition "invented"');

        self::preconditions([])->satisfied('invented', new Cart(), new JourneyState());
    }
}
