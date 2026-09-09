<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Verification\VerificationPlacement;
use PHPUnit\Framework\TestCase;

/**
 * The routing decision and the guard, asserted against each other.
 *
 * `[22.16]` keeps placement and blocking as separate settings, which means one
 * configuration is read by two collaborators asking different questions. This
 * class exists because they can disagree in two directions and each one is
 * silently broken rather than loudly:
 *
 * - a router that never names the step leaves it reachable only by typing its
 *   URL, so the deployment believes it is verifying identities and is not;
 * - a guard that refuses a step the router still routes *to* produces a
 *   redirect to the step the visitor is already on, which
 *   {@see \AsterMD\Storefront\Http\Middleware\StepGuardMiddleware} treats as a
 *   self-redirect and answers by sending them to the home page.
 *
 * The second is the worse failure and the harder one to see from either side:
 * every unit test of the guard passes, every unit test of the router passes,
 * and a buyer who finished a medical questionnaire lands on the front page.
 */
final class VerificationPlacementTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function config(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true,
            'placement' => 'intake',
            'blocking' => true,
            'checks' => [['slug' => 'crosscheck', 'required' => ['firstName', 'lastName'], 'narrowing' => []]],
        ];
    }

    /** A cart of one over-the-counter line, which asks for no questionnaire (`[8.3]`). */
    private static function cart(): Cart
    {
        $cart = new Cart();
        $cart->put(new CartLine('vitamin-d', 'Vitamin D', 'otc', 'emr-vitamin-d', null, 1, 1500));

        return $cart;
    }

    private static function journeyThatFinishedIntake(): JourneyState
    {
        return new JourneyState();
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function router(array $config): FunnelRouter
    {
        return new FunnelRouter(new FakeCatalog([]), $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function guard(array $config): StepPreconditions
    {
        return new StepPreconditions(new FakeCatalog([]), $config);
    }

    /**
     * The defect that made the whole step decorative: nothing routed to it.
     */
    public function testAJourneyWithVerificationOutstandingIsRoutedToTheStep(): void
    {
        $step = self::router(self::config())->nextStep(self::cart(), self::journeyThatFinishedIntake());

        self::assertSame('verify', $step);
    }

    /**
     * The invariant, and the reason this file is not two separate test classes.
     *
     * Wherever the guard refuses, the router must name a step that is *not*
     * the refused one. Asserted as a property over every reachable
     * verification state rather than as three examples, because the failure
     * mode is a combination nobody thought to write an example for.
     */
    public function testWhereverTheGuardRefusesTheRouterSendsThemSomewhereElse(): void
    {
        $states = [
            'never asked' => static function (JourneyState $state): void {},
            'failed' => static fn (JourneyState $state) => $state->recordVerification(JourneyState::VERIFICATION_FAILED),
            'inconclusive' => static fn (JourneyState $state) => $state->recordVerification(JourneyState::VERIFICATION_INCONCLUSIVE),
            'passed' => static fn (JourneyState $state) => $state->recordVerification(JourneyState::VERIFICATION_PASSED),
        ];

        foreach ($states as $label => $prepare) {
            $state = self::journeyThatFinishedIntake();
            $prepare($state);

            $satisfied = self::guard(self::config())->satisfied('verification_satisfied', self::cart(), $state);
            $destination = self::router(self::config())->nextStep(self::cart(), $state);

            if (!$satisfied) {
                self::assertNotSame(
                    'checkout',
                    $destination,
                    sprintf('a refused "%s" journey is routed to the very step the guard refuses', $label),
                );
                self::assertSame('verify', $destination, sprintf('"%s" should be sent back to the step', $label));
            }
        }
    }

    /**
     * The shipped configuration changes nothing about where anyone goes.
     *
     * Disabled is how this deployment ships, and it ships that way because the
     * configured provider returned `valid: false` for all twenty recorded
     * calls across nine identities. A step that cannot pass anybody must not
     * be in anybody's way.
     */
    public function testTheShippedDisabledConfigurationRoutesStraightToCheckout(): void
    {
        $config = self::config(['enabled' => false]);
        $state = self::journeyThatFinishedIntake();

        self::assertSame('checkout', self::router($config)->nextStep(self::cart(), $state));
        self::assertTrue(self::guard($config)->satisfied('verification_satisfied', self::cart(), $state));
    }

    /**
     * Non-blocking still collects, and still lets everybody through.
     *
     * `[22.16]`'s distinction made concrete: the visitor is sent to the step,
     * the outcome is recorded (`[22.20]`), and a failure does not stop them.
     */
    public function testANonBlockingPlacementCollectsWithoutGating(): void
    {
        $config = self::config(['blocking' => false]);
        $state = self::journeyThatFinishedIntake();

        self::assertSame('verify', self::router($config)->nextStep(self::cart(), $state), 'it still asks');
        self::assertTrue(self::guard($config)->satisfied('verification_satisfied', self::cart(), $state), 'and never blocks');

        $state->recordVerification(JourneyState::VERIFICATION_FAILED);

        self::assertSame('checkout', self::router($config)->nextStep(self::cart(), $state), 'a failure is an answer');
        self::assertTrue(self::guard($config)->satisfied('verification_satisfied', self::cart(), $state));
    }

    /**
     * A placement that runs at or after checkout cannot gate a step before it.
     *
     * The deadlock this prevents is not hypothetical: were `receipt` to gate
     * `/checkout/`, the only way to satisfy the gate would be to reach a page
     * that requires a placed order, which requires passing the gate.
     */
    public function testAPostPaymentPlacementNeverGatesAPrePaymentStep(): void
    {
        foreach (['post_checkout', 'receipt', 'async'] as $placement) {
            $config = self::config(['placement' => $placement]);
            $state = self::journeyThatFinishedIntake();

            self::assertTrue(
                self::guard($config)->satisfied('verification_satisfied', self::cart(), $state),
                sprintf('a "%s" placement must not gate checkout', $placement),
            );
            self::assertSame('checkout', self::router($config)->nextStep(self::cart(), $state));
        }
    }

    /**
     * An inconclusive verdict is not a pass, and is not treated as one.
     *
     * The absence worth stating: nothing in the chain quietly widens "did not
     * fail" into "passed". A provider outage under a blocking placement stops
     * the buyer — which is the correct behaviour and also the reason the
     * shipped configuration does not block.
     */
    public function testAnInconclusiveVerdictDoesNotSatisfyABlockingGate(): void
    {
        $state = self::journeyThatFinishedIntake();
        $state->recordVerification(JourneyState::VERIFICATION_INCONCLUSIVE);

        self::assertFalse((new VerificationPlacement(self::config()))->satisfied($state));
        self::assertTrue((new VerificationPlacement(self::config(['blocking' => false])))->satisfied($state));
    }
}
