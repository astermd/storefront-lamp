<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Forms\DefinitionCache;
use PHPUnit\Framework\TestCase;

final class DefinitionCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/teleform-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testStoresAndReturnsADefinition(): void
    {
        $cache = new DefinitionCache($this->dir, 3600);
        $cache->put('acct/org/form_13_1787491225.json', ['formId' => 'f1', 'pages' => []]);

        self::assertSame(['formId' => 'f1', 'pages' => []], $cache->get('acct/org/form_13_1787491225.json'));
    }

    public function testAnIdentifierWithPathSeparatorsCannotEscapeTheCacheDirectory(): void
    {
        $cache = new DefinitionCache($this->dir, 3600);
        $cache->put('../../etc/passwd', ['formId' => 'f1', 'pages' => []]);

        $written = glob($this->dir . '/*') ?: [];
        self::assertCount(1, $written);
        self::assertSame($this->dir, dirname($written[0]));
    }

    public function testARepublishedFormIsACacheMissBecauseItsIdentifierChanged(): void
    {
        $cache = new DefinitionCache($this->dir, 3600);
        $cache->put('acct/org/form_13_1787491225.json', ['formId' => 'f1', 'version' => 13]);

        self::assertNull($cache->get('acct/org/form_14_1787599999.json'));
    }

    public function testADefinitionOlderThanTheTtlIsNotReturned(): void
    {
        $cache = new DefinitionCache($this->dir, 1);
        $cache->put('acct/org/form_13_1787491225.json', ['formId' => 'f1']);

        $written = (glob($this->dir . '/*') ?: [])[0];
        touch($written, time() - 120);
        clearstatcache(true, $written);

        self::assertNull($cache->get('acct/org/form_13_1787491225.json'));
    }

    public function testPurgeRemovesEveryCachedDefinitionAndReportsHowMany(): void
    {
        $cache = new DefinitionCache($this->dir, 3600);
        $cache->put('a_1_1.json', ['formId' => 'a']);
        $cache->put('b_1_1.json', ['formId' => 'b']);

        self::assertSame(2, $cache->purge());
        self::assertNull($cache->get('a_1_1.json'));
    }
}
