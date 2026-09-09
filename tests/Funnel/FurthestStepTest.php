<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\FurthestStep;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * The advance rule behind `[21.7]`'s furthest completed step, and the one
 * property that cannot be checked by reading the class: that every name it
 * ranks is a step `config/funnel.php` actually declares.
 */
final class FurthestStepTest extends TestCase
{
    public function testEveryRankedStepIsANameTheFunnelConfigurationActuallyDeclares(): void
    {
        $declared = array_keys(FlowDefinition::fromConfig(Config::load(dirname(__DIR__, 2) . '/config'))->steps());

        self::assertSame([], array_values(array_diff(FurthestStep::ORDER, $declared)));
    }

    /**
     * The off-ramp is a step, and deliberately not a rung: a disqualified
     * journey is excluded from abandonment by its verdict rather than by how
     * far it got.
     */
    public function testTheTerminationStepIsNotRankedAtAll(): void
    {
        self::assertFalse(in_array('not_eligible', FurthestStep::ORDER, true));
    }

    public function testAJourneyThatHasCompletedNothingAcceptsAnyDeclaredStep(): void
    {
        $state = new JourneyState();

        FurthestStep::advance($state, 'checkout');

        self::assertSame('checkout', $state->furthestStep);
    }

    public function testAStepFurtherAlongTheFunnelReplacesTheOneAlreadyHeld(): void
    {
        $state = new JourneyState();
        $state->furthestStep = 'prequalification';

        FurthestStep::advance($state, 'intake');

        self::assertSame('intake', $state->furthestStep);
    }

    public function testAnEarlierStepDoesNotOverwriteALaterOne(): void
    {
        $state = new JourneyState();
        $state->furthestStep = 'checkout';

        FurthestStep::advance($state, 'prequalification');

        self::assertSame('checkout', $state->furthestStep);
    }

    public function testRepeatingTheStepAlreadyHeldChangesNothing(): void
    {
        $state = new JourneyState();
        $state->furthestStep = 'upsell';

        FurthestStep::advance($state, 'upsell');

        self::assertSame('upsell', $state->furthestStep);
    }

    /**
     * A private spelling in this field is a step the abandonment signal's
     * consumer cannot turn back into a URL, so the last true value stands
     * instead.
     */
    public function testAStepTheFunnelDoesNotDeclareIsRefusedRatherThanWritten(): void
    {
        $state = new JourneyState();
        $state->furthestStep = 'intake';

        FurthestStep::advance($state, 'payment');

        self::assertSame('intake', $state->furthestStep);
    }

    public function testAStepTheFunnelDoesNotDeclareIsRefusedEvenOnAJourneyHoldingNothing(): void
    {
        $state = new JourneyState();

        FurthestStep::advance($state, 'payment');

        self::assertNull($state->furthestStep);
    }

    /**
     * An unplaceable value can only come from an earlier release or a
     * hand-edited row. Blocking on it would freeze the field for the life of
     * the journey.
     */
    public function testAnUnrankedValueAlreadyHeldIsReplacedByADeclaredStep(): void
    {
        $state = new JourneyState();
        $state->furthestStep = 'a-step-from-some-earlier-release';

        FurthestStep::advance($state, 'prequalification');

        self::assertSame('prequalification', $state->furthestStep);
    }

    /** `[20.8]`: a journey the server never saw start is nowhere to write, not an error. */
    public function testAdvancingAJourneyThatDoesNotExistIsSilentlyNothing(): void
    {
        FurthestStep::advance(null, 'checkout');

        self::assertTrue(true);
    }
}
