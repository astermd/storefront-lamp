<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Checkout;

use AsterMD\Storefront\Checkout\OrderBumps;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use PHPUnit\Framework\TestCase;

final class OrderBumpsTest extends TestCase
{
    private function catalog(): FakeCatalog
    {
        return new FakeCatalog([
            'tirzepatide-5mg' => ['slug' => 'tirzepatide-5mg', 'name' => 'Tirzepatide', 'kind' => 'rx', 'price_cents' => 12000],
            'nad-500' => ['slug' => 'nad-500', 'name' => 'NAD+', 'kind' => 'rx', 'price_cents' => 10000],
            'anti-nausea-kit' => ['slug' => 'anti-nausea-kit', 'name' => 'Anti-Nausea Kit', 'kind' => 'otc', 'price_cents' => 2900],
            'electrolytes' => ['slug' => 'electrolytes', 'name' => 'Electrolyte Powder', 'kind' => 'otc', 'price_cents' => 1900],
            'sharps' => ['slug' => 'sharps', 'name' => 'Sharps Container', 'kind' => 'otc', 'price_cents' => 1500],
            'ny-blocked' => ['slug' => 'ny-blocked', 'name' => 'Blocked Thing', 'kind' => 'otc', 'price_cents' => 500, 'geo_blocks' => ['NY']],
            // A product whose default variant is not the one a bump offers.
            // `price_cents` mirrors variants[0] exactly as CatalogBuilder
            // writes it, so a bump naming `nad-3m` has to read past it.
            'nad-course' => [
                'slug' => 'nad-course',
                'name' => 'NAD+ Course',
                'kind' => 'rx',
                'price_cents' => 4900,
                'variants' => [
                    ['id' => 'nad-1m', 'name' => '1 month', 'price_cents' => 4900],
                    ['id' => 'nad-3m', 'name' => '3 months', 'price_cents' => 13200],
                ],
            ],
        ]);
    }

    private function cartWith(string ...$slugs): Cart
    {
        $cart = new Cart();
        foreach ($slugs as $slug) {
            $product = $this->catalog()->product($slug) ?? [];
            $cart->put(new CartLine(
                slug: $slug,
                name: (string) ($product['name'] ?? $slug),
                kind: (string) ($product['kind'] ?? 'otc'),
                emrProductId: null,
                parentSlug: null,
                quantity: 1,
                unitPriceCents: (int) ($product['price_cents'] ?? 0),
                variantId: 'v1',
            ));
        }

        return $cart;
    }

    /** @param array<string, mixed> $bumps */
    private function bumps(array $bumps, int $max = 3, ?CapturedLog $log = null): OrderBumps
    {
        return OrderBumps::fromConfig(
            ['max_on_page' => $max, 'bumps' => $bumps],
            $this->catalog(),
            ($log ?? new CapturedLog())->log,
        );
    }

    /** @return array<string, mixed> */
    private function bump(string $slug, int $position, ?int $override = null, ?string $variantId = null): array
    {
        return [
            'key' => $slug,
            'slug' => $slug,
            'variant_id' => $variantId,
            'headline' => 'Add ' . $slug,
            'body' => 'Because.',
            'position' => $position,
            'price_cents_override' => $override,
        ];
    }

    public function testABumpIsOfferedWhenItsTriggerIsInTheCart(): void
    {
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('anti-nausea-kit', 1)]])
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertCount(1, $offered);
        self::assertSame('anti-nausea-kit', $offered[0]->slug);
    }

    public function testNothingIsOfferedWhenNoTriggerIsInTheCart(): void
    {
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('anti-nausea-kit', 1)]])
            ->offeredFor($this->cartWith('nad-500'), 'CA');

        self::assertSame([], $offered);
    }

    public function testTheSameBumpTriggeredByTwoProductsIsShownOnce(): void
    {
        // [27.6].
        $offered = $this->bumps([
            'tirzepatide-5mg' => [$this->bump('anti-nausea-kit', 1)],
            'nad-500' => [$this->bump('anti-nausea-kit', 2)],
        ])->offeredFor($this->cartWith('tirzepatide-5mg', 'nad-500'), 'CA');

        self::assertCount(1, $offered);
    }

    public function testBumpsAreOrderedByTheirConfiguredPosition(): void
    {
        $offered = $this->bumps([
            'tirzepatide-5mg' => [
                $this->bump('sharps', 3),
                $this->bump('anti-nausea-kit', 1),
                $this->bump('electrolytes', 2),
            ],
        ])->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertSame(['anti-nausea-kit', 'electrolytes', 'sharps'], array_map(
            static fn ($b): string => $b->slug,
            $offered,
        ));
    }

    public function testABumpOfferingSomethingAlreadyInTheCartIsSuppressed(): void
    {
        // [27.7]: not shown, and ignored.
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('electrolytes', 1)]])
            ->offeredFor($this->cartWith('tirzepatide-5mg', 'electrolytes'), 'CA');

        self::assertSame([], $offered);
    }

    public function testABumpBlockedInTheBuyersTerritoryIsNotOffered(): void
    {
        // [27.11]: the geo gate applies to a bump like any other line, so a
        // bump nobody in this state could receive is never shown.
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('ny-blocked', 1)]])
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'NY');

        self::assertSame([], $offered);
        self::assertCount(1, $this->bumps(['tirzepatide-5mg' => [$this->bump('ny-blocked', 1)]])
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA'));
    }

    public function testBeyondTheConfiguredMaximumBumpsAreDroppedAndTheDropIsLogged(): void
    {
        // [27.8]: no silent truncation.
        $log = new CapturedLog();
        $offered = $this->bumps([
            'tirzepatide-5mg' => [
                $this->bump('anti-nausea-kit', 1),
                $this->bump('electrolytes', 2),
                $this->bump('sharps', 3),
            ],
        ], max: 2, log: $log)->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertCount(2, $offered);
        self::assertSame(['anti-nausea-kit', 'electrolytes'], array_map(static fn ($b): string => $b->slug, $offered));
        self::assertSame('checkout.bumps_truncated', $log->lastInfo()['event'] ?? null);
        self::assertSame(['sharps'], $log->lastInfo()['context']['dropped'] ?? null);
    }

    public function testABumpNamingAProductThatDoesNotExistIsSkippedAndLogged(): void
    {
        // [27.9]: bump definitions are validated with the same rigour as the
        // catalog. At render time a bad reference must not take the page down.
        $log = new CapturedLog();
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('does-not-exist', 1)]], log: $log)
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertSame([], $offered);
        self::assertSame('checkout.bump_unresolvable', $log->lastWarning()['event'] ?? null);
    }

    public function testABumpPriceOverrideIsCarriedOnTheOffer(): void
    {
        // [27.5]: the discount is the entire persuasive mechanism.
        // [27.12]: it applies to that line only and does not alter the catalog.
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('anti-nausea-kit', 1, override: 900)]])
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertSame(900, $offered[0]->priceCentsOverride);
        self::assertSame(2900, $this->catalog()->product('anti-nausea-kit')['price_cents']);
    }

    public function testABumpWithNoOverrideCarriesNull(): void
    {
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('anti-nausea-kit', 1)]])
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertNull($offered[0]->priceCentsOverride);
    }

    public function testABumpOfferingAVariantIsPricedAtThatVariant(): void
    {
        // `[27.5]`: the offer is the variant it names. Pricing it from the
        // product's `price_cents` -- which mirrors variants[0] -- charges for
        // whichever plan happens to be first, and every downstream number
        // (the card page, the provider's `order_offer_price`, the order line)
        // agrees with it, so nothing else can catch the undercharge.
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('nad-course', 1, variantId: 'nad-3m')]])
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertCount(1, $offered);
        self::assertSame('nad-3m', $offered[0]->variantId);
        self::assertSame(13200, $offered[0]->priceCents());
        self::assertSame(13200, $offered[0]->catalogPriceCents);
    }

    public function testABumpNamingNoVariantKeepsTheProductsOwnPrice(): void
    {
        $offered = $this->bumps(['tirzepatide-5mg' => [$this->bump('nad-course', 1)]])
            ->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertNull($offered[0]->variantId);
        self::assertSame(4900, $offered[0]->priceCents());
    }

    public function testAnOverrideStillWinsOverTheVariantsPrice(): void
    {
        // `[27.12]`: the discount is per line, and it is the discount that is
        // charged -- the variant only decides what it is a discount from.
        $offered = $this->bumps(['tirzepatide-5mg' => [
            $this->bump('nad-course', 1, override: 9900, variantId: 'nad-3m'),
        ]])->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertSame(9900, $offered[0]->priceCents());
        self::assertSame(13200, $offered[0]->catalogPriceCents);
    }

    public function testABumpNamingAVariantTheProductDoesNotHaveIsSkippedAndLogged(): void
    {
        // `[27.9]`, and the same precedent as an unknown slug: a bump whose
        // price cannot be resolved is dropped, never quietly re-priced from
        // some other variant. `CartRules::add()` would refuse the variant
        // anyway, so offering it could only end in a rejected accept.
        $log = new CapturedLog();
        $offered = $this->bumps(
            ['tirzepatide-5mg' => [$this->bump('nad-course', 1, variantId: 'nad-12m')]],
            log: $log,
        )->offeredFor($this->cartWith('tirzepatide-5mg'), 'CA');

        self::assertSame([], $offered);
        self::assertSame('checkout.bump_unresolvable', $log->lastWarning()['event'] ?? null);
        self::assertSame('nad-course', $log->lastWarning()['context']['slug'] ?? null);
        self::assertSame('nad-12m', $log->lastWarning()['context']['variant_id'] ?? null);
    }

    public function testTheShippedConfigurationOffersNothing(): void
    {
        // Ships empty on purpose: every product in this channel's catalog is a
        // prescription, and an order carries at most one.
        $config = require __DIR__ . '/../../config/cross-sells.php';

        self::assertSame([], $config['bumps']);
        self::assertSame(3, $config['max_on_page']);
    }
}
