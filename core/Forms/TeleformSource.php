<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Storefront\Support\OperatorLog;

/**
 * The one entry point for "give me this product's form".
 *
 * Resolution is an indirection `[10.1]`: the teleform's metadata carries a
 * content identifier, and that identifier resolves to the definition. Both
 * hops are needed on a cache miss and only the first on a hit, because the
 * identifier *is* the cache key — it carries the form's version and publish
 * time, so a republished form misses without any invalidation step `[3.12]`.
 *
 * The degradation rule is `[3.11]`'s: a fetch failure with a warm cache
 * serves the cached copy, and only a miss *and* a failure produces the
 * unavailable state. That ordering is the whole point — a form page that
 * breaks whenever a signed URL or an object store is briefly unavailable is
 * the failure mode the cache exists to remove.
 */
final class TeleformSource
{
    /** @var array<string, TeleformMetadata> resolved metadata, memoised per request so one page render is one read */
    private array $metadata = [];

    public function __construct(
        private readonly TeleformGateway $gateway,
        private readonly DefinitionCache $cache,
        private readonly OperatorLog $log,
    ) {
    }

    public function metadataFor(string $teleformId): ?TeleformMetadata
    {
        if (isset($this->metadata[$teleformId])) {
            return $this->metadata[$teleformId];
        }

        $metadata = $this->gateway->metadata($teleformId);
        if ($metadata !== null) {
            $this->metadata[$teleformId] = $metadata;
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FormUnavailable when the definition is neither cached nor fetchable
     */
    public function definitionFor(string $teleformId): array
    {
        $metadata = $this->metadataFor($teleformId);
        if ($metadata === null) {
            $this->log->warning('intake.teleform_metadata_unavailable', ['teleform' => $teleformId]);

            throw new FormUnavailable(sprintf('No teleform metadata for "%s".', $teleformId));
        }

        $cached = $this->cache->get($metadata->identifier);
        if ($cached !== null) {
            return $cached;
        }

        $definition = $this->gateway->definition($metadata);
        if ($definition === null) {
            $this->log->warning('intake.definition_unavailable', [
                'teleform' => $teleformId,
                'identifier' => $metadata->identifier,
            ]);

            throw new FormUnavailable(sprintf('No definition for teleform "%s".', $teleformId));
        }

        $this->cache->put($metadata->identifier, $definition);

        return $definition;
    }
}
