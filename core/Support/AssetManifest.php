<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

/**
 * Maps a logical asset name (e.g. `script.js`) to its content-hashed build
 * output (e.g. `script.8d796604.js`) via the build's manifest.json, so
 * templates never hardcode a hash that changes on every rebuild. The
 * manifest is read once and cached in-process for the life of the request.
 *
 * If the manifest is missing or unreadable, or has no entry for the
 * requested name, `path()` falls back to the logical name itself rather
 * than throwing — templates keep rendering (with an unhashed, possibly
 * uncached asset URL) instead of a fresh checkout or a build hiccup taking
 * the whole page down.
 */
final class AssetManifest
{
    /** @var array<string, string>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $manifestPath)
    {
    }

    public function path(string $logicalName): string
    {
        if ($this->entries === null) {
            $raw = is_readable($this->manifestPath) ? file_get_contents($this->manifestPath) : false;
            $decoded = $raw === false ? null : json_decode($raw, true);
            $this->entries = is_array($decoded) ? $decoded : [];
        }

        return '/assets/build/' . ($this->entries[$logicalName] ?? $logicalName);
    }
}
