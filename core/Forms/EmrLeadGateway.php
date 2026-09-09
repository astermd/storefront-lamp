<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see LeadGateway} over the SDK's `opportunities()` resource.
 *
 * The client is built on first use rather than in the constructor, so a
 * deployment with blank credentials still renders and still sells — it simply
 * captures no leads.
 *
 * **The created id is read defensively.** The response envelope is checked for
 * `_id` and then `id`, because the sales service's document identifier is
 * Mongo-shaped while several sibling endpoints answer with a plain `id`, and an
 * opportunity created without a recoverable id is worse than one never created
 * at all: the lead exists in the EMR, the journey cannot reach it again, and
 * every later save would create a duplicate. That case gets its own event,
 * `intake.lead_id_missing`, so it is distinguishable from a failed write in any
 * aggregation — the two need different responses.
 *
 * **What a failure writes to disk.** Never the exception *message*. The SDK
 * builds it from the provider's own `message` field and the payload this class
 * sends carries the visitor's name, email and clinical answers, so a provider
 * that echoes the request or explains the rejection would put PHI in the
 * operator log. {@see OperatorLog::redact()} is key-based and cannot see inside
 * a string, so the message cannot be made safe — it is replaced by the
 * exception class and the status code, which answer the questions an outage
 * raises without carrying provider-supplied free text (`[20.6]`). For the same
 * reason the context names the payload's **keys** and never its values.
 */
final class EmrLeadGateway implements LeadGateway
{
    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function create(array $payload): ?string
    {
        try {
            $data = $this->client()->opportunities()->create($payload)->data();
        } catch (\Throwable $e) {
            $this->log->warning('intake.lead_request_failed', [
                'operation' => 'create',
                'fields' => array_keys($payload),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return null;
        }

        $id = self::identifier($data);

        if ($id === null) {
            $this->log->error('intake.lead_id_missing', ['keys' => array_keys($data)]);
        }

        return $id;
    }

    public function update(string $opportunityId, array $payload): bool
    {
        try {
            $this->client()->opportunities()->update($opportunityId, $payload);

            return true;
        } catch (\Throwable $e) {
            $this->log->warning('intake.lead_request_failed', [
                'operation' => 'update',
                'opportunity_id' => $opportunityId,
                'fields' => array_keys($payload),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return false;
        }
    }

    /**
     * The new record's id, under either of the two keys the sales service uses.
     *
     * @param array<string, mixed> $data
     */
    private static function identifier(array $data): ?string
    {
        foreach (['_id', 'id'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }

    public function writes(): bool
    {
        return true;
    }
}
