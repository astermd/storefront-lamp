<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * What one call to the EMR's upload endpoints came back with.
 *
 * A refusal and a failure are kept apart because they ask different things of
 * the patient. A refusal is the EMR saying no to this file -- its type, its
 * size -- and trying again will not help, so the page says to choose another.
 * A failure is the call not completing, and trying again might.
 */
final class UploadOutcome
{
    /** @param ?array{part_number: int, etag: string} $part */
    private function __construct(
        public readonly bool $ok,
        public readonly ?FileReference $file = null,
        public readonly ?string $uploadId = null,
        public readonly ?array $part = null,
        public readonly bool $refused = false,
    ) {
    }

    public static function stored(FileReference $file): self
    {
        return new self(true, file: $file);
    }

    public static function started(string $uploadId): self
    {
        return new self(true, uploadId: $uploadId);
    }

    public static function partStored(int $partNumber, string $etag): self
    {
        return new self(true, part: ['part_number' => $partNumber, 'etag' => $etag]);
    }

    public static function refused(): self
    {
        return new self(false, refused: true);
    }

    public static function failed(): self
    {
        return new self(false);
    }
}
