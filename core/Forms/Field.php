<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * One authored field, as the definition declares it.
 *
 * Plain data is exposed as readonly properties and only genuine derivations
 * are methods, so a reader can tell at a glance which values came from the
 * form and which this class computed.
 *
 * A field may declare **subfields** (`[10.7]`): the composite and each of its
 * parts are independently answerable and independently mappable, which is why
 * subfields are parsed into `Field` objects of their own rather than left as
 * raw arrays. A subfield names itself with `subfieldId` where a top-level
 * field uses `fieldId`; both land in {@see self::$id}.
 */
final class Field
{
    /**
     * @param array<string, mixed> $validation constraints as authored — applied by the validator, never rewritten here
     * @param list<array<string, mixed>> $conditions raw condition groups, evaluated by {@see RuleEvaluator}
     * @param array<string, mixed> $properties presentation and per-type configuration (options, alert text, column span)
     * @param list<self> $subfields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $description,
        public readonly ?string $placeholder,
        public readonly int $order,
        public readonly bool $required,
        public readonly bool $hidden,
        public readonly bool $disabled,
        public readonly bool $readOnly,
        public readonly bool $sendToProvider,
        public readonly array $validation,
        public readonly array $conditions,
        public readonly array $properties,
        public readonly array $subfields,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $subfields = [];
        foreach ((array) ($raw['subfields'] ?? []) as $subfield) {
            if (is_array($subfield)) {
                $subfields[] = self::fromArray($subfield);
            }
        }

        $id = $raw['fieldId'] ?? $raw['subfieldId'] ?? null;
        $name = $raw['name'] ?? $id ?? '';

        return new self(
            id: (string) ($id ?? $name),
            name: (string) $name,
            label: (string) ($raw['label'] ?? ''),
            type: (string) ($raw['type'] ?? ''),
            description: self::text($raw['description'] ?? null),
            placeholder: self::text($raw['placeholder'] ?? null),
            order: (int) ($raw['order'] ?? 0),
            required: ($raw['required'] ?? false) === true,
            hidden: ($raw['hidden'] ?? false) === true,
            disabled: ($raw['disabled'] ?? false) === true,
            readOnly: ($raw['readOnly'] ?? false) === true,
            // Absent means "send it": the flag exists to suppress a field, and
            // a form that never sets it expects every answer to be forwarded.
            sendToProvider: ($raw['sendToProvider'] ?? true) !== false,
            validation: is_array($raw['validation'] ?? null) ? $raw['validation'] : [],
            conditions: array_values(array_filter((array) ($raw['conditions'] ?? []), 'is_array')),
            properties: is_array($raw['properties'] ?? null) ? $raw['properties'] : [],
            subfields: $subfields,
        );
    }

    /**
     * Choice options, normalised out of the two shapes a definition may use
     * (`[10.9]`): a plain string, which is both value and label, or an object
     * carrying them separately.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        $options = [];
        foreach ((array) ($this->properties['options'] ?? []) as $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? $option['label'] ?? '';
                $label = $option['label'] ?? $option['value'] ?? '';
            } else {
                $value = $option;
                $label = $option;
            }

            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }

    public function isDisplayOnly(): bool
    {
        return FieldTypes::isDisplayOnly($this->type);
    }

    public function isMultiValue(): bool
    {
        return FieldTypes::isMultiValue($this->type);
    }

    public function isBoolean(): bool
    {
        return FieldTypes::isBoolean($this->type);
    }

    public function isSupported(): bool
    {
        return FieldTypes::supported($this->type);
    }

    /** The authored column span that drives layout (`[25.7]`), never narrower than one column. */
    public function cols(): int
    {
        return max(1, (int) ($this->properties['cols'] ?? 1));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
