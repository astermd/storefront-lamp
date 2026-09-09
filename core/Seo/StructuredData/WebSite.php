<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo\StructuredData;

/**
 * The storefront itself, as schema.org `WebSite` with a search action
 * (`[24.8]`).
 *
 * The search action is conditional on a target being supplied. A
 * `SearchAction` is a promise that a query submitted to the named URL returns
 * results for it, so a deployment whose listing endpoint does not read a
 * query parameter can suppress the action entirely by naming no target,
 * rather than publishing a promise it does not keep.
 */
final class WebSite implements Emitter
{
    /** schema.org's placeholder for the submitted query, substituted by the consumer. */
    public const string QUERY_PLACEHOLDER = '{search_term_string}';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $name,
        private readonly ?string $searchUrlTemplate = null,
        private readonly bool $hasPublisher = false,
    ) {
    }

    /** The graph identifier other nodes reference this site by. */
    public static function idFor(string $baseUrl): string
    {
        return $baseUrl . '/#website';
    }

    public function emit(): ?array
    {
        $node = [
            '@type' => 'WebSite',
            '@id' => self::idFor($this->baseUrl),
            'name' => $this->name,
            'url' => $this->baseUrl . '/',
        ];

        // Only when the organisation node is actually in the graph: a
        // reference to an `@id` nothing defines is a dangling pointer, and a
        // consumer that resolves it finds an entity with no properties.
        if ($this->hasPublisher) {
            $node['publisher'] = ['@id' => Organisation::idFor($this->baseUrl)];
        }

        $target = $this->searchTarget();
        if ($target !== null) {
            $node['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $target,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $node;
    }

    /**
     * The absolute search URL template, or null when the deployment named
     * none or named one with no place to put the query.
     */
    private function searchTarget(): ?string
    {
        $template = trim((string) $this->searchUrlTemplate);
        if ($template === '' || !str_contains($template, self::QUERY_PLACEHOLDER)) {
            return null;
        }

        if (str_starts_with($template, 'http://') || str_starts_with($template, 'https://')) {
            return $template;
        }

        return $this->baseUrl . '/' . ltrim($template, '/');
    }
}
