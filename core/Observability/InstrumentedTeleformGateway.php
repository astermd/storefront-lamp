<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Observability;

use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;

/**
 * `[20.9]` over the form-definition boundary, in both halves.
 *
 * This is the boundary `[20.11]` uses as its worked example: a form definition
 * that silently stops loading leaves a healthy server and zero intake
 * completions, and infrastructure metrics show nothing at all. The two halves
 * are timed separately because they fail separately — the metadata is a cheap
 * authenticated read and the definition is a large document behind a
 * short-lived signed URL, so a rising failure rate on one says something quite
 * different from the other.
 *
 * The definition itself is never logged. It is the questionnaire, and its
 * field labels are clinical.
 */
final class InstrumentedTeleformGateway implements TeleformGateway
{
    public function __construct(
        private readonly TeleformGateway $inner,
        private readonly BoundaryTimer $timer,
    ) {
    }

    public function metadata(string $teleformId): ?TeleformMetadata
    {
        return $this->timer->measure(
            Boundary::FormDefinition,
            fn (): ?TeleformMetadata => $this->inner->metadata($teleformId),
            static fn (?TeleformMetadata $metadata): array => ['outcome' => $metadata === null ? 'failed' : 'ok'],
            ['operation' => 'metadata', 'teleform' => $teleformId],
        );
    }

    public function definition(TeleformMetadata $metadata): ?array
    {
        return $this->timer->measure(
            Boundary::FormDefinition,
            fn (): ?array => $this->inner->definition($metadata),
            static fn (?array $definition): array => ['outcome' => $definition === null ? 'failed' : 'ok'],
            ['operation' => 'definition', 'teleform' => $metadata->id],
        );
    }
}
