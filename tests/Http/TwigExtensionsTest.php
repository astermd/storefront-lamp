<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Http\TwigExtensions;
use AsterMD\Storefront\Support\AssetManifest;
use PHPUnit\Framework\TestCase;

final class TwigExtensionsTest extends TestCase
{
    private TwigExtensions $extensions;

    protected function setUp(): void
    {
        $this->extensions = new TwigExtensions(new AssetManifest(sys_get_temp_dir() . '/does-not-exist-manifest.json'));
    }

    private function filter(string $name): callable
    {
        foreach ($this->extensions->getFilters() as $filter) {
            if ($filter->getName() === $name) {
                return $filter->getCallable();
            }
        }

        self::fail("Filter {$name} not registered");
    }

    private function function_(string $name): callable
    {
        foreach ($this->extensions->getFunctions() as $function) {
            if ($function->getName() === $name) {
                return $function->getCallable();
            }
        }

        self::fail("Function {$name} not registered");
    }

    public function testMoneyFormatsCentsAsDollars(): void
    {
        $money = $this->filter('money');

        self::assertSame('$46.00', $money(4600));
        self::assertSame('$138.00', $money(13800));
        self::assertSame('$1,234,567.89', $money(123456789));
        self::assertSame('$0.00', $money(0));
    }

    public function testUrlWrapsUrlToWithCanonicalization(): void
    {
        $url = $this->function_('url');

        self::assertSame('/treatments/', $url('/Treatments'));
    }

    public function testAssetFallsBackToLogicalNameWhenManifestMissing(): void
    {
        $asset = $this->function_('asset');

        self::assertSame('/assets/build/app.css', $asset('app.css'));
    }
}
