<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Forms\FileRules;
use AsterMD\Storefront\Forms\FormUnavailable;
use AsterMD\Storefront\Forms\IntakeSession;
use AsterMD\Storefront\Forms\IntakeUploads;
use AsterMD\Storefront\Forms\UploadOutcome;
use AsterMD\Storefront\Funnel\FunnelRules;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * `/intake/upload/…` -- a file-upload question's file, relayed to the EMR as
 * soon as it is picked.
 *
 * The browser cannot send it to the EMR itself: the call needs this
 * deployment's credential, which never reaches a page. So each request here
 * is one request there, and answers what the EMR answered -- a
 * `{path, name, mime_type}` for the page to post with the form, an upload id,
 * or a part's ETag. Nothing is kept on this server beyond the request.
 *
 * Five routes, the EMR's two flows ({@see FileRules::needsParts()}):
 *
 * - `upload/` takes a whole file;
 * - `upload/start/`, `upload/part/` and `upload/finish/` take a video, or
 *   anything over one request, in numbered parts;
 * - `upload/abort/` gives up a started upload, so the EMR drops its parts.
 *
 * **Each request names the question it is for**, and is held to that
 * question's rules ({@see FileRules}) on the form this journey is collecting.
 * The page checks the same rules first; a request refused here went round it.
 *
 * **An upload id is honoured only from the PHP session that started it.**
 * The EMR keeps no owner for one, so without this a request could send parts
 * into, finish or abort another visitor's upload by guessing its id.
 *
 * POSTs, so the CSRF check applies; they are also rate-limited per client
 * address, because each one spends an EMR call and carries up to ten
 * megabytes.
 */
final class IntakeUploadController
{
    private const string BUCKET = 'intake.upload';

    /** The PHP-session key holding the uploads this visitor has started and still has open. */
    private const string STARTED = '_intake_uploads';

    /** How many started uploads one visitor may hold open at once. */
    private const int MAX_STARTED = 10;

    private const int MAX_PARTS = 10000;

    private const string RETRY = 'The upload did not go through. Please try again.';

    private const string EXPIRED = 'Your session has expired. Please reload the page and attach the file again.';

    private readonly FunnelRules $rules;

    public function __construct(
        private readonly IntakeSession $intake,
        private readonly IntakeUploads $uploads,
        private readonly CartStore $carts,
        private readonly JourneyStore $journeys,
        ProductCatalog $catalog,
        private readonly DatabaseRateLimiter $limiter,
        private readonly OperatorLog $log,
    ) {
        $this->rules = new FunnelRules($catalog);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $file = self::uploadedFile($request);
        if ($file === null) {
            return self::json($response->withStatus(400), ['error' => self::RETRY]);
        }

        [$rules, $session, $refusal] = $this->question($request, $response);
        if ($rules === null || $session === null) {
            return $refusal;
        }

        $name = (string) $file->getClientFilename();
        $bytes = (int) $file->getSize();
        $mimeType = (string) $rules->mimeTypeFor($name);

        $problem = $rules->refusal($name, $bytes);
        if ($problem === null && FileRules::needsParts($mimeType, $bytes)) {
            // The page sends a video or a large file in parts; one arriving
            // whole here came from something else.
            $problem = sprintf('%s has to be sent in parts.', $name);
        }

        if ($problem !== null) {
            return self::json($response->withStatus(422), ['error' => $problem]);
        }

        if (!$this->allowed($request)) {
            return self::json($response->withStatus(429), ['error' => self::RETRY]);
        }

        return $this->answer($response, $this->uploads->upload($session, self::pathOf($file), $name, $mimeType), $name);
    }

    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $name = is_string($body['file_name'] ?? null) ? trim($body['file_name']) : '';
        $bytes = is_numeric($body['file_size'] ?? null) ? (int) $body['file_size'] : 0;

        [$rules, $session, $refusal] = $this->question($request, $response);
        if ($rules === null || $session === null) {
            return $refusal;
        }

        $problem = $rules->refusal($name, $bytes);
        if ($problem !== null) {
            return self::json($response->withStatus(422), ['error' => $problem]);
        }

        $started = self::started();
        if (count($started) >= self::MAX_STARTED) {
            return self::json($response->withStatus(429), ['error' => self::RETRY]);
        }

        if (!$this->allowed($request)) {
            return self::json($response->withStatus(429), ['error' => self::RETRY]);
        }

        $mimeType = (string) $rules->mimeTypeFor($name);
        $outcome = $this->uploads->start($session, $name, $mimeType, $bytes);

        if (!$outcome->ok || $outcome->uploadId === null) {
            return $this->answer($response, $outcome, $name);
        }

        $started[$outcome->uploadId] = ['name' => $name, 'mime_type' => $mimeType, 'bytes' => $bytes];
        $_SESSION[self::STARTED] = $started;

        return self::json($response, [
            'upload_id' => $outcome->uploadId,
            'part_bytes' => FileRules::requestBytes(),
        ]);
    }

    public function part(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $uploadId = self::text($body['upload_id'] ?? null);
        $partNumber = is_numeric($body['part_number'] ?? null) ? (int) $body['part_number'] : 0;
        $upload = $uploadId === null ? null : (self::started()[$uploadId] ?? null);
        $file = self::uploadedFile($request);

        if ($upload === null || $file === null || $partNumber < 1 || $partNumber > self::MAX_PARTS
            || (int) $file->getSize() > FileRules::SINGLE_REQUEST_MAX_BYTES) {
            return self::json($response->withStatus(400), ['error' => self::RETRY]);
        }

        if (!$this->allowed($request)) {
            return self::json($response->withStatus(429), ['error' => self::RETRY]);
        }

        $outcome = $this->uploads->part((string) $uploadId, $partNumber, self::pathOf($file), $upload['name'], $upload['mime_type']);

        return $outcome->ok && $outcome->part !== null
            ? self::json($response, $outcome->part)
            : $this->answer($response, $outcome, $upload['name']);
    }

    public function finish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $uploadId = self::text($body['upload_id'] ?? null);
        $upload = $uploadId === null ? null : (self::started()[$uploadId] ?? null);
        $parts = self::parts($body['parts'] ?? null);

        if ($upload === null || $parts === null) {
            return self::json($response->withStatus(400), ['error' => self::RETRY]);
        }

        if (!$this->allowed($request)) {
            return self::json($response->withStatus(429), ['error' => self::RETRY]);
        }

        $outcome = $this->uploads->finish((string) $uploadId, $parts);

        // Finished, or refused for good: either way the id is spent. A failure
        // that might succeed on a retry keeps it, so the page can retry the
        // finish rather than send every part again.
        if ($outcome->ok || $outcome->refused) {
            $this->forget((string) $uploadId);
        }

        return $this->answer($response, $outcome, $upload['name']);
    }

    public function abort(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $uploadId = self::text($body['upload_id'] ?? null);

        if ($uploadId !== null && isset(self::started()[$uploadId])) {
            $this->uploads->abort($uploadId);
            $this->forget($uploadId);
        }

        // The same answer either way: an id this visitor does not hold has
        // nothing of theirs to abort, and saying so would confirm it exists.
        return self::json($response, ['aborted' => true]);
    }

    /**
     * The rules of the question a request names, and the session its file is
     * filed under -- or the response refusing it.
     *
     * @return array{0: ?FileRules, 1: ?string, 2: ResponseInterface}
     */
    private function question(ServerRequestInterface $request, ResponseInterface $response): array
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $session = $this->journeys->sessionUuid();
        if ($session === null || $session === '' || $this->journeys->state() === null) {
            return [null, null, self::json($response->withStatus(409), ['error' => self::EXPIRED])];
        }

        $step = ($body['step'] ?? null) === IntakeSession::STEP_PREQUALIFICATION
            ? IntakeSession::STEP_PREQUALIFICATION
            : IntakeSession::STEP_INTAKE;
        $teleformId = $this->teleformId($step);
        $field = self::text($body['field'] ?? null);

        $rules = null;
        if ($teleformId !== null && $field !== null) {
            try {
                $rules = $this->intake->fileRulesFor($teleformId, $field);
            } catch (FormUnavailable) {
                $rules = null;
            }
        }

        if ($rules === null) {
            $this->log->info('intake.upload_no_question', ['step' => $step]);

            return [null, null, self::json($response->withStatus(422), ['error' => self::EXPIRED])];
        }

        return [$rules, $session, $response];
    }

    /** The same question {@see IntakeController} asks, so the form a file is checked against is the form on screen. */
    private function teleformId(string $step): ?string
    {
        try {
            $cart = $this->carts->cart();
        } catch (\Throwable) {
            $cart = new Cart();
        }

        $state = $this->journeys->state();

        return $step === IntakeSession::STEP_PREQUALIFICATION
            ? $this->rules->prequalificationFormToCollect($cart, $state)
            : $this->rules->intakeFormToCollect($cart, $state);
    }

    private function answer(ResponseInterface $response, UploadOutcome $outcome, string $name): ResponseInterface
    {
        if ($outcome->ok && $outcome->file !== null) {
            return self::json($response, ['file' => $outcome->file->toArray()]);
        }

        if ($outcome->refused) {
            return self::json($response->withStatus(422), [
                'error' => sprintf('%s could not be accepted. Please choose a different file.', $name),
            ]);
        }

        return self::json($response->withStatus(502), ['error' => self::RETRY]);
    }

    private function allowed(ServerRequestInterface $request): bool
    {
        return $this->limiter->allow(self::BUCKET, RequestContext::fromRequest($request)->clientIp ?? 'unknown');
    }

    /** @return array<string, array{name: string, mime_type: string, bytes: int}> */
    private static function started(): array
    {
        $started = $_SESSION[self::STARTED] ?? [];

        return is_array($started) ? $started : [];
    }

    private function forget(string $uploadId): void
    {
        $started = self::started();
        unset($started[$uploadId]);
        $_SESSION[self::STARTED] = $started;
    }

    private static function uploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        return $file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK ? $file : null;
    }

    private static function pathOf(UploadedFileInterface $file): string
    {
        return (string) $file->getStream()->getMetadata('uri');
    }

    /**
     * The part list the page posts to finish an upload, as JSON, or null when
     * it is not one.
     *
     * @return ?list<array{part_number: int, etag: string}>
     */
    private static function parts(mixed $posted): ?array
    {
        $decoded = is_string($posted) && strlen($posted) <= 200_000 ? json_decode($posted, true) : null;
        if (!is_array($decoded) || $decoded === [] || count($decoded) > self::MAX_PARTS) {
            return null;
        }

        $parts = [];
        foreach ($decoded as $part) {
            $number = is_array($part) ? ($part['part_number'] ?? null) : null;
            $etag = is_array($part) ? ($part['etag'] ?? null) : null;
            if (!is_int($number) || $number < 1 || $number > self::MAX_PARTS || !is_string($etag) || $etag === '') {
                return null;
            }

            $parts[] = ['part_number' => $number, 'etag' => $etag];
        }

        return $parts;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 200 ? trim($value) : null;
    }

    /** @param array<string, mixed> $data */
    private static function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
