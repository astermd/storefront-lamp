<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * One page of a definition, which is one step of the form (`[10.5]`).
 *
 * Only top-level fields are listed here; a composite's subfields hang off
 * their parent {@see Field}. {@see Definition::fields()} is the flattened view
 * for anything that needs every answerable field at once.
 */
final class Page
{
    /** @param list<Field> $fields */
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly int $order,
        public readonly array $fields,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $fields = [];
        foreach ((array) ($raw['fields'] ?? []) as $field) {
            if (is_array($field)) {
                $fields[] = Field::fromArray($field);
            }
        }

        return new self(
            id: (string) ($raw['pageId'] ?? ''),
            title: is_string($raw['title'] ?? null) && $raw['title'] !== '' ? $raw['title'] : null,
            description: is_string($raw['description'] ?? null) && $raw['description'] !== '' ? $raw['description'] : null,
            order: (int) ($raw['order'] ?? 0),
            fields: $fields,
        );
    }
}
