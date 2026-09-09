<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Bootstrap;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Verification\EmrIdentityGateway;
use AsterMD\Storefront\Verification\IdentityGateway;
use AsterMD\Storefront\Verification\NullIdentityGateway;
use PHPUnit\Framework\TestCase;

/**
 * `Config::class` is a container override that the container actually honours.
 *
 * This is a seam test rather than a feature test, and it exists because its
 * absence hid two defects. `AppFactory` built most of its bindings around a
 * `$config` captured lexically at the top of `create()`, so the second
 * argument — this project's documented test seam — could substitute a gateway
 * but could not substitute a *setting*. The consequence was that no HTTP test
 * anywhere could run the funnel with `verification.enabled` true: every
 * assertion about the identity step was an assertion about the disabled one,
 * `blocking => true` appeared in exactly one unit test that never touched
 * HTTP, and the blocking branch was reachable only by editing a shipped file.
 *
 * So what is pinned here is the seam itself: an overridden `Config` must reach
 * the three readers of `config/verification.php` that decide whether the step
 * exists, whether it blocks, and whether the provider is called at all. A
 * binding that quietly goes back to the captured `$config` fails here rather
 * than silently narrowing what every other test is able to say.
 */
final class ConfigSeamTest extends TestCase
{
    use ConfigVariant;

    public function testAnOverriddenConfigChangesWhereTheRouterSendsAFinishedQuestionnaire(): void
    {
        $router = $this->container($this->verificationConfig([
            'enabled' => true,
            'placement' => 'intake',
        ]))->get(FunnelRouter::class);

        self::assertInstanceOf(FunnelRouter::class, $router);
        self::assertSame('verify', $router->nextStep($this->cart(), $this->finishedQuestionnaire()));
    }

    public function testTheShippedConfigurationStillRoutesStraightToCheckout(): void
    {
        // The other half of the seam: an override changes the answer, and the
        // absence of one leaves the shipped answer alone.
        $router = $this->container()->get(FunnelRouter::class);

        self::assertInstanceOf(FunnelRouter::class, $router);
        self::assertSame('checkout', $router->nextStep($this->cart(), $this->finishedQuestionnaire()));
    }

    public function testAnOverriddenConfigDecidesWhetherTheGuardBlocks(): void
    {
        $blocking = $this->container($this->verificationConfig([
            'enabled' => true,
            'placement' => 'intake',
            'blocking' => true,
        ]))->get(StepPreconditions::class);
        $recording = $this->container($this->verificationConfig([
            'enabled' => true,
            'placement' => 'intake',
            'blocking' => false,
        ]))->get(StepPreconditions::class);

        self::assertInstanceOf(StepPreconditions::class, $blocking);
        self::assertInstanceOf(StepPreconditions::class, $recording);
        self::assertFalse(
            $blocking->satisfied('verification_satisfied', $this->cart(), $this->finishedQuestionnaire()),
            'a blocking placement must refuse a journey that has not passed',
        );
        self::assertTrue(
            $recording->satisfied('verification_satisfied', $this->cart(), $this->finishedQuestionnaire()),
            'a non-blocking placement records and lets the journey through',
        );
    }

    public function testAnOverriddenConfigDecidesWhetherTheProviderIsCalledAtAll(): void
    {
        self::assertInstanceOf(
            NullIdentityGateway::class,
            $this->container()->get(IdentityGateway::class),
            'the shipped configuration reaches no provider',
        );
        self::assertInstanceOf(
            EmrIdentityGateway::class,
            $this->container($this->verificationConfig(['enabled' => true]))->get(IdentityGateway::class),
            'an enabled deployment resolves the real gateway',
        );
    }

    // ----------------------------------------------------------- fixtures

    /** The container of an app built with $config, or with the shipped one when none is given. */
    private function container(?Config $config = null): \Psr\Container\ContainerInterface
    {
        $app = AppFactory::create(dirname(__DIR__, 2), array_filter([
            Config::class => $config,
            ProductCatalog::class => self::catalog(),
        ], static fn (mixed $value): bool => $value !== null));

        $container = $app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    /** A cart holding the one product the fake catalog knows, with a plan chosen. */
    private function cart(): Cart
    {
        return Cart::fromArray([
            'session' => null,
            'territory' => 'OR',
            'lines' => [[
                'slug' => 'tirzepatide', 'name' => 'Tirzepatide', 'kind' => 'rx',
                'emr_product_id' => null, 'parent_slug' => null,
                'quantity' => 1, 'unit_price_cents' => 24500, 'variant_id' => 't-1m',
            ]],
        ]);
    }

    /**
     * A journey that has answered everything the funnel asks before the
     * identity step, so the only thing that can move the router's answer is
     * the verification configuration under test.
     */
    private function finishedQuestionnaire(): JourneyState
    {
        return JourneyState::fromArray(
            ['form_status' => ['tf-seam' => JourneyState::FORM_COMPLETED]],
            null,
            null,
        );
    }

    private static function catalog(): ProductCatalog
    {
        return new FakeCatalog(['tirzepatide' => [
            'slug' => 'tirzepatide',
            'name' => 'Tirzepatide',
            'kind' => 'rx',
            'teleform_id' => 'tf-seam',
            'variants' => [[
                'id' => 't-1m', 'name' => '1 Month', 'price_cents' => 24500,
                'provider' => ['offer_id' => '337', 'product_id' => '3414'],
            ]],
        ]]);
    }
}
