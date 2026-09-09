<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Funnel;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Support\Config;
use PHPUnit\Framework\TestCase;

final class FlowDefinitionTest extends TestCase
{
    /** @return array<string, array{path: string, requires: list<string>}> */
    private function steps(): array
    {
        return [
            'home' => ['path' => '/', 'requires' => []],
            'checkout' => ['path' => '/checkout/', 'requires' => ['cart_not_empty']],
        ];
    }

    public function testStepsResolveBothWays(): void
    {
        $flow = new FlowDefinition($this->steps());

        self::assertSame('checkout', $flow->stepForPath('/checkout/'));
        self::assertSame('/checkout/', $flow->pathFor('checkout'));
    }

    public function testAnUnlistedPathIsNotAStep(): void
    {
        $flow = new FlowDefinition($this->steps());

        self::assertNull($flow->stepForPath('/treatments/'));
    }

    public function testPathsAreMatchedCanonically(): void
    {
        $flow = new FlowDefinition($this->steps());

        self::assertSame('checkout', $flow->stepForPath('/Checkout'));
    }

    public function testEveryRequirementNameInTheShippedConfigIsKnown(): void
    {
        $config = Config::load(dirname(__DIR__, 2) . '/config');
        $flow = FlowDefinition::fromConfig($config);
        $preconditions = new StepPreconditions(new FakeCatalog([]));
        $cart = new Cart();

        $checked = 0;
        foreach ($flow->steps() as $definition) {
            foreach ($definition['requires'] as $requirement) {
                $preconditions->satisfied($requirement, $cart, null);
                $checked++;
            }
        }

        self::assertGreaterThan(0, $checked);
    }
}
