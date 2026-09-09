<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The teleform record, reduced to the four things the storefront needs.
 *
 * `dbFields` is the answer-to-record mapping `[11.1]` — authored by whoever
 * authored the form, so the storefront hardcodes no question-to-field
 * mapping. It is taken from here rather than from each field's own `db_field`
 * because only this map reaches a composite's subfields: a BMI field's height
 * and weight have targets (`opportunity.clinical.height.value`) that the
 * parent field's single declaration cannot express.
 */
final class TeleformMetadata
{
    /** @param array<string, string> $dbFields answer field name => dotted target path on the EMR record */
    public function __construct(
        public readonly string $id,
        public readonly string $identifier,
        public readonly string $type,
        public readonly string $layout,
        public readonly array $dbFields,
    ) {
    }

    /** @param array<string, mixed> $payload the `teleforms()->view()` data block */
    public static function fromPayload(string $teleformId, array $payload): ?self
    {
        $identifier = $payload['form_json_identifier'] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        $dbFields = [];
        foreach ((array) ($payload['db_fields'] ?? []) as $name => $target) {
            if (is_string($name) && is_string($target) && $target !== '') {
                $dbFields[$name] = $target;
            }
        }

        return new self(
            id: $teleformId,
            identifier: $identifier,
            type: is_string($payload['type'] ?? null) ? $payload['type'] : 'intake',
            layout: is_string($payload['layout'] ?? null) ? $payload['layout'] : 'single-column',
            dbFields: $dbFields,
        );
    }
}
