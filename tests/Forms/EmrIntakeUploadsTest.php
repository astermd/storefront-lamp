<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Forms;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Forms\EmrIntakeUploads;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The upload adapter against the EMR's recorded answers
 * (`docs/INTEGRATION-NOTES.md` item 30): a 400 is a refusal of the file, any
 * other failure is a failure, and what comes back is a reference only when it
 * is shaped like one.
 */
final class EmrIntakeUploadsTest extends TestCase
{
    private const TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    private const string SESSION = '550e8400-e29b-41d4-a716-446655440000';

    private string $dir;
    private string $rootDir;
    private string $logFile;
    private string $file;

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';

        $this->dir = sys_get_temp_dir() . '/emr-intake-uploads-' . uniqid();
        mkdir($this->dir);
        $this->rootDir = sys_get_temp_dir() . '/emr-intake-uploads-root-' . uniqid();
        mkdir($this->rootDir);
        $this->logFile = sys_get_temp_dir() . '/emr-intake-uploads-log-' . uniqid() . '.log';
        $this->file = (string) tempnam(sys_get_temp_dir(), 'emr-upload-');
        file_put_contents($this->file, 'patient lab results');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        foreach ([$this->logFile, $this->file] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAStoredFileComesBackAsTheReferenceTheAnswerCarries(): void
    {
        $path = '64b2f0c7e3a1d2b3c4e5f6a8/64b2f0c7e3a1d2b3c4e5f6a9/intake_submissions/' . self::SESSION . '/labs-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.pdf';
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/intake-submissions/upload-file/' => [200, ['success' => true, 'data' => [
                'path' => $path, 'name' => 'labs-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.pdf', 'mime_type' => 'application/pdf',
            ]]],
        ]);

        $outcome = $this->uploads($http)->upload(self::SESSION, $this->file, 'labs.pdf', 'application/pdf');

        self::assertTrue($outcome->ok);
        self::assertSame($path, $outcome->file?->path);
        self::assertTrue($outcome->file?->belongsTo(self::SESSION));
        self::assertStringContainsString('/intake-submissions/upload-file/' . self::SESSION, $this->lastPath($http));
    }

    public function testATypeTheEmrDoesNotStoreIsARefusalAndItsMessageStaysOutOfTheLog(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/intake-submissions/upload-file/' => [400, ['success' => false, 'message' => 'Unsupported attachment type: image&#x2F;heic']],
        ]);

        $outcome = $this->uploads($http)->upload(self::SESSION, $this->file, 'photo.heic', 'image/heic');

        self::assertFalse($outcome->ok);
        self::assertTrue($outcome->refused, 'choosing another file is the fix, so the page says so');
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('intake.upload_failed', $log);
        self::assertStringContainsString('"status":400', $log);
        self::assertStringNotContainsString('Unsupported attachment', $log);
    }

    public function testAnOutageIsAFailureToRetryRatherThanARefusal(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/intake-submissions/upload-file/' => [503, ['success' => false, 'message' => 'down']],
        ]);

        $outcome = $this->uploads($http)->upload(self::SESSION, $this->file, 'labs.pdf', 'application/pdf');

        self::assertFalse($outcome->ok);
        self::assertFalse($outcome->refused);
    }

    public function testAnAnswerThatIsNotAReferenceIsNotTakenForOne(): void
    {
        // A path outside the session folder is not something the EMR files an
        // upload under, so it is not passed on as one.
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/intake-submissions/upload-file/' => [200, ['success' => true, 'data' => [
                'path' => '../../etc/passwd', 'name' => 'passwd', 'mime_type' => 'text/plain',
            ]]],
        ]);

        $outcome = $this->uploads($http)->upload(self::SESSION, $this->file, 'labs.txt', 'text/plain');

        self::assertFalse($outcome->ok);
        self::assertNull($outcome->file);
    }

    public function testAMultipartUploadStartsSendsAPartAndFinishesWithThePartsInOrder(): void
    {
        $path = 'acct1/org1/intake_submissions/' . self::SESSION . '/clip-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.mp4';
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/upload-file-multipart/initiate/' => [200, ['success' => true, 'data' => ['upload_id' => 'a1b2c3d4-e5f6-47a8-89b0-c1d2e3f4a5b6']]],
            '/upload-file-multipart/part/' => [200, ['success' => true, 'data' => ['part_number' => 2, 'etag' => '"9bb58f26"']]],
            '/upload-file-multipart/finish/' => [200, ['success' => true, 'data' => [
                'path' => $path, 'name' => 'clip-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.mp4', 'mime_type' => 'video/mp4',
            ]]],
        ]);
        $uploads = $this->uploads($http);

        $started = $uploads->start(self::SESSION, 'clip.mp4', 'video/mp4', 1000);
        $part = $uploads->part('a1b2c3d4-e5f6-47a8-89b0-c1d2e3f4a5b6', 2, $this->file, 'clip.mp4', 'video/mp4');
        $finished = $uploads->finish('a1b2c3d4-e5f6-47a8-89b0-c1d2e3f4a5b6', [
            ['part_number' => 2, 'etag' => '"b"'],
            ['part_number' => 1, 'etag' => '"a"'],
        ]);

        self::assertSame('a1b2c3d4-e5f6-47a8-89b0-c1d2e3f4a5b6', $started->uploadId);
        self::assertSame(['part_number' => 2, 'etag' => '"9bb58f26"'], $part->part, 'the ETag goes back as it came, quotes and all');
        self::assertSame($path, $finished->file?->path);

        $finish = end($http->requests);
        self::assertNotFalse($finish);
        $body = json_decode((string) $finish->getBody(), true);
        self::assertSame([1, 2], array_column($body['parts'] ?? [], 'part_number'), 'sorted, because the EMR assembles in the order it is given');
    }

    private function uploads(FakeEmrHttpClient $http): EmrIntakeUploads
    {
        file_put_contents(
            $this->dir . '/app.php',
            '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];',
        );

        return new EmrIntakeUploads(new ClientFactory(Config::load($this->dir), $this->rootDir), new OperatorLog($this->logFile), $http);
    }

    private function lastPath(FakeEmrHttpClient $http): string
    {
        $last = end($http->requests);

        return $last === false ? '' : $last->getUri()->getPath();
    }
}
