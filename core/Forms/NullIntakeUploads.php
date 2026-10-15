<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * Bound when EMR analytics are off, which is also the test suite.
 *
 * A file answer reaches the clinician only inside the intake submission, and
 * with analytics off no submission is sent, so an upload here would store a
 * file nothing ever names. Every call fails instead, and the page says the
 * file could not be uploaded -- unlike {@see NullIntakeGateway}, which reports
 * success, because a patient told their file was attached would believe the
 * clinician can see it.
 */
final class NullIntakeUploads implements IntakeUploads
{
    public function upload(string $session, string $localPath, string $fileName, string $mimeType): UploadOutcome
    {
        return UploadOutcome::failed();
    }

    public function start(string $session, string $fileName, string $mimeType, int $bytes): UploadOutcome
    {
        return UploadOutcome::failed();
    }

    public function part(string $uploadId, int $partNumber, string $localPath, string $fileName, string $mimeType): UploadOutcome
    {
        return UploadOutcome::failed();
    }

    public function finish(string $uploadId, array $parts): UploadOutcome
    {
        return UploadOutcome::failed();
    }

    public function abort(string $uploadId): void
    {
    }
}
