<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Whether the funnel, as `config/funnel.php` actually declares it, can strand
 * anybody — asked of every combination of cart and journey this storefront can
 * produce rather than of the handful a scenario test happens to walk.
 *
 * It exists because the two failure modes it looks for are both invisible
 * per-case. A visitor is *stranded* when every redirect lands on a step that
 * redirects them onward again, at a different URL each hop so no browser ever
 * reports a loop; a step is left *unguarded* when the guard gives up and serves
 * a page whose precondition is unmet, which is how someone reaches checkout
 * without a chosen plan. Each is a property of the whole flow — the config, the
 * routing decision and the preconditions together — so no test of one of those
 * three in isolation can see it, and a fixture only ever samples it.
 *
 * **What it does not cover, and why that matters.** Only the step guard's own
 * redirects are modelled. A controller may also forward — the eligibility step
 * forwards a cart that has no eligibility form to ask about — and those
 * forwards ask the same routing decision without passing through the guard. So
 * a routing decision can be sound by every property below and still close a
 * loop through a page's own call to action. Nothing here can see that; only an
 * HTTP walk can, which is why {@see \AsterMD\Storefront\Tests\Http\IntakeFormChoiceTest}
 * exists alongside this.
 *
 * The stronger of the two properties is
 * {@see self::testEveryRoutingDecisionNamesAStepTheSameVisitorMayEnter()}: if
 * the routing decision always names a step the same visitor may actually
 * enter, then a redirect chain is one hop long by construction and a cycle is
 * arithmetically impossible. The walk is kept alongside it because it models
 * the guard's own behaviour — including the fail-closed self-redirect — and so
 * would catch a stranding that arose from the *guard* rather than from the
 * routing decision.
 */
final class FunnelReachabilityTest extends TestCase
{
    /**
     * Products chosen so that every branch of {@see FunnelRules} is reachable:
     * an intake form, a dedicated eligibility form on the same line as its
     * opt-in, a form declared on a non-prescription line, and a prescription
     * that declares no questionnaire at all.
     *
     * A literal catalog rather than the shipped one because the shipped seed
     * happens to declare no eligibility form anywhere, and "no product uses
     * this key today" is not a property the funnel may rely on.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogData(): array
    {
        return [
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
            'formless-rx' => [
                'slug' => 'formless-rx', 'name' => 'Formless Rx', 'kind' => 'rx',
                'teleform_id' => null,
            ],
            'otc-with-form' => [
                'slug' => 'otc-with-form', 'name' => 'OTC With Form', 'kind' => 'otc',
                'teleform_id' => 'tf-otc',
            ],
            'otc-plain' => ['slug' => 'otc-plain', 'name' => 'OTC Plain', 'kind' => 'otc'],
        ];
    }

    private function catalog(): FakeCatalog
    {
        return new FakeCatalog($this->catalogData());
    }

    /** The shipped flow, not a fixture of one: the point is to check the funnel this deployment serves. */
    private function flow(): FlowDefinition
    {
        return FlowDefinition::fromConfig(Config::load(dirname(__DIR__, 2) . '/config'));
    }

    private function line(string $slug, ?string $variantId): CartLine
    {
        return new CartLine(
            slug: $slug,
            name: (string) ($this->catalogData()[$slug]['name'] ?? $slug),
            kind: (string) ($this->catalogData()[$slug]['kind'] ?? 'otc'),
            emrProductId: null,
            parentSlug: null,
            variantId: $variantId,
        );
    }

    /** @param array<string, ?string> $lines slug => chosen variant, null for none */
    private function cart(array $lines): Cart
    {
        $cart = new Cart();
        foreach ($lines as $slug => $variantId) {
            $cart->put($this->line($slug, $variantId));
        }

        return $cart;
    }

    /**
     * Every shape of cart that reaches the funnel, labelled so a failure names
     * the case rather than an index.
     *
     * @return array<string, Cart>
     */
    private function everyCart(): array
    {
        return [
            'empty' => $this->cart([]),
            'one Rx with no plan' => $this->cart(['plain-rx' => null]),
            'one Rx with a plan' => $this->cart(['plain-rx' => 'plan-monthly']),
            'Rx and OTC naming different forms' => $this->cart(['plain-rx' => 'plan-monthly', 'otc-with-form' => null]),
            'OTC only' => $this->cart(['otc-plain' => null]),
            'nothing naming a form' => $this->cart(['formless-rx' => 'plan-monthly', 'otc-plain' => null]),
            // Not in the shipped seed, and the reason this case is here: the
            // eligibility step is opt-in, so a funnel that only ever sees carts
            // without it never exercises the ordering between the two gates.
            'one Rx wanting the eligibility step' => $this->cart(['prequal-rx' => 'plan-monthly']),
            'eligibility Rx plus an OTC form' => $this->cart(['prequal-rx' => 'plan-monthly', 'otc-with-form' => null]),
            'eligibility Rx with no plan' => $this->cart(['prequal-rx' => null]),
        ];
    }

    /**
     * Every shape of journey, including the two that are not a journey at all:
     * null (session resolution failed, or analytics is switched off) and one
     * that has stored nothing yet.
     *
     * @return array<string, ?JourneyState>
     */
    private function everyJourney(): array
    {
        $inProgress = new JourneyState();
        $inProgress->storeAnswers('tf-medical', ['q1' => 'a']);

        $intakeDone = new JourneyState();
        $intakeDone->markFormCompleted('tf-medical');

        $eligibilityDone = new JourneyState();
        $eligibilityDone->markFormCompleted('tf-pre');

        $everyFormDone = new JourneyState();
        $everyFormDone->markFormCompleted('tf-pre');
        $everyFormDone->markFormCompleted('tf-medical');
        $everyFormDone->markFormCompleted('tf-otc');

        $disqualified = new JourneyState();
        $disqualified->markFormCompleted('tf-medical');
        $disqualified->recordDisqualification('tf-medical', 'bmi_low_hard_stop_notice');

        $bought = new JourneyState();
        $bought->markFormCompleted('tf-pre');
        $bought->markFormCompleted('tf-medical');
        $bought->markFormCompleted('tf-otc');
        $bought->recordPlacedOrder('34660');

        // The shape a journey is left in once the answers have been let go of
        // but the order is still on the record: an empty `formStatus` next to a
        // placed order, which is a different thing from a visitor who has not
        // started.
        $wiped = new JourneyState();
        $wiped->recordPlacedOrder('34660');

        return [
            'no journey' => null,
            'fresh' => new JourneyState(),
            'one form in progress' => $inProgress,
            'intake completed' => $intakeDone,
            'eligibility completed' => $eligibilityDone,
            'every form completed' => $everyFormDone,
            'disqualified' => $disqualified,
            'order placed' => $bought,
            'retired after completion' => $wiped,
        ];
    }

    /**
     * The first requirement of $step this cart and journey do not satisfy, or
     * null when the step may be served — the same first-failure order the
     * guard itself redirects on.
     */
    private function firstUnmet(FlowDefinition $flow, StepPreconditions $preconditions, string $step, Cart $cart, ?JourneyState $state): ?string
    {
        foreach ($flow->requirementsFor($step) as $requirement) {
            if (!$preconditions->satisfied($requirement, $cart, $state)) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * The property that makes the walk below terminate: a redirect target the
     * same visitor cannot enter is a second redirect, and a second redirect is
     * the only way a chain can ever become a loop.
     *
     * Asserted separately from the walk because it is the invariant an author
     * of a new routing branch can actually hold in their head — "never name a
     * step this visitor would be bounced off" — whereas "the walk terminates"
     * is a consequence of it rather than a rule anybody can apply.
     */
    public function testEveryRoutingDecisionNamesAStepTheSameVisitorMayEnter(): void
    {
        $flow = $this->flow();
        $router = new FunnelRouter($this->catalog());
        $preconditions = new StepPreconditions($this->catalog());
        $checked = 0;

        foreach ($this->everyCart() as $cartLabel => $cart) {
            foreach ($this->everyJourney() as $journeyLabel => $state) {
                $target = $router->nextStep($cart, $state);
                $unmet = $this->firstUnmet($flow, $preconditions, $target, $cart, $state);
                $checked++;

                self::assertNull(
                    $unmet,
                    sprintf(
                        'cart "%s" with journey "%s" is routed to "%s", whose own requirement "%s" it does not satisfy',
                        $cartLabel,
                        $journeyLabel,
                        $target,
                        (string) $unmet,
                    ),
                );
            }
        }

        self::assertSame(count($this->everyCart()) * count($this->everyJourney()), $checked);
    }

    /**
     * The guard's own behaviour, followed to a fixed point from every step of
     * the flow.
     *
     * This models {@see \AsterMD\Storefront\Http\Middleware\StepGuardMiddleware::process()}
     * rather than the routing decision alone, and the part that has to be
     * modelled rather than assumed is its fail-closed branch: when the routing
     * decision names the step whose precondition just failed, the guard does
     * *not* serve the page and does not follow the decision either — it
     * redirects to the flow's entry step, and only when the entry step is
     * itself the page being refused does it give up and serve it. A walk that
     * took the naive hop would report a loop the guard does not actually
     * perform, and one that ignored the branch would miss that the visitor
     * lands somewhere else entirely.
     */
    public function testNoCartAndJourneyCombinationProducesARedirectCycleOrAnUnguardedStep(): void
    {
        $flow = $this->flow();
        $router = new FunnelRouter($this->catalog());
        $preconditions = new StepPreconditions($this->catalog());
        $walked = 0;

        foreach ($this->everyCart() as $cartLabel => $cart) {
            foreach ($this->everyJourney() as $journeyLabel => $state) {
                foreach (array_keys($flow->steps()) as $entry) {
                    $where = sprintf('cart "%s", journey "%s", entering at "%s"', $cartLabel, $journeyLabel, $entry);
                    $at = $entry;
                    $seen = [$entry => true];
                    $walked++;

                    while (true) {
                        $unmet = $this->firstUnmet($flow, $preconditions, $at, $cart, $state);

                        if ($unmet === null) {
                            break;
                        }

                        $next = $router->nextStep($cart, $state);

                        if ($flow->pathFor($next) === $flow->pathFor($at)) {
                            // The guard's fail-closed branch, and the reason
                            // the walk cannot simply follow the decision.
                            $next = FlowDefinition::ENTRY_STEP;

                            if ($flow->pathFor($next) === $flow->pathFor($at)) {
                                self::fail(sprintf(
                                    '"%s" is served with the unmet requirement "%s" (%s)',
                                    $at,
                                    $unmet,
                                    $where,
                                ));
                            }
                        }

                        self::assertArrayNotHasKey($next, $seen, sprintf('redirect cycle back to "%s" (%s)', $next, $where));

                        $seen[$next] = true;
                        $at = $next;
                    }
                }
            }
        }

        self::assertSame(
            count($this->everyCart()) * count($this->everyJourney()) * count($flow->steps()),
            $walked,
        );
    }
}
