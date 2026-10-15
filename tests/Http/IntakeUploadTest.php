<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\IntakeGateway;
use AsterMD\Storefront\Forms\IntakeUploads;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\FakeIntakeGateway;
use AsterMD\Storefront\Tests\Support\FakeIntakeUploads;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * File-upload and signature questions, end to end: the page that draws them,
 * the `/intake/upload/…` relay to the EMR, and what a save and a submit then
 * store and send.
 *
 * The EMR is {@see FakeIntakeUploads}, which files every upload under the
 * session it was sent for, exactly as the EMR does -- so the case that posts
 * someone else's path is posting a path the fake would never have produced
 * for this visitor.
 */
final class IntakeUploadTest extends TestCase
{
    use TempDatabase;

    /**
     * The EMR session the fake session gateway mints, and so the one every
     * upload is filed under. Also the cookie every request carries, so the
     * journey a POST reads is the one the first GET minted.
     */
    private const string SESSION = '4f1b6f2a-8c3d-4e6a-9b7f-2d5e8a1c6f30';

    private const string COOKIE = self::SESSION;

    private const string OTHER_SESSION = '9e8d7c6b-5a49-4382-9170-6f5e4d3c2b1a';

    private const string SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $cacheDir;

    private \PDO $pdo;

    private FakeIntakeUploads $uploads;

    private FakeIntakeGateway $submissions;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cacheDir = sys_get_temp_dir() . '/intake-upload-' . bin2hex(random_bytes(6));
        $this->uploads = new FakeIntakeUploads();
        $this->submissions = new FakeIntakeGateway();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testTheFormDrawsAFileQuestionWithTheLimitsItWillBeHeldTo(): void
    {
        $app = $this->started();
        $body = (string) $app->handle($this->get('/intake/medical/'))->getBody();

        self::assertStringContainsString('data-intake-upload', $body);
        self::assertStringContainsString('accept=".pdf,.png"', $body, 'the author\'s own list, kept to what the EMR stores');
        self::assertStringContainsString('data-max-files="2"', $body);
        self::assertStringContainsString('up to 5 MB each', $body);
        self::assertStringContainsString('data-intake-signature-pad', $body);
        self::assertStringContainsString('data-upload-url="/intake/upload/"', $body);
        self::assertStringNotContainsString('data-intake-unsupported', $body, 'neither question is refused under the server renderer');
    }

    public function testAPickedFileIsRelayedWholeAndAnsweredWithWhereTheEmrStoredIt(): void
    {
        $app = $this->started();
        $response = $this->upload($app, '/intake/upload/', ['field' => 'labs'], $this->file('labs.pdf', 2048));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $file = self::json($response)['file'] ?? null;
        self::assertIsArray($file);
        self::assertStringContainsString('/intake_submissions/' . self::SESSION . '/', (string) $file['path']);
        self::assertSame('application/pdf', $file['mime_type']);

        self::assertSame([['call' => 'upload', 'session' => self::SESSION, 'name' => 'labs.pdf', 'mime_type' => 'application/pdf', 'bytes' => 2048]], $this->uploads->calls);
    }

    public function testAFileTheQuestionDoesNotTakeNeverReachesTheEmr(): void
    {
        $app = $this->started();

        $wrongKind = $this->upload($app, '/intake/upload/', ['field' => 'labs'], $this->file('notes.docx', 100));
        $tooLarge = $this->upload($app, '/intake/upload/', ['field' => 'labs'], $this->file('scan.png', 5 * 1024 * 1024 + 1));
        $noSuchQuestion = $this->upload($app, '/intake/upload/', ['field' => 'first_name'], $this->file('labs.pdf', 100));

        self::assertSame(422, $wrongKind->getStatusCode());
        self::assertStringContainsString('not a file type this question accepts', (string) (self::json($wrongKind)['error'] ?? ''));
        self::assertSame(422, $tooLarge->getStatusCode());
        self::assertStringContainsString('larger than 5 MB', (string) (self::json($tooLarge)['error'] ?? ''));
        self::assertSame(422, $noSuchQuestion->getStatusCode(), 'a text question takes no file');
        self::assertSame([], $this->uploads->calls);
    }

    public function testAFileTheEmrRefusesIsReportedAsOneToReplace(): void
    {
        $app = $this->started();
        $this->uploads->refuse = true;

        $response = $this->upload($app, '/intake/upload/', ['field' => 'labs'], $this->file('labs.pdf', 100));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('choose a different file', (string) (self::json($response)['error'] ?? ''));
    }

    public function testAVideoIsStartedSentInPartsAndFinished(): void
    {
        // The EMR's single-request endpoint refuses every video however small,
        // so even a short clip goes the multipart way.
        $app = $this->started(self::formWithVideo());

        $whole = $this->upload($app, '/intake/upload/', ['field' => 'clip'], $this->file('clip.mp4', 1000));
        self::assertSame(422, $whole->getStatusCode(), 'a video sent whole came from something other than the page');

        $start = $this->post($app, '/intake/upload/start/', ['field' => 'clip', 'file_name' => 'clip.mp4', 'file_size' => '1000']);
        self::assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $uploadId = (string) (self::json($start)['upload_id'] ?? '');

        $part = $this->upload($app, '/intake/upload/part/', ['upload_id' => $uploadId, 'part_number' => '1'], $this->file('clip.mp4', 1000));
        self::assertSame(['part_number' => 1, 'etag' => '"etag-1"'], self::json($part));

        $finish = $this->post($app, '/intake/upload/finish/', [
            'upload_id' => $uploadId,
            'parts' => json_encode([['part_number' => 1, 'etag' => '"etag-1"']]),
        ]);
        self::assertSame(200, $finish->getStatusCode());
        self::assertSame('video/mp4', self::json($finish)['file']['mime_type'] ?? null);

        self::assertSame(['start', 'part', 'finish'], $this->uploads->callNames());
        self::assertSame('video/mp4', $this->uploads->calls[0]['mime_type']);

        $again = $this->post($app, '/intake/upload/finish/', ['upload_id' => $uploadId, 'parts' => json_encode([['part_number' => 1, 'etag' => 'x']])]);
        self::assertSame(400, $again->getStatusCode(), 'a finished upload id is spent');
    }

    public function testAnUploadThisVisitorDidNotStartCannotBeWrittenFinishedOrAborted(): void
    {
        // The EMR keeps no owner for an upload id, so the id alone must not be
        // enough to send parts into someone else's upload.
        $app = $this->started(self::formWithVideo());
        $foreign = 'a1b2c3d4-0000-4000-8000-999999999999';

        $part = $this->upload($app, '/intake/upload/part/', ['upload_id' => $foreign, 'part_number' => '1'], $this->file('clip.mp4', 10));
        $finish = $this->post($app, '/intake/upload/finish/', ['upload_id' => $foreign, 'parts' => json_encode([['part_number' => 1, 'etag' => 'x']])]);
        $abort = $this->post($app, '/intake/upload/abort/', ['upload_id' => $foreign]);

        self::assertSame(400, $part->getStatusCode());
        self::assertSame(400, $finish->getStatusCode());
        self::assertSame(['aborted' => true], self::json($abort), 'the same answer, so the id is not confirmed to exist');
        self::assertSame([], $this->uploads->calls);
    }

    public function testAStartedUploadThatIsAbandonedIsAbortedAtTheEmr(): void
    {
        $app = $this->started(self::formWithVideo());
        $uploadId = (string) (self::json($this->post($app, '/intake/upload/start/', [
            'field' => 'clip', 'file_name' => 'clip.mp4', 'file_size' => '1000',
        ]))['upload_id'] ?? '');

        $this->post($app, '/intake/upload/abort/', ['upload_id' => $uploadId]);

        self::assertSame(['start', 'abort'], $this->uploads->callNames());
        self::assertSame($uploadId, $this->uploads->calls[1]['upload_id']);
    }

    public function testASaveKeepsTheVisitorsOwnFilesAndDropsAPathFiledUnderAnotherSession(): void
    {
        $app = $this->started();
        $own = self::reference(self::SESSION, 'labs-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.pdf');
        $foreign = self::reference(self::OTHER_SESSION, 'someone-else-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.pdf');

        $this->post($app, '/intake/save/', [
            'page' => '0',
            'labs' => ['', json_encode($own), json_encode($foreign)],
        ]);

        self::assertSame([$own], $this->answers()['labs'] ?? null);
    }

    public function testRemovingTheLastFileClearsTheAnswer(): void
    {
        $app = $this->started();
        $own = self::reference(self::SESSION, 'labs-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.pdf');

        $this->post($app, '/intake/save/', ['page' => '0', 'labs' => ['', json_encode($own)]]);
        $this->post($app, '/intake/save/', ['page' => '0', 'labs' => ['']]);

        self::assertSame([], $this->answers()['labs'] ?? null, 'the blank entry is what says the question now has no files');
    }

    public function testASubmissionSendsFilesAndTheSignatureUnderTheFileType(): void
    {
        $app = $this->started();
        $own = self::reference(self::SESSION, 'labs-3f9a1c62-2b41-4f0e-91a7-6c1d0e5b8a44.pdf');

        $response = $this->post($app, '/intake/submit/', [
            'first_name' => 'Ada',
            'labs' => ['', json_encode($own)],
            'consent_signature' => self::SIGNATURE,
        ]);
        self::assertSame(303, $response->getStatusCode());

        $sent = [];
        foreach (end($this->submissions->records)['data'] ?? [] as $entry) {
            $sent[$entry['name']] = $entry;
        }

        self::assertSame('file', $sent['labs']['type']);
        self::assertSame([['value' => $own]], $sent['labs']['value']);
        self::assertSame('file', $sent['consent_signature']['type']);
        self::assertSame([['value' => self::SIGNATURE]], $sent['consent_signature']['value']);
        self::assertSame('text', $sent['first_name']['type'], 'every other field keeps its authored type');
    }

    public function testARequiredFileOrSignatureLeftEmptyRefusesTheSubmission(): void
    {
        $app = $this->started();

        $response = $this->post($app, '/intake/submit/', ['first_name' => 'Ada', 'labs' => [''], 'consent_signature' => '']);
        $body = (string) $response->getBody();

        self::assertStringContainsString('Recent lab results is required.', $body);
        self::assertStringContainsString('Signature is required.', $body);
        self::assertNotContains(
            'intake_completed',
            array_map(static fn (array $record): string => $record['event']->value, $this->submissions->records),
        );
    }

    /** An app with a journey started: the form has been drawn once, which mints the session and the CSRF token. */
    private function started(?array $definition = null): App
    {
        $this->seedCart();
        $app = AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->pdo = $this->tempPdo(),
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            TeleformGateway::class => self::gateway($definition ?? self::form()),
            DefinitionCache::class => new DefinitionCache($this->cacheDir, 3600),
            SessionGateway::class => new FakeSessionGateway(mintUuid: self::SESSION),
            IntakeUploads::class => $this->uploads,
            IntakeGateway::class => $this->submissions,
        ]);

        $page = $app->handle($this->get('/intake/medical/'));
        self::assertSame(200, $page->getStatusCode());

        return $app;
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        $row = (new SessionRepository(fn (): \PDO => $this->pdo))->find(self::COOKIE);
        self::assertNotNull($row);

        $answers = $row['journey_state']['form_answers'] ?? [];

        return is_array($answers) ? (array) (reset($answers) ?: []) : [];
    }

    private function seedCart(): void
    {
        $_SESSION['cart'] = ['session' => null, 'territory' => null, 'lines' => [[
            'slug' => 'semaglutide', 'name' => 'Semaglutide', 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 4600, 'variant_id' => 'semaglutide-3m',
        ]]];
    }

    private function get(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withCookieParams(['amd_session' => self::COOKIE]);
    }

    /** @param array<string, mixed> $body */
    private function post(App $app, string $path, array $body): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withParsedBody($body + ['_csrf' => (string) ($_SESSION['_csrf'] ?? ''), 'step' => 'intake'])
                ->withCookieParams(['amd_session' => self::COOKIE]),
        );
    }

    /** @param array<string, string> $body */
    private function upload(App $app, string $path, array $body, UploadedFile $file): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withParsedBody($body + ['_csrf' => (string) ($_SESSION['_csrf'] ?? ''), 'step' => 'intake'])
                ->withUploadedFiles(['file' => $file])
                ->withCookieParams(['amd_session' => self::COOKIE]),
        );
    }

    private function file(string $name, int $bytes): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload-');
        file_put_contents($path, str_repeat('x', $bytes));
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'application/octet-stream', $bytes, UPLOAD_ERR_OK);
    }

    /** @return array<string, mixed> */
    private static function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @return array{path: string, name: string, mime_type: string} */
    private static function reference(string $session, string $name): array
    {
        return ['path' => sprintf('acct1/org1/intake_submissions/%s/%s', $session, $name), 'name' => $name, 'mime_type' => 'application/pdf'];
    }

    /** @return array<string, mixed> */
    private static function form(): array
    {
        return [
            'formId' => 'f-uploads',
            'formName' => 'Uploads',
            'pages' => [[
                'pageId' => 'p1',
                'title' => 'Documents',
                'order' => 0,
                'fields' => [
                    ['fieldId' => 'first_name', 'name' => 'first_name', 'type' => 'text', 'label' => 'First name', 'required' => true],
                    [
                        'fieldId' => 'file-upload-1', 'name' => 'labs', 'type' => 'file-upload', 'label' => 'Recent lab results',
                        'required' => true,
                        'properties' => ['maxFiles' => 2, 'allowMultipleFiles' => true, 'maxFileSizeMb' => 5, 'fileTypes' => 'pdf, .PNG, zip', 'dragDrop' => true],
                    ],
                    [
                        'fieldId' => 'signature-1', 'name' => 'consent_signature', 'type' => 'signature', 'label' => 'Signature',
                        'required' => true,
                        'properties' => ['signatureCanvasWidth' => 320, 'signatureCanvasHeight' => 220],
                    ],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function formWithVideo(): array
    {
        return [
            'formId' => 'f-video',
            'formName' => 'Video',
            'pages' => [[
                'pageId' => 'p1',
                'order' => 0,
                'fields' => [[
                    'fieldId' => 'file-upload-2', 'name' => 'clip', 'type' => 'file-upload', 'label' => 'A short video',
                    'properties' => ['maxFiles' => 1, 'maxFileSizeMb' => 200, 'fileTypes' => 'mp4,mov'],
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed> $definition */
    private static function gateway(array $definition): TeleformGateway
    {
        return new class($definition) implements TeleformGateway {
            /** @param array<string, mixed> $definition */
            public function __construct(private readonly array $definition)
            {
            }

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'acct/org/form_' . md5((string) json_encode($this->definition)) . '.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: [],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return $this->definition;
            }
        };
    }
}
