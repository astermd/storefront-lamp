<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see TeleformGateway} over the SDK's `teleforms()` resource plus one plain
 * HTTPS GET.
 *
 * `viewByIdentifier()` does not return the definition: it returns a
 * short-lived signed URL to it (verified against the live API — a
 * `{view_url, key, expires_in}` envelope). Two other shapes are tolerated
 * because `[10.2]` requires it — the definition inline, or nested under a
 * wrapper key — and an unrecognised shape fails soft with an operator-facing
 * log rather than a broken form.
 *
 * The signed URL is never cached. Its lifetime (an hour) has nothing to do
 * with the definition's, and a cached URL would expire into a form outage
 * while a perfectly good definition sat one hop away.
 *
 * Every failure below logs the exception class and code and never its
 * message, for the reason {@see \AsterMD\Storefront\Emr\EmrCartGateway}
 * spells out: a provider that echoes the request back puts form content next
 * to an identifier in the operator log, and {@see OperatorLog::redact()} is
 * key-based and cannot see inside a string (`[20.6]`).
 */
final class EmrTeleformGateway implements TeleformGateway
{
    /** Keys checked in order for an already-parsed definition inside the envelope `[10.2]`. */
    private const array INLINE_KEYS = ['pages', 'form', 'definition', 'form_json', 'data'];

    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function metadata(string $teleformId): ?TeleformMetadata
    {
        try {
            $payload = $this->client()->teleforms()->view($teleformId)->data();
        } catch (\Throwable $e) {
            $this->log->warning('intake.teleform_view_failed', [
                'teleform' => $teleformId,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return null;
        }

        return TeleformMetadata::fromPayload($teleformId, $payload);
    }

    public function definition(TeleformMetadata $metadata): ?array
    {
        try {
            $envelope = $this->client()->teleforms()->viewByIdentifier($metadata->identifier)->data();
        } catch (\Throwable $e) {
            $this->log->warning('intake.teleform_identifier_failed', [
                'teleform' => $metadata->id,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return null;
        }

        $inline = self::inlineDefinition($envelope);
        if ($inline !== null) {
            return $inline;
        }

        $url = $envelope['view_url'] ?? null;
        if (!is_string($url) || $url === '') {
            $this->log->warning('intake.definition_shape_unrecognised', [
                'teleform' => $metadata->id,
                'keys' => implode(',', array_keys($envelope)),
            ]);

            return null;
        }

        return $this->fetch($url, $metadata->id);
    }

    /**
     * The three shapes `[10.2]` allows, in the order they are cheapest to
     * recognise: the envelope *is* the definition, or the definition sits
     * under one of a handful of wrapper keys. Presence of `pages` is what
     * identifies a definition — every recorded one has it, and it is the one
     * key the renderers cannot do without.
     *
     * @param array<string, mixed> $envelope
     *
     * @return array<string, mixed>|null
     */
    private static function inlineDefinition(array $envelope): ?array
    {
        if (isset($envelope['pages']) && is_array($envelope['pages'])) {
            return $envelope;
        }

        foreach (self::INLINE_KEYS as $key) {
            $candidate = $envelope[$key] ?? null;
            if (is_array($candidate) && isset($candidate['pages']) && is_array($candidate['pages'])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The signed URL points at an object store, not the EMR API, so it is
     * fetched outside the SDK and given a short timeout: the whole reason the
     * definition is cached is to keep a third-party round trip off the
     * critical path, and a hung fetch would reintroduce it.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $url, string $teleformId): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $this->log->warning('intake.definition_fetch_failed', ['teleform' => $teleformId]);

            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['pages'])) {
            $this->log->warning('intake.definition_undecodable', ['teleform' => $teleformId]);

            return null;
        }

        return $decoded;
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
