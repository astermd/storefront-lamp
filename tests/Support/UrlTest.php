<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    public function testAddsTrailingSlashAndLowercases(): void
    {
        self::assertSame('/products/semaglutide/', Url::canonicalizePath('/Products/Semaglutide'));
    }

    public function testCollapsesDuplicateSlashes(): void
    {
        self::assertSame('/cart/', Url::canonicalizePath('//cart///'));
    }

    public function testRootIsSingleSlash(): void
    {
        self::assertSame('/', Url::canonicalizePath(''));
        self::assertSame('/', Url::canonicalizePath('/'));
    }

    public function testToBuildsCanonicalUrlWithQuery(): void
    {
        self::assertSame('/treatments/?category=weight-loss', Url::to('/treatments', ['category' => 'weight-loss']));
        self::assertSame('/checkout/', Url::to('checkout'));
    }
}
