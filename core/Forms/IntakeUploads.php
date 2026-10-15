<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The EMR's file storage for intake answers: a file goes up on its own, before
 * the submission that names it, and comes back as a {@see FileReference} the
 * answer carries.
 *
 * Two routes, because the EMR has two. A small document or image is one
 * request; a video, or anything larger than one request may carry, is started,
 * sent in numbered parts and finished, and a started upload that will not be
 * finished is aborted so the EMR drops what it holds. {@see FileRules} decides
 * which route a file takes.
 *
 * The bytes are PHI. An implementation logs that a call failed and how, never
 * what was in it.
 */
interface IntakeUploads
{
    public function upload(string $session, string $localPath, string $fileName, string $mimeType): UploadOutcome;

    public function start(string $session, string $fileName, string $mimeType, int $bytes): UploadOutcome;

    public function part(string $uploadId, int $partNumber, string $localPath, string $fileName, string $mimeType): UploadOutcome;

    /** @param list<array{part_number: int, etag: string}> $parts */
    public function finish(string $uploadId, array $parts): UploadOutcome;

    /** Best effort: the upload is already being given up on, and nothing waits on this. */
    public function abort(string $uploadId): void;
}
