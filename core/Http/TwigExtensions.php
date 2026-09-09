<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http;

use AsterMD\Storefront\Support\AssetManifest;
use AsterMD\Storefront\Support\Url;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The storefront's Twig functions/filters: `asset()` (manifest-resolved
 * static asset URLs), `url()` (canonicalised, query-aware route URLs), and
 * `money` (integer cents formatted as a display price).
 */
final class TwigExtensions extends AbstractExtension
{
    public function __construct(private readonly AssetManifest $assets)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset', fn (string $name): string => $this->assets->path($name)),
            new TwigFunction('url', fn (string $path, array $query = []): string => Url::to($path, $query)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', fn (int $cents): string => '$' . number_format($cents / 100, 2)),
        ];
    }
}
