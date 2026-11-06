<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\SettlementPolicy;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Payment\SettlementMode;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use PHPUnit\Framework\TestCase;

final class SettlementPolicyTest extends TestCase
{
    private CapturedLog $log;

    protected function setUp(): void
    {
        $this->log = new CapturedLog();
    }

    /** @param array<string, array<string, mixed>> $products */
    private function policy(array $products, SettlementMode $default = SettlementMode::Capture): SettlementPolicy
    {
        return new SettlementPolicy(new FakeCatalog($products), $default, $this->log->log);
    }

    public function testAProductThatSaysNothingInheritsTheDeploymentDefault(): void
    {
        $policy = $this->policy(['tirzepatide' => ['slug' => 'tirzepatide']], SettlementMode::Authorize);

        self::assertSame(SettlementMode::Authorize, $policy->forSlug('tirzepatide'));
    }

    public function testAProductCanAskToHoldFundsOnACaptureDeployment(): void
    {
        $policy = $this->policy(['lab' => ['slug' => 'lab', 'settlement' => 'authorize']]);

        self::assertSame(SettlementMode::Authorize, $policy->forSlug('lab'));
    }

    public function testOneAuthorizeProductMakesTheWholeOrderAuthorize(): void
    {
        // The rule the whole feature turns on: a cart is one order ([13.19]),
        // so a cart mixing the two settles the way that takes no money.
        $policy = $this->policy([
            'tirzepatide' => ['slug' => 'tirzepatide'],
            'lab' => ['slug' => 'lab', 'settlement' => 'authorize'],
        ]);

        self::assertSame(SettlementMode::Authorize, $policy->forSlugs('tirzepatide', 'lab'));
    }

    public function testAProductMarkedCaptureCannotPullAnAuthorizeDeploymentBackToCharging(): void
    {
        // Strictness only ever travels one way. A deployment that holds funds
        // must not be talked out of it by one product's opinion, or the
        // per-product key would become a way to charge behind the
        // deployment's back.
        $policy = $this->policy([
            'otc' => ['slug' => 'otc', 'settlement' => 'capture'],
        ], SettlementMode::Authorize);

        self::assertSame(SettlementMode::Authorize, $policy->forSlugs('otc'));
    }

    public function testACaptureDeploymentBuyingOnlyCaptureProductsStillCaptures(): void
    {
        $policy = $this->policy([
            'a' => ['slug' => 'a'],
            'b' => ['slug' => 'b', 'settlement' => 'capture'],
        ]);

        self::assertSame(SettlementMode::Capture, $policy->forSlugs('a', 'b'));
    }

    public function testASlugWithNoProductTakesTheDefaultRatherThanFailing(): void
    {
        // Free attachments and bundle children reach here by the same path as
        // anything else and are not always catalog entries of their own. A
        // throw would turn a line nobody is charged for into a failed checkout.
        $policy = $this->policy([], SettlementMode::Authorize);

        self::assertSame(SettlementMode::Authorize, $policy->forSlug('syringe'));
    }

    public function testAnEmptyCartResolvesToTheDefault(): void
    {
        self::assertSame(SettlementMode::Capture, $this->policy([])->forSlugs());
    }

    public function testAnUnrecognisedProductValueFallsBackToTheDefaultAndIsLogged(): void
    {
        // Falling back to the default is the only answer that neither starts
        // charging a deployment that holds funds nor starts holding on one
        // that charges. config:validate is where the typo is meant to be
        // caught; this is what happens if it reaches production anyway.
        $policy = $this->policy(['lab' => ['slug' => 'lab', 'settlement' => 'authorise']]);

        self::assertSame(SettlementMode::Capture, $policy->forSlug('lab'));
        self::assertSame('checkout.settlement_unrecognised', $this->log->lastError()['event'] ?? null);
    }

    public function testAnUnrecognisedValueOnAnAuthorizeDeploymentKeepsHoldingFunds(): void
    {
        $policy = $this->policy(
            ['lab' => ['slug' => 'lab', 'settlement' => 'nonsense']],
            SettlementMode::Authorize,
        );

        self::assertSame(SettlementMode::Authorize, $policy->forSlug('lab'));
    }

    public function testAnAbsentConfigurationValueIsCaptureRatherThanAnError(): void
    {
        // `config/payment.php` ships the key, but a deployment's own copy may
        // predate it, and what that deployment has always done is charge.
        self::assertSame(SettlementMode::Capture, SettlementPolicy::modeFromConfig(null));
        self::assertSame(SettlementMode::Capture, SettlementPolicy::modeFromConfig('authorise'));
        self::assertSame(SettlementMode::Authorize, SettlementPolicy::modeFromConfig('authorize'));
    }

    /**
     * A prescription whose one dose is sold on a plan that holds its first
     * payment and on one that takes it, with a free supply attached.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function subscriptionProducts(?string $holdSettlement = 'authorize'): array
    {
        $hold = ['id' => 'plan-hold', 'first_payment_cents' => 10000];
        if ($holdSettlement !== null) {
            $hold['settlement'] = $holdSettlement;
        }
        $take = ['id' => 'plan-take', 'first_payment_cents' => 10000, 'settlement' => 'capture'];

        return [
            'glp' => [
                'slug' => 'glp',
                'variants' => [['id' => 'glp-1mg', 'plan' => $hold, 'plans' => [$hold, $take]]],
                'attachments' => ['needles'],
            ],
            'needles' => ['slug' => 'needles'],
            'lab' => ['slug' => 'lab'],
        ];
    }

    private static function line(string $slug, ?string $planId = null, ?string $parentSlug = null): CartLine
    {
        return new CartLine($slug, $slug, 'rx', null, $parentSlug, variantId: $parentSlug === null ? $slug . '-1mg' : null, planId: $planId);
    }

    public function testAPlanThatHoldsItsFirstPaymentHoldsOnADeploymentThatCharges(): void
    {
        $policy = $this->policy(self::subscriptionProducts());

        self::assertSame(SettlementMode::Authorize, $policy->forLines(self::line('glp', 'plan-hold')));
    }

    public function testAPlanThatTakesItsFirstPaymentChargesOnADeploymentThatHolds(): void
    {
        // The other direction from the per-product key, which can only ever
        // add a hold: the plan is the EMR's statement of how its first payment
        // is taken, so the deployment default does not speak for it.
        $policy = $this->policy(self::subscriptionProducts(), SettlementMode::Authorize);

        self::assertSame(SettlementMode::Capture, $policy->forLines(self::line('glp', 'plan-take')));
    }

    public function testALineNamingNoPlanIsOnItsVariantsDefault(): void
    {
        $policy = $this->policy(self::subscriptionProducts());

        self::assertSame(SettlementMode::Authorize, $policy->forLines(self::line('glp')));
    }

    public function testThePlansProductKeyDoesNotSpeakForItsSubscription(): void
    {
        $products = self::subscriptionProducts();
        $products['glp']['settlement'] = 'authorize';

        self::assertSame(SettlementMode::Capture, $this->policy($products)->forLines(self::line('glp', 'plan-take')));
    }

    public function testAPlansFreeSupplyDoesNotBringTheDefaultBackIn(): void
    {
        $policy = $this->policy(self::subscriptionProducts(), SettlementMode::Authorize);

        self::assertSame(
            SettlementMode::Capture,
            $policy->forLines(self::line('glp', 'plan-take'), self::line('needles', parentSlug: 'glp')),
        );
    }

    public function testAnAttachmentThatAsksToHoldStillDoes(): void
    {
        $products = self::subscriptionProducts();
        $products['needles']['settlement'] = 'authorize';

        self::assertSame(
            SettlementMode::Authorize,
            $this->policy($products)->forLines(self::line('glp', 'plan-take'), self::line('needles', parentSlug: 'glp')),
        );
    }

    public function testAProductBesideThePlanStillHoldsTheWholeOrderOnAnAuthorizeDeployment(): void
    {
        // One order, one mode ([13.19]): the plan's line would take the money,
        // the other line asks to hold it, and authorize wins the cart.
        $policy = $this->policy(self::subscriptionProducts(), SettlementMode::Authorize);

        self::assertSame(
            SettlementMode::Authorize,
            $policy->forLines(self::line('glp', 'plan-take'), new CartLine('lab', 'Lab', 'lab', null, null)),
        );
    }

    public function testACartWithNoPlanSettlesExactlyAsItsSlugsDo(): void
    {
        $products = ['otc' => ['slug' => 'otc', 'settlement' => 'capture'], 'lab' => ['slug' => 'lab', 'settlement' => 'authorize']];

        foreach ([SettlementMode::Capture, SettlementMode::Authorize] as $default) {
            $policy = $this->policy($products, $default);
            $lines = [new CartLine('otc', 'OTC', 'otc', null, null), new CartLine('lab', 'Lab', 'lab', null, 'otc')];

            self::assertSame($policy->forSlugs('otc', 'lab'), $policy->forLines(...$lines));
            self::assertSame($policy->forSlugs('otc'), $policy->forLines($lines[0]));
        }
    }

    public function testAPlanSyncedBeforePlansSaidHowTheySettleSettlesAsItAlwaysDid(): void
    {
        $policy = $this->policy(self::subscriptionProducts(holdSettlement: null), SettlementMode::Authorize);

        self::assertSame(SettlementMode::Authorize, $policy->forLines(self::line('glp', 'plan-hold')));
        self::assertSame(SettlementMode::Capture, $this->policy(self::subscriptionProducts(holdSettlement: null))->forLines(self::line('glp', 'plan-hold')));
    }

    public function testAnUnrecognisedPlanValueSettlesAsIfSoldOneOffAndIsLogged(): void
    {
        $policy = $this->policy(self::subscriptionProducts(holdSettlement: 'authorise'));

        self::assertSame(SettlementMode::Capture, $policy->forLines(self::line('glp', 'plan-hold')));
        self::assertSame('checkout.settlement_unrecognised', $this->log->lastError()['event'] ?? null);
    }

    public function testAnEmptyOrderOfLinesResolvesToTheDefault(): void
    {
        self::assertSame(SettlementMode::Authorize, $this->policy([], SettlementMode::Authorize)->forLines());
    }
}
