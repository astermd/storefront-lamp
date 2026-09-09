<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Domain;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\CartRules;
use PHPUnit\Framework\TestCase;

final class CartRulesTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function catalogData(): array
    {
        return [
            'sema' => [
                'slug' => 'sema', 'name' => 'Semaglutide', 'kind' => 'rx', 'emr_product_id' => 'emr-sema',
                'variants' => [
                    ['id' => 'sema-1m', 'name' => '1 Month', 'price_cents' => 5000],
                    ['id' => 'sema-3m', 'name' => '3 Months', 'price_cents' => 13800],
                ],
                'bundles' => ['cmp'], 'attachments' => ['syringe'], 'geo_blocks' => ['NY'],
            ],
            'tada' => [
                'slug' => 'tada', 'name' => 'Tadalafil', 'kind' => 'rx', 'emr_product_id' => 'emr-tada',
                'variants' => [['id' => 'tada-1m', 'name' => '1 Month', 'price_cents' => 4900]],
            ],
            'organizer' => [
                'slug' => 'organizer', 'name' => 'Pill Organizer', 'kind' => 'otc', 'emr_product_id' => 'emr-org',
                'variants' => [['id' => 'org-1', 'name' => 'Standard', 'price_cents' => 499]],
                'max_buy_qty' => 3, 'geo_blocks' => ['TX'],
            ],
            'cmp' => [
                'slug' => 'cmp', 'name' => 'Metabolic Panel', 'kind' => 'lab', 'emr_product_id' => 'emr-cmp',
                'variants' => [['id' => 'cmp-1', 'name' => 'Panel', 'price_cents' => 2500]],
            ],
            'syringe' => [
                'slug' => 'syringe', 'name' => 'Syringe Kit', 'kind' => 'free-addon', 'emr_product_id' => 'emr-syr',
                'variants' => [['id' => 'syr-1', 'name' => 'Kit', 'price_cents' => 900]],
                'geo_blocks' => ['AK'],
            ],
            'kit-parent' => [
                'slug' => 'kit-parent', 'name' => 'Kit Parent', 'kind' => 'otc', 'emr_product_id' => 'emr-kit',
                'variants' => [['id' => 'kit-1', 'name' => 'Kit', 'price_cents' => 1000]],
                'bundles' => ['missing-lab'],
            ],
        ];
    }

    private function catalog(): FakeCatalog
    {
        return new FakeCatalog($this->catalogData());
    }

    private static function assertNoFreeAddonCarriesAPrice(Cart $cart): void
    {
        foreach ($cart->lines() as $line) {
            if ($line->kind === 'free-addon') {
                self::assertSame(0, $line->unitPriceCents);
            }
        }
    }

    public function testAddingAnUnknownProductIsRejected(): void
    {
        $cart = new Cart();
        $outcome = (new CartRules($this->catalog()))->add($cart, 'nope');

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::UNKNOWN_PRODUCT, $outcome->notice);
        self::assertTrue($cart->isEmpty());
    }

    public function testAddingAnRxLandsAtZeroUntilAPlanIsChosen(): void
    {
        $cart = new Cart();
        (new CartRules($this->catalog()))->add($cart, 'sema');

        $line = $cart->line('sema');
        self::assertNotNull($line);
        self::assertSame(0, $line->unitPriceCents);
        self::assertNull($line->variantId);
    }

    public function testAddingAnRxWithAVariantTakesThatVariantsPrice(): void
    {
        $cart = new Cart();
        (new CartRules($this->catalog()))->add($cart, 'sema', 'sema-3m');

        $line = $cart->line('sema');
        self::assertSame(13800, $line?->unitPriceCents);
        self::assertSame('sema-3m', $line?->variantId);
    }

    public function testAddingANonRxTakesTheFirstVariantPrice(): void
    {
        $cart = new Cart();
        (new CartRules($this->catalog()))->add($cart, 'organizer');

        self::assertSame(499, $cart->line('organizer')?->unitPriceCents);
    }

    public function testAnUnknownVariantIsRejectedRatherThanSilentlyDefaulted(): void
    {
        $cart = new Cart();
        $outcome = (new CartRules($this->catalog()))->add($cart, 'sema', 'sema-9y');

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::UNKNOWN_VARIANT, $outcome->notice);
        self::assertTrue($cart->isEmpty());
    }

    public function testMandatoryBundlesAndFreeAttachmentsComeAlongAsChildren(): void
    {
        $cart = new Cart();
        (new CartRules($this->catalog()))->add($cart, 'sema');

        self::assertCount(3, $cart->lines());

        $cmp = $cart->line('cmp');
        self::assertSame('sema', $cmp?->parentSlug);
        self::assertSame(2500, $cmp?->unitPriceCents);

        $syringe = $cart->line('syringe');
        self::assertSame('sema', $syringe?->parentSlug);
        self::assertSame(0, $syringe?->unitPriceCents);
    }

    public function testAnAttachmentIsAddedOnceWhenTwoParentsRequireIt(): void
    {
        $data = $this->catalogData();
        $data['second-parent'] = [
            'slug' => 'second-parent', 'name' => 'Second Parent', 'kind' => 'otc', 'emr_product_id' => 'emr-second',
            'variants' => [['id' => 'sp-1', 'name' => 'Standard', 'price_cents' => 1000]],
            'bundles' => ['cmp'],
        ];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();

        $rules->add($cart, 'sema');
        $cmpAfterFirstAttach = $cart->line('cmp');

        $rules->add($cart, 'second-parent');

        $cmpLines = array_values(array_filter($cart->lines(), static fn (CartLine $line): bool => $line->slug === 'cmp'));
        self::assertCount(1, $cmpLines);
        self::assertSame('sema', $cmpLines[0]->parentSlug);
        self::assertSame($cmpAfterFirstAttach?->quantity, $cmpLines[0]->quantity);
    }

    public function testBundlesRecurseIntoTheirOwnBundles(): void
    {
        $data = $this->catalogData();
        $data['cmp']['bundles'] = ['lipid'];
        $data['lipid'] = [
            'slug' => 'lipid', 'name' => 'Lipid Panel', 'kind' => 'lab', 'emr_product_id' => 'emr-lipid',
            'variants' => [['id' => 'lipid-1', 'name' => 'Panel', 'price_cents' => 1500]],
        ];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();

        $rules->add($cart, 'sema');

        self::assertTrue($cart->has('cmp'));
        self::assertTrue($cart->has('lipid'));
        self::assertSame('sema', $cart->line('cmp')?->parentSlug);
        self::assertSame('cmp', $cart->line('lipid')?->parentSlug);
    }

    public function testACycleInBundleReferencesTerminates(): void
    {
        $data = $this->catalogData();
        $data['loop-a'] = [
            'slug' => 'loop-a', 'name' => 'Loop A', 'kind' => 'lab', 'emr_product_id' => 'emr-la',
            'variants' => [['id' => 'la-1', 'name' => 'A', 'price_cents' => 100]],
            'bundles' => ['loop-b'],
        ];
        $data['loop-b'] = [
            'slug' => 'loop-b', 'name' => 'Loop B', 'kind' => 'lab', 'emr_product_id' => 'emr-lb',
            'variants' => [['id' => 'lb-1', 'name' => 'B', 'price_cents' => 200]],
            'bundles' => ['loop-a'],
        ];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();

        $outcome = $rules->add($cart, 'loop-a');

        self::assertTrue($outcome->accepted);
        self::assertCount(2, $cart->lines());
        self::assertTrue($cart->has('loop-a'));
        self::assertTrue($cart->has('loop-b'));
    }

    public function testAddingASecondPrescriptionReplacesTheFirstWithANotice(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->add($cart, 'tada');

        self::assertTrue($outcome->accepted);
        self::assertTrue($cart->has('tada'));
        self::assertFalse($cart->has('sema'));
        self::assertFalse($cart->has('cmp'));
        self::assertFalse($cart->has('syringe'));
        self::assertSame(sprintf(CartRules::RX_REPLACED, 'Semaglutide', 'Tadalafil'), $outcome->notice);
    }

    public function testAddingTheSamePrescriptionAgainDoesNotReplaceItself(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->add($cart, 'sema');

        self::assertNull($outcome->notice);
        self::assertCount(1, array_values(array_filter($cart->lines(), static fn (CartLine $line): bool => $line->slug === 'sema')));
        self::assertSame(1, $cart->line('sema')?->quantity);
    }

    public function testAnOtcProductAddedTwiceIncrements(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'organizer');
        $rules->add($cart, 'organizer');

        self::assertSame(2, $cart->line('organizer')?->quantity);
    }

    public function testAPrescriptionQuantityIsAlwaysOne(): void
    {
        $cart = new Cart();
        (new CartRules($this->catalog()))->add($cart, 'sema', null, 5);

        self::assertSame(1, $cart->line('sema')?->quantity);
    }

    public function testTheGeoGateRejectsABlockedProductWhenTheTerritoryIsKnown(): void
    {
        $cart = new Cart();
        $cart->shippingTerritory = 'NY';

        $outcome = (new CartRules($this->catalog()))->add($cart, 'sema');

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::GEO_BLOCKED, $outcome->notice);
        self::assertTrue($cart->isEmpty());
    }

    public function testTheGeoGateIsInertWhenNoTerritoryIsKnownYet(): void
    {
        $cart = new Cart();
        $cart->shippingTerritory = null;

        $outcome = (new CartRules($this->catalog()))->add($cart, 'sema');

        self::assertTrue($outcome->accepted);
    }

    public function testTheGeoGateIsReRunOnAQuantityIncrement(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'organizer');

        $cart->shippingTerritory = 'TX';
        $outcome = $rules->add($cart, 'organizer');

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::GEO_BLOCKED, $outcome->notice);
        self::assertSame(1, $cart->line('organizer')?->quantity);
    }

    public function testAProductWhoseRequiredBundleIsMissingFromTheCatalogIsRejected(): void
    {
        $cart = new Cart();
        $outcome = (new CartRules($this->catalog()))->add($cart, 'kit-parent');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::CHILD_UNAVAILABLE, 'Kit Parent', 'missing-lab'), $outcome->notice);
        self::assertTrue($cart->isEmpty());
    }

    public function testAProductWhoseAttachmentIsGeoBlockedIsRejected(): void
    {
        $cart = new Cart();
        $cart->shippingTerritory = 'AK';

        $outcome = (new CartRules($this->catalog()))->add($cart, 'sema');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::CHILD_UNAVAILABLE, 'Semaglutide', 'syringe'), $outcome->notice);
        self::assertTrue($cart->isEmpty());
    }

    public function testABundledChildCannotBeRemovedOnItsOwn(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->remove($cart, 'cmp');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::CHILD_LOCKED, 'Metabolic Panel', 'Semaglutide'), $outcome->notice);
        self::assertCount(3, $cart->lines());
    }

    public function testRemovingAParentRemovesEveryChildInOneOperation(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->remove($cart, 'sema');

        self::assertTrue($outcome->accepted);
        self::assertTrue($cart->isEmpty());
    }

    public function testRemovingSomethingNotInTheCartIsRejectedWithoutTouchingTheCart(): void
    {
        $cart = new Cart();
        $outcome = (new CartRules($this->catalog()))->remove($cart, 'nope');

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::UNKNOWN_PRODUCT, $outcome->notice);
        self::assertTrue($cart->isEmpty());
    }

    public function testEveryFreeAddonLineIsZeroPricedAfterEveryMutationPath(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());

        $rules->add($cart, 'sema');
        self::assertNoFreeAddonCarriesAPrice($cart);

        $rules->remove($cart, 'sema');
        self::assertNoFreeAddonCarriesAPrice($cart);

        $rules->add($cart, 'sema');
        self::assertNoFreeAddonCarriesAPrice($cart);

        $rules->add($cart, 'sema', 'sema-3m');
        self::assertNoFreeAddonCarriesAPrice($cart);
    }

    public function testASharedChildSurvivesTheDepartureOfThePrescriptionThatFirstAttachedIt(): void
    {
        $data = $this->catalogData();
        $data['second-parent'] = [
            'slug' => 'second-parent', 'name' => 'Second Parent', 'kind' => 'otc', 'emr_product_id' => 'emr-second',
            'variants' => [['id' => 'sp-1', 'name' => 'Standard', 'price_cents' => 1000]],
            'bundles' => ['cmp'],
        ];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();

        $rules->add($cart, 'sema');
        $rules->add($cart, 'second-parent');
        $rules->add($cart, 'tada');

        self::assertTrue($cart->has('cmp'));
        self::assertSame('second-parent', $cart->line('cmp')?->parentSlug);
        self::assertFalse($cart->has('syringe'));
        self::assertFalse($cart->has('sema'));
        self::assertTrue($cart->has('tada'));
        self::assertTrue($cart->has('second-parent'));
    }

    public function testASharedChildSurvivesRemovalOfWhicheverParentAttachedItFirst(): void
    {
        $data = $this->catalogData();
        $data['parent-a'] = [
            'slug' => 'parent-a', 'name' => 'Parent A', 'kind' => 'otc', 'emr_product_id' => 'emr-pa',
            'variants' => [['id' => 'pa-1', 'name' => 'Standard', 'price_cents' => 1000]],
            'bundles' => ['cmp'],
        ];
        $data['parent-b'] = [
            'slug' => 'parent-b', 'name' => 'Parent B', 'kind' => 'otc', 'emr_product_id' => 'emr-pb',
            'variants' => [['id' => 'pb-1', 'name' => 'Standard', 'price_cents' => 2000]],
            'bundles' => ['cmp'],
        ];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();

        $rules->add($cart, 'parent-a');
        $rules->add($cart, 'parent-b');
        self::assertSame('parent-a', $cart->line('cmp')?->parentSlug);

        $rules->remove($cart, 'parent-a');

        self::assertTrue($cart->has('cmp'));
        self::assertSame('parent-b', $cart->line('cmp')?->parentSlug);

        $rules->remove($cart, 'parent-b');

        self::assertFalse($cart->has('cmp'));
    }

    public function testAddingABundledChildDirectlyIsRejected(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->add($cart, 'cmp');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::CHILD_LOCKED, 'Metabolic Panel', 'Semaglutide'), $outcome->notice);
        self::assertSame(1, $cart->line('cmp')?->quantity);
        self::assertCount(3, $cart->lines());
    }

    public function testQuantityCanBeSetOnAnAccessory(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'organizer');

        $outcome = $rules->setQuantity($cart, 'organizer', 3);

        self::assertTrue($outcome->accepted);
        self::assertNull($outcome->notice);
        self::assertSame(3, $cart->line('organizer')?->quantity);
    }

    public function testQuantityIsCappedByTheCatalogsMaximumWithANotice(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'organizer');

        $outcome = $rules->setQuantity($cart, 'organizer', 9);

        self::assertTrue($outcome->accepted);
        self::assertSame(sprintf(CartRules::QTY_CAPPED, 3, 'Pill Organizer'), $outcome->notice);
        self::assertSame(3, $cart->line('organizer')?->quantity);
    }

    public function testQuantityIsCappedByTheDefaultWhenTheCatalogDeclaresNoMaximum(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'cmp');

        $outcome = $rules->setQuantity($cart, 'cmp', 99);

        self::assertTrue($outcome->accepted);
        self::assertSame(CartRules::DEFAULT_MAX_QUANTITY, $cart->line('cmp')?->quantity);
    }

    public function testAPrescriptionQuantityCannotBeChanged(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->setQuantity($cart, 'sema', 2);

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::RX_QTY_FIXED, $outcome->notice);
        self::assertSame(1, $cart->line('sema')?->quantity);
    }

    public function testABundledChildQuantityCannotBeChanged(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->setQuantity($cart, 'cmp', 2);

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::CHILD_LOCKED, 'Metabolic Panel', 'Semaglutide'), $outcome->notice);
        self::assertSame(1, $cart->line('cmp')?->quantity);
    }

    public function testSettingQuantityToZeroRemovesTheLine(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'organizer');

        $outcome = $rules->setQuantity($cart, 'organizer', 0);

        self::assertTrue($outcome->accepted);
        self::assertFalse($cart->has('organizer'));
    }

    /**
     * Pins the outcome, not the guard order: {@see CartRules::remove()} carries
     * its own independent child guard, so a child is refused at zero quantity
     * whichever of the two guards in {@see CartRules::setQuantity()} runs
     * first. That the child check is placed before the zero check is
     * established by reading the method, not by this test.
     */
    public function testABundledChildIsRefusedAtZeroQuantityRegardlessOfRoute(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->setQuantity($cart, 'cmp', 0);

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::CHILD_LOCKED, 'Metabolic Panel', 'Semaglutide'), $outcome->notice);
        self::assertTrue($cart->has('cmp'));
    }

    public function testSettingQuantityReRunsTheGeoGate(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'organizer');

        $cart->shippingTerritory = 'TX';
        $outcome = $rules->setQuantity($cart, 'organizer', 2);

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::GEO_BLOCKED, $outcome->notice);
        self::assertSame(1, $cart->line('organizer')?->quantity);
    }

    public function testSettingQuantityReAttachesAMissingChild(): void
    {
        $data = $this->catalogData();
        $data['organizer']['attachments'] = ['syringe'];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();

        $rules->add($cart, 'organizer');
        $cart->forget('syringe');

        $outcome = $rules->setQuantity($cart, 'organizer', 2);

        self::assertTrue($outcome->accepted);
        $syringe = $cart->line('syringe');
        self::assertNotNull($syringe);
        self::assertSame('organizer', $syringe->parentSlug);
        self::assertSame(0, $syringe->unitPriceCents);
    }

    public function testSettingQuantityRefusesWhenARequiredAttachmentIsBlockedInTheCurrentTerritory(): void
    {
        $data = $this->catalogData();
        $data['organizer']['attachments'] = ['syringe'];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();
        $rules->add($cart, 'organizer');

        $cart->shippingTerritory = 'AK';
        $outcome = $rules->setQuantity($cart, 'organizer', 2);

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::CHILD_UNAVAILABLE, 'Pill Organizer', 'syringe'), $outcome->notice);
        self::assertSame(1, $cart->line('organizer')?->quantity);
    }

    public function testApplyingATerritoryRefusesWhenARequiredChildIsCurrentlyAbsent(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');
        $cart->forget('syringe');

        $outcome = $rules->applyTerritory($cart, 'AK');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::TERRITORY_BLOCKED, 'Semaglutide'), $outcome->notice);
        self::assertNull($cart->shippingTerritory);
        self::assertTrue($cart->has('sema'));
        self::assertTrue($cart->has('cmp'));
    }

    /**
     * `cmp` is named because its own line is directly blocked in `NY`; `sema`
     * is named too because it requires `cmp`, so its own subtree cannot ship
     * there either. A top-level-only implementation would have missed the
     * blocked child entirely and named neither.
     */
    public function testApplyingATerritoryThatBlocksABundledChildIsCaught(): void
    {
        $data = $this->catalogData();
        $data['sema']['geo_blocks'] = [];
        $data['cmp']['geo_blocks'] = ['NY'];
        $rules = new CartRules(new FakeCatalog($data));
        $cart = new Cart();
        $rules->add($cart, 'sema');

        $outcome = $rules->applyTerritory($cart, 'NY');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::TERRITORY_BLOCKED, 'Semaglutide, Metabolic Panel'), $outcome->notice);
        self::assertTrue($cart->has('sema'));
        self::assertTrue($cart->has('cmp'));
        self::assertTrue($cart->has('syringe'));
        self::assertNull($cart->shippingTerritory);
    }

    public function testChoosingAPlanWritesBothTheVariantAndItsPrice(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->chooseVariant($cart, 'sema', 'sema-3m');

        self::assertTrue($outcome->accepted);
        self::assertSame('sema-3m', $cart->line('sema')?->variantId);
        self::assertSame(13800, $cart->line('sema')?->unitPriceCents);
    }

    public function testChoosingAVariantThatBelongsToAnotherProductIsRejected(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->chooseVariant($cart, 'sema', 'tada-1m');

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::UNKNOWN_VARIANT, $outcome->notice);
        self::assertSame(0, $cart->line('sema')?->unitPriceCents);
    }

    public function testChoosingAPlanForAProductNotInTheCartIsRejected(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());

        $outcome = $rules->chooseVariant($cart, 'sema', 'sema-3m');

        self::assertFalse($outcome->accepted);
        self::assertSame(CartRules::UNKNOWN_PRODUCT, $outcome->notice);
    }

    public function testRemovingAProductDiscardsItsPlanSelection(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');
        $rules->chooseVariant($cart, 'sema', 'sema-3m');

        $rules->remove($cart, 'sema');
        $rules->add($cart, 'sema');

        self::assertNull($cart->line('sema')?->variantId);
        self::assertSame(0, $cart->line('sema')?->unitPriceCents);
    }

    public function testApplyingATerritoryRecordsItAndAcceptsAnUnblockedCart(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'organizer');

        $outcome = $rules->applyTerritory($cart, 'CA');

        self::assertTrue($outcome->accepted);
        self::assertSame('CA', $cart->shippingTerritory);
    }

    public function testApplyingABlockedTerritoryNamesEveryBlockedProductAndLeavesTheCartIntact(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');
        $rules->add($cart, 'organizer');

        $outcome = $rules->applyTerritory($cart, 'NY');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::TERRITORY_BLOCKED, 'Semaglutide'), $outcome->notice);
        self::assertTrue($cart->has('sema'));
        self::assertTrue($cart->has('cmp'));
        self::assertTrue($cart->has('syringe'));
        self::assertTrue($cart->has('organizer'));
        self::assertNull($cart->shippingTerritory);
    }

    public function testTerritoryMatchingIsCaseInsensitive(): void
    {
        $cart = new Cart();
        $rules = new CartRules($this->catalog());
        $rules->add($cart, 'sema');

        $outcome = $rules->applyTerritory($cart, 'ny');

        self::assertFalse($outcome->accepted);
        self::assertSame(sprintf(CartRules::TERRITORY_BLOCKED, 'Semaglutide'), $outcome->notice);
    }
}
