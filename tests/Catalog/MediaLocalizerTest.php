<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Catalog;

use AsterMD\Storefront\Catalog\MediaLocalizer;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

final class MediaLocalizerTest extends TestCase
{
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir() . '/media-localizer-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->mediaDir)) {
            return;
        }

        foreach (glob($this->mediaDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->mediaDir);
    }

    /** @return array<string, mixed> */
    private static function catalogWith(string $slug, ?string $remoteImage): array
    {
        return [
            'channel' => ['id' => 'c1', 'name' => 'Flow 1', 'currency' => 'USD'],
            'products' => [
                $slug => [
                    'slug' => $slug,
                    'name' => 'Tadalafil',
                    'image' => '/assets/img/product-placeholder.svg',
                    'gallery' => ['/assets/img/product-placeholder.svg'],
                    'remote_image' => $remoteImage,
                ],
            ],
        ];
    }

    public function testDownloadsHashesAndRewritesImageAndGalleryOnSuccess(): void
    {
        $fake = new FakeEmrHttpClient(['/images/tadalafil.jpg' => [200, 'binary-bytes']]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', 'images/tadalafil.jpg'));

        $expectedHash = substr(hash('sha256', 'binary-bytes'), 0, 8);
        $expectedFilename = "abc123-tadalafil.{$expectedHash}.jpg";
        $expectedPath = "/assets/media/{$expectedFilename}";

        self::assertSame($expectedPath, $result['catalog']['products']['abc123-tadalafil']['image']);
        self::assertSame($expectedPath, $result['catalog']['products']['abc123-tadalafil']['gallery'][0]);
        self::assertSame(['downloaded' => 1, 'unchanged' => 0, 'failed' => [], 'orphaned' => []], $result['report']);
        self::assertFileExists($this->mediaDir . '/' . $expectedFilename);
        self::assertSame('binary-bytes', file_get_contents($this->mediaDir . '/' . $expectedFilename));
    }

    public function testJoinsAssetBaseUrlAndRemoteImageHandlingDoubleSlashes(): void
    {
        $fake = new FakeEmrHttpClient(['/images/tadalafil.jpg' => [200, 'bytes']]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test/');

        $localizer->localize(self::catalogWith('abc123-tadalafil', '/images/tadalafil.jpg'));

        self::assertCount(1, $fake->requests);
        self::assertSame(
            'https://assets.example.test/images/tadalafil.jpg',
            (string) $fake->requests[0]->getUri(),
        );
    }

    public function testDefaultsExtensionToPngWhenRemotePathHasNone(): void
    {
        $fake = new FakeEmrHttpClient(['/images/tadalafil' => [200, 'bytes']]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', 'images/tadalafil'));

        self::assertStringEndsWith('.png', $result['catalog']['products']['abc123-tadalafil']['image']);
    }

    public function testSecondRunWithSameBytesIsUnchangedAndDoesNotRewriteFile(): void
    {
        $fake = new FakeEmrHttpClient(['/images/tadalafil.jpg' => [200, 'binary-bytes']]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $localizer->localize(self::catalogWith('abc123-tadalafil', 'images/tadalafil.jpg'));

        $expectedHash = substr(hash('sha256', 'binary-bytes'), 0, 8);
        $filePath = $this->mediaDir . "/abc123-tadalafil.{$expectedHash}.jpg";
        $firstMtime = filemtime($filePath);

        // Ensure a distinguishable mtime would show up if the file were rewritten.
        touch($filePath, $firstMtime - 100);

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', 'images/tadalafil.jpg'));

        self::assertSame(['downloaded' => 0, 'unchanged' => 1, 'failed' => [], 'orphaned' => []], $result['report']);
        self::assertSame($firstMtime - 100, filemtime($filePath));
    }

    public function testNonTwoHundredResponseIsAFailureAndFallsBackToPlaceholderWhenNoExistingFile(): void
    {
        $fake = new FakeEmrHttpClient(['/images/tadalafil.jpg' => [404, 'not found']]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', 'images/tadalafil.jpg'));

        self::assertSame(
            '/assets/img/product-placeholder.svg',
            $result['catalog']['products']['abc123-tadalafil']['image'],
        );
        self::assertSame(
            '/assets/img/product-placeholder.svg',
            $result['catalog']['products']['abc123-tadalafil']['gallery'][0],
        );
        self::assertSame(['downloaded' => 0, 'unchanged' => 0, 'failed' => ['abc123-tadalafil'], 'orphaned' => []], $result['report']);
    }

    public function testClientExceptionIsAFailureAndNeverThrows(): void
    {
        // FakeEmrHttpClient throws a RuntimeException when no route matches the path.
        $fake = new FakeEmrHttpClient([]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', 'images/tadalafil.jpg'));

        self::assertSame(['abc123-tadalafil'], $result['report']['failed']);
        self::assertSame(
            '/assets/img/product-placeholder.svg',
            $result['catalog']['products']['abc123-tadalafil']['image'],
        );
    }

    public function testFailureKeepsPointingAtNewestExistingLocalFileForThatSlug(): void
    {
        mkdir($this->mediaDir, 0775, true);
        $olderPath = $this->mediaDir . '/abc123-tadalafil.aaaaaaaa.jpg';
        $newerPath = $this->mediaDir . '/abc123-tadalafil.bbbbbbbb.jpg';
        file_put_contents($olderPath, 'old');
        file_put_contents($newerPath, 'new');
        touch($olderPath, time() - 1000);
        touch($newerPath, time() - 10);

        $fake = new FakeEmrHttpClient(['/images/tadalafil.jpg' => [500, 'server error']]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', 'images/tadalafil.jpg'));

        self::assertSame(
            '/assets/media/abc123-tadalafil.bbbbbbbb.jpg',
            $result['catalog']['products']['abc123-tadalafil']['image'],
        );
        self::assertSame(['abc123-tadalafil'], $result['report']['failed']);
    }

    public function testProductsWithNullRemoteImageAreUntouchedAndNotCounted(): void
    {
        $fake = new FakeEmrHttpClient([]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', null));

        self::assertSame(
            '/assets/img/product-placeholder.svg',
            $result['catalog']['products']['abc123-tadalafil']['image'],
        );
        self::assertSame([], $fake->requests);
        self::assertSame(['downloaded' => 0, 'unchanged' => 0, 'failed' => [], 'orphaned' => []], $result['report']);
    }

    public function testOrphanedFilesAreReportedButNotDeleted(): void
    {
        mkdir($this->mediaDir, 0775, true);
        file_put_contents($this->mediaDir . '/old-slug-gone.deadbeef.png', 'stale');

        $fake = new FakeEmrHttpClient([]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $result = $localizer->localize(self::catalogWith('abc123-tadalafil', null));

        self::assertSame(['old-slug-gone.deadbeef.png'], $result['report']['orphaned']);
        self::assertFileExists($this->mediaDir . '/old-slug-gone.deadbeef.png');
    }

    public function testMultiProductBatchProcessesAllProductsWithMixedOutcomes(): void
    {
        mkdir($this->mediaDir, 0775, true);

        // Product C already has a local file whose hash matches what the fake
        // will return, so this run should be `unchanged` for C.
        $productCBody = 'unchanged-bytes';
        $productCHash = substr(hash('sha256', $productCBody), 0, 8);
        $productCFilename = "c1-product-c.{$productCHash}.jpg";
        file_put_contents($this->mediaDir . '/' . $productCFilename, $productCBody);

        $fake = new FakeEmrHttpClient([
            '/images/a.png' => [200, 'downloaded-bytes'],
            '/images/c.jpg' => [200, $productCBody],
            // No route for /images/b.jpg: FakeEmrHttpClient throws for any
            // unmatched path, simulating a thrown client exception for B.
        ]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $placeholder = '/assets/img/product-placeholder.svg';
        $catalog = [
            'channel' => ['id' => 'c1', 'name' => 'Flow 1', 'currency' => 'USD'],
            'products' => [
                'a1-product-a' => [
                    'slug' => 'a1-product-a',
                    'name' => 'Product A',
                    'image' => $placeholder,
                    'gallery' => [$placeholder],
                    // Query-stringed remote path: extension must still resolve to 'png'.
                    'remote_image' => 'images/a.png?v=2',
                ],
                'b1-product-b' => [
                    'slug' => 'b1-product-b',
                    'name' => 'Product B',
                    'image' => $placeholder,
                    'gallery' => [$placeholder],
                    'remote_image' => 'images/b.jpg',
                ],
                'c1-product-c' => [
                    'slug' => 'c1-product-c',
                    'name' => 'Product C',
                    'image' => $placeholder,
                    'gallery' => [$placeholder],
                    'remote_image' => 'images/c.jpg',
                ],
            ],
        ];

        $result = $localizer->localize($catalog);

        $aHash = substr(hash('sha256', 'downloaded-bytes'), 0, 8);
        $aFilename = "a1-product-a.{$aHash}.png";

        // B's thrown exception must not have stopped C from being processed.
        self::assertSame(
            ['downloaded' => 1, 'unchanged' => 1, 'failed' => ['b1-product-b'], 'orphaned' => []],
            $result['report'],
        );

        self::assertSame("/assets/media/{$aFilename}", $result['catalog']['products']['a1-product-a']['image']);
        self::assertStringEndsWith('.png', $result['catalog']['products']['a1-product-a']['image']);
        self::assertSame($placeholder, $result['catalog']['products']['b1-product-b']['image']);
        self::assertSame(
            "/assets/media/{$productCFilename}",
            $result['catalog']['products']['c1-product-c']['image'],
        );
        self::assertFileExists($this->mediaDir . '/' . $aFilename);
    }

    public function testCreatesMediaDirectoryWhenMissing(): void
    {
        self::assertDirectoryDoesNotExist($this->mediaDir);

        $fake = new FakeEmrHttpClient([]);
        $localizer = new MediaLocalizer($fake, $this->mediaDir, 'https://assets.example.test');

        $localizer->localize(self::catalogWith('abc123-tadalafil', null));

        self::assertDirectoryExists($this->mediaDir);
    }
}
