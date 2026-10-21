<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * What one file-upload question will take: how many files, how large, and of
 * which kinds.
 *
 * Two sets of limits meet here and the stricter always wins. The form author
 * sets `maxFiles`, `allowMultipleFiles`, `maxFileSizeMb` and, optionally,
 * `fileTypes` (a comma-separated list of extensions); the EMR's upload
 * endpoints set the kinds of file they store and the largest file they take.
 * The browser checks the same rules before it sends a byte, so a refusal from
 * here is a request that went round the page.
 *
 * **The kind of a file is read from its extension**, not from the type the
 * browser reports: browsers report HEIC as an empty string and some report a
 * `.mov` as `video/quicktime` and others not at all. The extension maps to one
 * MIME type, and that is the type the EMR is told.
 *
 * **Which endpoint a file takes** follows `docs/INTEGRATION-NOTES.md` item 30:
 * the single-request endpoint refuses every video however small, and the
 * multipart one takes documents and images as well as video, so a video or
 * anything over {@see self::SINGLE_REQUEST_MAX_BYTES} goes in parts.
 */
final class FileRules
{
    /**
     * The most one request to the EMR may carry. Its cap is 10 MiB measured
     * on the whole multipart envelope, boundaries and headers included, so a
     * file of exactly 10 MiB is refused; 64 KiB is left for the envelope.
     * Also the size of every part of a multipart upload.
     */
    public const int SINGLE_REQUEST_MAX_BYTES = 10 * 1024 * 1024 - 64 * 1024;

    /** The smallest part the multipart flow accepts, except the last one. */
    public const int MIN_PART_BYTES = 5 * 1024 * 1024;

    /** The EMR's cap on one file, checked when a multipart upload starts. */
    public const int MAX_FILE_BYTES = 200 * 1024 * 1024;

    /** What a question that states no size of its own takes, as the hosted form does. */
    private const int DEFAULT_MAX_MB = 10;

    /**
     * Every extension the EMR stores, and the MIME type it is sent as.
     *
     * HEIC and HEIF are here although the EMR does not take them yet: phones
     * take photos in them, and a refusal from the EMR names the type, which is
     * a better answer for a patient than a picker that will not open the file.
     */
    public const array EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];

    /**
     * @param ?int         $maxFiles   null when the question takes any number
     * @param list<string> $extensions lower-case, without the dot
     */
    private function __construct(
        public readonly ?int $maxFiles,
        public readonly int $maxBytes,
        public readonly array $extensions,
        public readonly bool $dragDrop,
    ) {
    }

    /**
     * Read the way the hosted form reads them: several files only when
     * `allowMultipleFiles` says so, or, on a form older than that flag, when
     * `maxFiles` is above one; and then as many as `maxFiles` allows, or any
     * number when it is not set above one.
     */
    public static function for(Field $field): self
    {
        $properties = $field->properties;
        $maxFiles = is_numeric($properties['maxFiles'] ?? null) ? (int) $properties['maxFiles'] : null;
        $multiple = is_bool($properties['allowMultipleFiles'] ?? null)
            ? $properties['allowMultipleFiles']
            : ($maxFiles ?? 1) > 1;

        $megabytes = is_numeric($properties['maxFileSizeMb'] ?? null) && (float) $properties['maxFileSizeMb'] > 0
            ? (float) $properties['maxFileSizeMb']
            : self::DEFAULT_MAX_MB;

        return new self(
            maxFiles: $multiple ? ($maxFiles !== null && $maxFiles > 1 ? $maxFiles : null) : 1,
            maxBytes: (int) min(self::MAX_FILE_BYTES, floor($megabytes * 1024 * 1024)),
            extensions: self::extensionsFrom($properties['fileTypes'] ?? null),
            dragDrop: ($properties['dragDrop'] ?? true) !== false,
        );
    }

    /** The MIME type a file of this name is sent as, or null when the question does not take it. */
    public function mimeTypeFor(string $fileName): ?string
    {
        $extension = self::extensionOf($fileName);

        return in_array($extension, $this->extensions, true) ? self::EXTENSIONS[$extension] : null;
    }

    /**
     * Why this file cannot be attached, in words for the patient, or null
     * when it can.
     */
    public function refusal(string $fileName, int $bytes): ?string
    {
        if ($this->mimeTypeFor($fileName) === null) {
            return sprintf('%s is not a file type this question accepts.', self::displayName($fileName));
        }

        if ($bytes <= 0) {
            return sprintf('%s is empty.', self::displayName($fileName));
        }

        if ($bytes > $this->maxBytes) {
            return sprintf('%s is larger than %s.', self::displayName($fileName), self::megabytes($this->maxBytes));
        }

        return null;
    }

    /** Whether a file goes up in parts rather than in one request. */
    public static function needsParts(string $mimeType, int $bytes): bool
    {
        return str_starts_with($mimeType, 'video/') || $bytes > self::SINGLE_REQUEST_MAX_BYTES;
    }

    /**
     * The most one request to this server can carry, which bounds every
     * request the page sends: the EMR's own cap, or less where PHP's
     * `upload_max_filesize` or `post_max_size` is set lower. A part has to be
     * at least {@see self::MIN_PART_BYTES} unless it is the only one, so a
     * server set below that can take a large file only in one request, and
     * the page refuses one too large for that.
     */
    public static function requestBytes(): int
    {
        $limits = [self::SINGLE_REQUEST_MAX_BYTES];

        foreach (['upload_max_filesize' => 0, 'post_max_size' => 64 * 1024] as $setting => $envelope) {
            $bytes = self::iniBytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limits[] = $bytes - $envelope;
            }
        }

        return max(0, min($limits));
    }

    /** The `accept` attribute for the file picker. */
    public function accept(): string
    {
        $accepted = [];
        foreach ($this->extensions as $extension) {
            $accepted[] = '.' . $extension;
        }

        return implode(',', $accepted);
    }

    public static function megabytes(int $bytes): string
    {
        $value = $bytes / (1024 * 1024);

        return (fmod((float) $value, 1.0) === 0.0 ? (string) (int) $value : number_format((float) $value, 1)) . ' MB';
    }

    /**
     * The author's list where there is one, kept to what the EMR stores: an
     * extension it refuses would let a patient wait out an upload only to be
     * told no at the end.
     *
     * @return list<string>
     */
    private static function extensionsFrom(mixed $authored): array
    {
        $known = array_keys(self::EXTENSIONS);
        if (!is_string($authored) || trim($authored) === '') {
            return $known;
        }

        $chosen = [];
        foreach (explode(',', strtolower($authored)) as $entry) {
            $extension = ltrim(trim($entry), '.');
            if (in_array($extension, $known, true) && !in_array($extension, $chosen, true)) {
                $chosen[] = $extension;
            }
        }

        return $chosen === [] ? $known : $chosen;
    }

    /** A php.ini size (`8M`, `2G`, `512K`, or bytes) in bytes; zero for unset or unlimited. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !is_numeric(rtrim($value, 'kKmMgG'))) {
            return 0;
        }

        $number = (float) rtrim($value, 'kKmMgG');

        return (int) match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function extensionOf(string $fileName): string
    {
        $dot = strrpos($fileName, '.');

        return $dot === false ? '' : strtolower(substr($fileName, $dot + 1));
    }

    private static function displayName(string $fileName): string
    {
        $name = trim($fileName);

        return $name === '' ? 'This file' : $name;
    }
}
