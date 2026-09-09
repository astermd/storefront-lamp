<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Upsell;

use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Upsell\Upsells;
use PHPUnit\Framework\TestCase;

/**
 * The upsell layer's two questions (§16): which offers this journey earned,
 * and what one of them looks like at the moment it is about to be shown.
 *
 * They are asked at different times on purpose, so every case below that fixes
 * an ordering or a skip is really fixing the seam between them.
 */
final class UpsellsTest extends TestCase
{
    private function catalog(): FakeCatalog
    {
        return new FakeCatalog([
            'semaglutide' => ['slug' => 'semaglutide', 'name' => 'Semaglutide', 'kind' => 'rx', 'price_cents' => 12000],
            'tirzepatide' => ['slug' => 'tirzepatide', 'name' => 'Tirzepatide', 'kind' => 'rx', 'price_cents' => 14000],
            'ramelteon' => ['slug' => 'ramelteon', 'name' => 'Ramelteon', 'kind' => 'rx', 'price_cents' => 8000],
            // Two plans at different prices, and `price_cents` mirrors the
            // first exactly as CatalogBuilder writes it, so an upsell naming
            // the second has to read past it.
            'wellness-pack' => [
                'slug' => 'wellness-pack',
                'name' => 'Wellness Pack',
                'kind' => 'otc',
                'price_cents' => 1299,
                'variants' => [
                    ['id' => 'wp-1m', 'name' => '1 month', 'price_cents' => 1299, 'provider' => ['offer_id' => '412', 'product_id' => '3600']],
                    ['id' => 'wp-3m', 'name' => '3 months', 'price_cents' => 3299, 'provider' => ['offer_id' => '413', 'product_id' => '3601']],
                ],
            ],
            // No variants at all, which the recorded live channel does have:
            // orderable price, no provider identity.
            'sleep-kit' => ['slug' => 'sleep-kit', 'name' => 'Sleep Kit', 'kind' => 'otc', 'price_cents' => 2400],
        ]);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return ['upsells' => [
            'wellness-pack' => [
                'slug' => 'wellness-pack',
                'offer_after' => ['semaglutide', 'tirzepatide'],
                'eyebrow' => 'Wait! Your Exclusive Offer!',
                'headline' => 'Enhance Your Wellness Journey',
                'body' => 'Optimize your daily routine.',
                'bullets' => ['Immune Support', 'Energy Boost'],
                'image' => '/assets/img/upsell.png',
                'price_cents_override' => 899,
            ],
            'sleep-kit' => [
                'slug' => 'sleep-kit',
                'offer_after' => ['ramelteon'],
            ],
            'ghost' => [
                'slug' => 'not-in-the-catalog',
                'offer_after' => ['semaglutide'],
            ],
        ]];
    }

    /** @param array<string, mixed>|null $config */
    private function upsells(?array $config = null, ?CapturedLog $log = null): Upsells
    {
        return Upsells::fromConfig(
            $config ?? $this->config(),
            $this->catalog(),
            ($log ?? new CapturedLog())->log,
        );
    }

    public function testTheQueueIsEveryUpsellWhoseOfferAfterNamesAPurchasedSlug(): void
    {
        // [16.1]: membership is decided from what was just bought.
        self::assertSame(['wellness-pack', 'ghost'], $this->upsells()->queueFor(['semaglutide']));
    }

    public function testTwoPurchasedProductsTriggeringOneUpsellQueueItOnce(): void
    {
        // [16.2]: `wellness-pack` is earned by both purchases and appears
        // once. `ghost` rides along because `semaglutide` earns it too.
        $queue = $this->upsells()->queueFor(['semaglutide', 'tirzepatide']);

        self::assertSame(['wellness-pack', 'ghost'], $queue);
        self::assertCount(1, array_keys($queue, 'wellness-pack', true));
    }

    public function testTheQueueFollowsConfigurationOrderNotPurchaseOrder(): void
    {
        // 'sleep-kit' is configured after 'wellness-pack', so it comes second
        // however the purchases are ordered.
        self::assertSame(
            ['wellness-pack', 'sleep-kit', 'ghost'],
            $this->upsells()->queueFor(['ramelteon', 'semaglutide']),
        );
        self::assertSame(
            ['wellness-pack', 'sleep-kit', 'ghost'],
            $this->upsells()->queueFor(['semaglutide', 'ramelteon']),
        );
    }

    public function testNothingPurchasedQueuesNothing(): void
    {
        self::assertSame([], $this->upsells()->queueFor([]));
        self::assertSame([], $this->upsells()->queueFor(['finasteride']));
    }

    public function testAnUpsellWithNoOfferAfterEntriesIsNeverQueued(): void
    {
        // Nothing earns it, so nothing offers it. The validator errors on the
        // same configuration, which is where a misconfiguration belongs.
        $upsells = $this->upsells(['upsells' => [
            'orphan' => ['slug' => 'sleep-kit', 'offer_after' => []],
        ]]);

        self::assertSame([], $upsells->queueFor(['semaglutide', 'sleep-kit']));
        self::assertSame(['orphan'], $upsells->keys());
    }

    public function testAnUnresolvableEntryIsQueuedAndSkippedAtResolution(): void
    {
        // [16.4]: the queue is built once at checkout and stepped later, so
        // configuration can change in between. Filtering here would hide a
        // misconfiguration that [16.17]'s validator is meant to catch loudly.
        $log = new CapturedLog();
        $upsells = $this->upsells(log: $log);

        self::assertContains('ghost', $upsells->queueFor(['semaglutide']));
        self::assertNull($upsells->resolve('ghost'));
        self::assertSame('upsell.unresolvable', $log->lastWarning()['event'] ?? null);
        self::assertSame('ghost', $log->lastWarning()['context']['key'] ?? null);
        self::assertSame('not-in-the-catalog', $log->lastWarning()['context']['slug'] ?? null);
    }

    public function testAKeyThatWasNeverConfiguredResolvesToNothing(): void
    {
        self::assertNull($this->upsells()->resolve('never-configured'));
    }

    public function testAResolvedUpsellCarriesTheOverridePriceAndTheProviderMapping(): void
    {
        $upsell = $this->upsells()->resolve('wellness-pack');

        self::assertNotNull($upsell);
        self::assertSame('wellness-pack', $upsell->key);
        self::assertSame('wellness-pack', $upsell->slug);
        self::assertNull($upsell->variantId);
        self::assertSame(899, $upsell->priceCents, 'the override wins over the catalog price');
        self::assertSame('Wellness Pack', $upsell->name, 'the name comes from the catalog, not the config');
        self::assertSame('Enhance Your Wellness Journey', $upsell->headline);
        self::assertSame('Wait! Your Exclusive Offer!', $upsell->eyebrow);
        self::assertSame('Optimize your daily routine.', $upsell->body);
        self::assertSame(['Immune Support', 'Energy Boost'], $upsell->bullets);
        self::assertSame('/assets/img/upsell.png', $upsell->image);
        self::assertSame('412', $upsell->providerOffer, 'no variant named, so the first variant answers');
        self::assertSame('3600', $upsell->providerItem);
        self::assertSame('otc', $upsell->kind);
    }

    public function testAResolvedUpsellWithoutCopyFallsBackToTheCatalogName(): void
    {
        $upsell = $this->upsells()->resolve('sleep-kit');

        self::assertNotNull($upsell);
        self::assertSame('Sleep Kit', $upsell->headline);
        self::assertSame('', $upsell->eyebrow);
        self::assertSame('', $upsell->body);
        self::assertSame([], $upsell->bullets);
        self::assertNull($upsell->image);
        self::assertSame('Add to Order', $upsell->acceptLabel);
        self::assertSame('No Thanks', $upsell->declineLabel);
    }

    public function testTheConfiguredLabelsWinOverTheMockupsDefaults(): void
    {
        $upsell = $this->upsells(['upsells' => [
            'sleep-kit' => [
                'slug' => 'sleep-kit',
                'offer_after' => ['ramelteon'],
                'accept_label' => 'Yes, add my kit',
                'decline_label' => 'Skip this',
            ],
        ]])->resolve('sleep-kit');

        self::assertNotNull($upsell);
        self::assertSame('Yes, add my kit', $upsell->acceptLabel);
        self::assertSame('Skip this', $upsell->declineLabel);
    }

    public function testAnUpsellOfferingAVariantIsPricedAtThatVariantAndMappedToIt(): void
    {
        // The same undercharge OrderBumps guards against: `price_cents`
        // mirrors variants[0], and every downstream number would agree with
        // it, so nothing else could catch the wrong plan being charged for.
        $upsell = $this->upsells(['upsells' => [
            'wellness-pack' => ['slug' => 'wellness-pack', 'variant_id' => 'wp-3m', 'offer_after' => ['semaglutide']],
        ]])->resolve('wellness-pack');

        self::assertNotNull($upsell);
        self::assertSame('wp-3m', $upsell->variantId);
        self::assertSame(3299, $upsell->priceCents);
        self::assertSame('413', $upsell->providerOffer);
        self::assertSame('3601', $upsell->providerItem);
    }

    public function testAnUpsellNamingAVariantTheProductDoesNotHaveIsUnresolvable(): void
    {
        // Null rather than a fallback, for OrderBumps::variantPrice()'s
        // reason: the only prices available to fall back to belong to a
        // different plan.
        $log = new CapturedLog();
        $upsells = $this->upsells(['upsells' => [
            'wellness-pack' => ['slug' => 'wellness-pack', 'variant_id' => 'wp-12m', 'offer_after' => ['semaglutide']],
        ]], log: $log);

        self::assertNull($upsells->resolve('wellness-pack'));
        self::assertSame('upsell.unresolvable', $log->lastWarning()['event'] ?? null);
        self::assertSame('wp-12m', $log->lastWarning()['context']['variant_id'] ?? null);
    }

    public function testAnUpsellWithNoProviderMappingStillResolves(): void
    {
        // Whether an unchargeable offer is survivable is the charge's
        // decision, not this class's — and the validator refuses the same
        // configuration at config time, which is where it can be fixed.
        $upsell = $this->upsells()->resolve('sleep-kit');

        self::assertNotNull($upsell);
        self::assertSame(2400, $upsell->priceCents);
        self::assertNull($upsell->providerOffer);
        self::assertNull($upsell->providerItem);
    }

    public function testKeysAreEveryConfiguredEntryInConfigurationOrder(): void
    {
        self::assertSame(['wellness-pack', 'sleep-kit', 'ghost'], $this->upsells()->keys());
    }

    public function testTheShippedConfigurationOffersNothing(): void
    {
        // Ships empty on purpose: no synced variant carries a provider
        // mapping, so nothing here could be charged for even if it were
        // offered.
        $config = require __DIR__ . '/../../config/upsells.php';

        self::assertSame([], $config['upsells']);
        self::assertSame([], $this->upsells($config)->keys());
    }
}
