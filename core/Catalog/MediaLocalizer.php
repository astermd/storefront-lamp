<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Catalog;

use Psr\Http\Client\ClientInterface;
use Slim\Psr7\Factory\RequestFactory;

/**
 * Downloads each product's `remote_image` into `public/assets/media/`
 * (content-hashed filenames so a re-run only re-downloads what actually
 * changed [3.10]) and rewrites the product's `image`/`gallery[0]` to point at
 * the local copy. SDK-free by design — callers resolve `$assetBaseUrl`
 * themselves (e.g. via the SDK's `assetUrl()`) so this class only needs a
 * PSR-18 transport, never the AsterMD SDK.
 *
 * A single image failure (non-2xx response or a thrown client exception)
 * never aborts the run [3.3]: the affected product falls back to its newest
 * already-downloaded local file, or the shared placeholder SVG when it has
 * none, and its slug is recorded in the report's `failed` list.
 */
final class MediaLocalizer
{
    private const string FALLBACK_PLACEHOLDER = '/assets/img/product-placeholder.svg';

    private const string DEFAULT_EXTENSION = 'png';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $mediaDir,
        private readonly string $assetBaseUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $catalog generated-catalog shape (`channel` + `products`)
     * @return array{
     *     catalog: array<string, mixed>,
     *     report: array{downloaded: int, unchanged: int, failed: list<string>, orphaned: list<string>},
     * }
     */
    public function localize(array $catalog): array
    {
        $this->ensureMediaDir();

        $products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];

        $downloaded = 0;
        $unchanged = 0;
        $failed = [];
        $catalogSlugs = [];

        foreach ($products as $key => $product) {
            if (!is_array($product)) {
                continue;
            }

            $slug = (string) ($product['slug'] ?? $key);
            $catalogSlugs[] = $slug;

            $remoteImage = $product['remote_image'] ?? null;
            if ($remoteImage === null) {
                continue;
            }

            $outcome = $this->localizeOne($slug, (string) $remoteImage);

            match ($outcome['status']) {
                'downloaded' => $downloaded++,
                'unchanged' => $unchanged++,
                default => $failed[] = $slug,
            };

            $gallery = is_array($product['gallery'] ?? null) ? $product['gallery'] : [];
            $gallery[0] = $outcome['path'];

            $product['image'] = $outcome['path'];
            $product['gallery'] = $gallery;
            $products[$key] = $product;
        }

        $catalog['products'] = $products;

        return [
            'catalog' => $catalog,
            'report' => [
                'downloaded' => $downloaded,
                'unchanged' => $unchanged,
                'failed' => $failed,
                'orphaned' => self::findOrphans($this->mediaDir, $catalogSlugs),
            ],
        ];
    }

    /**
     * Files in `$mediaDir` whose slug prefix (the portion of the filename
     * before its first `.`) matches none of `$catalogSlugs` — shared between
     * {@see self::localize()} (report-only) and `media:prune` (report +
     * optional delete), so both agree on exactly what counts as an orphan.
     *
     * @param array<int, string> $catalogSlugs
     * @return list<string> orphaned file basenames, sorted
     */
    public static function findOrphans(string $mediaDir, array $catalogSlugs): array
    {
        if (!is_dir($mediaDir)) {
            return [];
        }

        $slugSet = array_flip($catalogSlugs);
        $orphans = [];

        foreach (glob($mediaDir . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $basename = basename($file);
            $slug = strstr($basename, '.', true);
            $slug = $slug === false ? $basename : $slug;

            if (!isset($slugSet[$slug])) {
                $orphans[] = $basename;
            }
        }

        sort($orphans);

        return $orphans;
    }

    /** @return array{status: 'downloaded'|'unchanged'|'failed', path: string} */
    private function localizeOne(string $slug, string $remoteImage): array
    {
        $existingFiles = glob($this->mediaDir . '/' . $slug . '.*') ?: [];

        try {
            $request = (new RequestFactory())->createRequest('GET', $this->joinUrl($remoteImage));
            $response = $this->http->sendRequest($request);
        } catch (\Throwable) {
            return $this->fallback($existingFiles);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $this->fallback($existingFiles);
        }

        $body = (string) $response->getBody();
        $hash = substr(hash('sha256', $body), 0, 8);
        $filename = sprintf('%s.%s.%s', $slug, $hash, self::extensionOf($remoteImage));
        $path = $this->mediaDir . '/' . $filename;

        if (is_file($path)) {
            return ['status' => 'unchanged', 'path' => '/assets/media/' . $filename];
        }

        file_put_contents($path, $body);

        return ['status' => 'downloaded', 'path' => '/assets/media/' . $filename];
    }

    /**
     * @param list<string> $existingFiles absolute paths already on disk for this product's slug
     * @return array{status: 'failed', path: string}
     */
    private function fallback(array $existingFiles): array
    {
        if ($existingFiles === []) {
            return ['status' => 'failed', 'path' => self::FALLBACK_PLACEHOLDER];
        }

        usort($existingFiles, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return ['status' => 'failed', 'path' => '/assets/media/' . basename($existingFiles[0])];
    }

    private function joinUrl(string $remoteImage): string
    {
        return rtrim($this->assetBaseUrl, '/') . '/' . ltrim($remoteImage, '/');
    }

    private static function extensionOf(string $remoteImage): string
    {
        $path = parse_url($remoteImage, PHP_URL_PATH);
        $extension = pathinfo(is_string($path) ? $path : $remoteImage, PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : self::DEFAULT_EXTENSION;
    }

    private function ensureMediaDir(): void
    {
        if (!is_dir($this->mediaDir) && !mkdir($this->mediaDir, 0775, true) && !is_dir($this->mediaDir)) {
            throw new \RuntimeException(sprintf('Unable to create media directory: %s', $this->mediaDir));
        }
    }
}
