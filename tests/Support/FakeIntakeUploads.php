<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Forms\FileReference;
use AsterMD\Storefront\Forms\IntakeUploads;
use AsterMD\Storefront\Forms\UploadOutcome;

/**
 * In-memory {@see IntakeUploads}: answers the way the EMR does, filing every
 * file under the session it was sent for, and records each call so a test can
 * assert what reached the EMR -- and, as often, that nothing did.
 *
 * `$refuse` makes every call answer as the EMR answers a type it does not
 * store.
 */
final class FakeIntakeUploads implements IntakeUploads
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public bool $refuse = false;

    /** @var array<string, string> upload id => session */
    private array $sessions = [];

    public function upload(string $session, string $localPath, string $fileName, string $mimeType): UploadOutcome
    {
        $this->calls[] = ['call' => 'upload', 'session' => $session, 'name' => $fileName, 'mime_type' => $mimeType, 'bytes' => filesize($localPath)];

        return $this->refuse ? UploadOutcome::refused() : UploadOutcome::stored(self::stored($session, $fileName, $mimeType));
    }

    public function start(string $session, string $fileName, string $mimeType, int $bytes): UploadOutcome
    {
        $this->calls[] = ['call' => 'start', 'session' => $session, 'name' => $fileName, 'mime_type' => $mimeType, 'bytes' => $bytes];
        if ($this->refuse) {
            return UploadOutcome::refused();
        }

        $uploadId = sprintf('a1b2c3d4-0000-4000-8000-%012d', count($this->sessions) + 1);
        $this->sessions[$uploadId] = $session;

        return UploadOutcome::started($uploadId);
    }

    public function part(string $uploadId, int $partNumber, string $localPath, string $fileName, string $mimeType): UploadOutcome
    {
        $this->calls[] = ['call' => 'part', 'upload_id' => $uploadId, 'part_number' => $partNumber, 'bytes' => filesize($localPath)];

        return UploadOutcome::partStored($partNumber, '"etag-' . $partNumber . '"');
    }

    public function finish(string $uploadId, array $parts): UploadOutcome
    {
        $this->calls[] = ['call' => 'finish', 'upload_id' => $uploadId, 'parts' => $parts];

        return UploadOutcome::stored(self::stored($this->sessions[$uploadId] ?? 'unknown', 'clip.mp4', 'video/mp4'));
    }

    public function abort(string $uploadId): void
    {
        $this->calls[] = ['call' => 'abort', 'upload_id' => $uploadId];
    }

    /** @return list<string> */
    public function callNames(): array
    {
        return array_map(static fn (array $call): string => (string) $call['call'], $this->calls);
    }

    private static function stored(string $session, string $fileName, string $mimeType): FileReference
    {
        $dot = strrpos($fileName, '.');
        $name = ($dot === false ? $fileName : substr($fileName, 0, $dot))
            . '-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44'
            . ($dot === false ? '' : substr($fileName, $dot));

        return FileReference::fromArray([
            'path' => sprintf('acct1/org1/intake_submissions/%s/%s', $session, $name),
            'name' => $name,
            'mime_type' => $mimeType,
        ]) ?? throw new \LogicException('the fake built a reference FileReference refuses');
    }
}
