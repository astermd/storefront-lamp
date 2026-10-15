<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Http\FileUpload;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see IntakeUploads} over the SDK's `intakeSubmissions()` upload calls.
 *
 * **A 400 is a refusal of the file**, and every other failure is a failure.
 * The endpoints answer 400 for a type they do not store, a file over their
 * cap and a part they cannot assemble (`docs/INTEGRATION-NOTES.md` item 30),
 * which are all things the patient fixes by choosing another file.
 *
 * What a failure writes to the log follows {@see EmrIntakeGateway}: the
 * exception class and status, never its message, since a provider message
 * may echo what it was sent.
 */
final class EmrIntakeUploads implements IntakeUploads
{
    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function upload(string $session, string $localPath, string $fileName, string $mimeType): UploadOutcome
    {
        try {
            $data = $this->client()->intakeSubmissions()
                ->uploadFile($session, FileUpload::fromPath($localPath, $fileName, $mimeType))
                ->data();
        } catch (\Throwable $e) {
            return $this->unsuccessful('upload', $e);
        }

        return $this->stored('upload', $data);
    }

    public function start(string $session, string $fileName, string $mimeType, int $bytes): UploadOutcome
    {
        try {
            $data = $this->client()->intakeSubmissions()
                ->initiateMultipartUpload($session, $fileName, $mimeType, $bytes)
                ->data();
        } catch (\Throwable $e) {
            return $this->unsuccessful('start', $e);
        }

        $uploadId = is_array($data) ? ($data['upload_id'] ?? null) : null;

        return is_string($uploadId) && $uploadId !== ''
            ? UploadOutcome::started($uploadId)
            : $this->malformed('start');
    }

    public function part(string $uploadId, int $partNumber, string $localPath, string $fileName, string $mimeType): UploadOutcome
    {
        try {
            $data = $this->client()->intakeSubmissions()
                ->uploadMultipartPart($uploadId, $partNumber, FileUpload::fromPath($localPath, $fileName, $mimeType))
                ->data();
        } catch (\Throwable $e) {
            return $this->unsuccessful('part', $e);
        }

        $etag = is_array($data) ? ($data['etag'] ?? null) : null;

        // The ETag goes back to `finish` exactly as it came, quotes and all.
        return is_string($etag) && $etag !== ''
            ? UploadOutcome::partStored((int) ($data['part_number'] ?? $partNumber), $etag)
            : $this->malformed('part');
    }

    public function finish(string $uploadId, array $parts): UploadOutcome
    {
        usort($parts, static fn (array $a, array $b): int => $a['part_number'] <=> $b['part_number']);

        try {
            $data = $this->client()->intakeSubmissions()->finishMultipartUpload($uploadId, $parts)->data();
        } catch (\Throwable $e) {
            return $this->unsuccessful('finish', $e);
        }

        return $this->stored('finish', $data);
    }

    public function abort(string $uploadId): void
    {
        try {
            $this->client()->intakeSubmissions()->abortMultipartUpload($uploadId);
        } catch (\Throwable $e) {
            $this->unsuccessful('abort', $e);
        }
    }

    private function stored(string $call, mixed $data): UploadOutcome
    {
        $file = FileReference::fromArray($data);

        return $file === null ? $this->malformed($call) : UploadOutcome::stored($file);
    }

    private function unsuccessful(string $call, \Throwable $e): UploadOutcome
    {
        $this->log->warning('intake.upload_failed', [
            'call' => $call,
            'exception' => $e::class,
            'status' => $e->getCode(),
        ]);

        return $e->getCode() === 400 ? UploadOutcome::refused() : UploadOutcome::failed();
    }

    private function malformed(string $call): UploadOutcome
    {
        $this->log->warning('intake.upload_unreadable', ['call' => $call]);

        return UploadOutcome::failed();
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
