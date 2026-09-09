<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Seo\StructuredData;

/**
 * The organisation behind the storefront, as schema.org `Organization`
 * (`[24.8]`).
 *
 * Everything it publishes is already public on the site itself — the name in
 * the header and the description in the page metadata — which is the standard
 * this whole namespace is held to: structured data restates what a visitor
 * can already read, it does not assert anything new. Nothing here is invented
 * from a field the catalog does not have, so there is no logo claim: the
 * deployment's social image is a sharing card, not a mark, and publishing one
 * as the other would be a claim about the brand that no configuration made.
 *
 * The `@id` is stable and absolute so {@see WebSite} can name this node as
 * its publisher instead of repeating it, which is the whole reason the graph
 * form exists.
 */
final class Organisation implements Emitter
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $name,
        private readonly string $description,
    ) {
    }

    /** The graph identifier other nodes reference this organisation by. */
    public static function idFor(string $baseUrl): string
    {
        return $baseUrl . '/#organisation';
    }

    public function emit(): ?array
    {
        $node = [
            '@type' => 'Organization',
            '@id' => self::idFor($this->baseUrl),
            'name' => $this->name,
            'url' => $this->baseUrl . '/',
        ];

        if (trim($this->description) !== '') {
            $node['description'] = $this->description;
        }

        return $node;
    }
}
