<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * One uploaded file as the EMR named it: the `{path, name, mime_type}` its
 * upload endpoints answer, which is exactly what a file-upload answer carries
 * into the intake submission.
 *
 * **A reference is accepted only under the visitor's own session.** The EMR
 * files every upload at `{account}/{org}/intake_submissions/{session}/{name}`,
 * and the answer comes back from the browser, so without this check a visitor
 * could attach another patient's stored file to their own submission by
 * posting its path. {@see self::belongsTo()} is what every posted reference
 * is held to before it is stored.
 */
final class FileReference
{
    private const string PATH_SHAPE = '#^[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/intake_submissions/([0-9A-Fa-f-]{36})/[^/]+$#';

    private function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $mimeType,
    ) {
    }

    /**
     * From the EMR's answer, or from an answer already stored. Null for
     * anything else.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $path = $raw['path'] ?? null;
        $name = $raw['name'] ?? null;
        $mimeType = $raw['mime_type'] ?? null;

        if (!is_string($path) || !is_string($name) || !is_string($mimeType)
            || preg_match(self::PATH_SHAPE, $path) !== 1 || trim($name) === '') {
            return null;
        }

        return new self($path, $name, $mimeType);
    }

    /** From the JSON a page posts for each attached file. */
    public static function fromPosted(mixed $posted): ?self
    {
        if (is_array($posted)) {
            return self::fromArray($posted);
        }

        if (!is_string($posted) || $posted === '' || strlen($posted) > 2048) {
            return null;
        }

        return self::fromArray(json_decode($posted, true));
    }

    public function belongsTo(string $session): bool
    {
        return preg_match(self::PATH_SHAPE, $this->path, $match) === 1
            && strtolower($match[1]) === strtolower($session);
    }

    /** @return array{path: string, name: string, mime_type: string} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'name' => $this->name, 'mime_type' => $this->mimeType];
    }
}
