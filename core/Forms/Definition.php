<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * A parsed teleform definition: the pages that become steps, the settings the
 * form author chose, and two lookup tables over every field it declares.
 *
 * The lookups are built over a **flattened** field list that includes
 * subfields, because a composite's parts are independently answerable and
 * independently mappable (`[10.7]`). A subfield that could not be found by
 * name would be a subfield that never receives its `db_fields` target — the
 * BMI composite's height and weight are exactly that case.
 *
 * Pages are ordered by their authored `order` rather than by array position,
 * since the authoring UI reorders by rewriting that number and leaves the
 * array alone.
 */
final class Definition
{
    /** @var array<string, Field> */
    private readonly array $byName;

    /** @var array<string, Field> */
    private readonly array $byId;

    /** @var list<Field> */
    private readonly array $flattened;

    /**
     * @param array<string, mixed> $settings
     * @param list<Page> $pages
     */
    private function __construct(
        private readonly array $settings,
        private readonly array $pages,
    ) {
        $byName = [];
        $byId = [];
        $flattened = [];

        foreach ($pages as $page) {
            foreach ($page->fields as $field) {
                foreach (self::withSubfields($field) as $entry) {
                    $flattened[] = $entry;
                    // First occurrence wins: a repeated name is an authoring
                    // error, and the field the renderer draws first is the one
                    // whose answer the visitor will have supplied.
                    $byName[$entry->name] ??= $entry;
                    $byId[$entry->id] ??= $entry;
                }
            }
        }

        $this->byName = $byName;
        $this->byId = $byId;
        $this->flattened = $flattened;
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $pages = [];
        foreach ((array) ($raw['pages'] ?? []) as $page) {
            if (is_array($page)) {
                $pages[] = Page::fromArray($page);
            }
        }

        usort($pages, static fn (Page $a, Page $b): int => $a->order <=> $b->order);

        return new self(
            settings: is_array($raw['settings'] ?? null) ? $raw['settings'] : [],
            pages: $pages,
        );
    }

    /** @return list<Page> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Every declared field, subfields included, in page-then-authored order. @return list<Field> */
    public function fields(): array
    {
        return $this->flattened;
    }

    public function field(string $name): ?Field
    {
        return $this->byName[$name] ?? null;
    }

    public function fieldById(string $fieldId): ?Field
    {
        return $this->byId[$fieldId] ?? null;
    }

    /**
     * The distinct types this renderer does not implement, in the order they
     * are first met — so the message an operator reads names the field they
     * will find first when they open the form (`[10.13]`).
     *
     * @return list<string>
     */
    public function unsupportedTypes(): array
    {
        $unsupported = [];
        foreach ($this->flattened as $field) {
            if (!$field->isSupported() && !in_array($field->type, $unsupported, true)) {
                $unsupported[] = $field->type;
            }
        }

        return $unsupported;
    }

    /** Reads a setting by dotted path, the way {@see \AsterMD\Storefront\Support\Config::get()} reads config. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $node = $this->settings;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /** @return list<Field> */
    private static function withSubfields(Field $field): array
    {
        $fields = [$field];
        foreach ($field->subfields as $subfield) {
            foreach (self::withSubfields($subfield) as $nested) {
                $fields[] = $nested;
            }
        }

        return $fields;
    }
}
