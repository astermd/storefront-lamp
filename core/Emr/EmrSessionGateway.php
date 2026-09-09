<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Exception\NotFoundException;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see SessionGateway} over the SDK's `sessions()` resource.
 *
 * The SDK client is built on first use rather than in the constructor, so a
 * deployment with blank or wrong credentials still renders every page — it
 * simply has no sessions — instead of failing at container build time. Every
 * call is wrapped: a transport error, a non-2xx envelope, or a missing
 * identifier all become null plus an operator log line, because a tracking
 * outage that takes the storefront down is a worse outage than the one it
 * was reporting.
 *
 * The response readers probe several paths for the opportunity id and the
 * event names, since those key names are the EMR's to change and a shape
 * variant should degrade to "not found" rather than to a crash.
 */
final class EmrSessionGateway implements SessionGateway
{
    private ?AsterMDClient $client = null;

    /**
     * Paths checked in order for the linked opportunity id, searched against
     * the whole session entry (so both `data.<path>` and a bare `<path>`
     * are covered). The `data.`-prefixed variant of each shape is tried
     * first, since the SDK docblock documents the read-model as sitting
     * under `data`; the bare variant is the fallback for a flatter payload.
     *
     * Changing what these paths (or {@see self::EVENT_NAME_KEYS}) read means
     * bumping {@see \AsterMD\Storefront\Journey\SessionResolver::READ_MODEL_SHAPE},
     * so journeys already reconciled by the previous generation re-read once
     * rather than keeping an answer produced by a path that was wrong.
     */
    private const array OPPORTUNITY_PATHS = [
        'data.opportunity_id',
        'opportunity_id',
        'data.opportunity.id',
        'opportunity.id',
        'data.opportunity._id',
        'opportunity._id',
        'data.opportunity.opportunity_id',
        'opportunity.opportunity_id',
    ];

    /** Keys checked in order for an event's name inside the events timeline. */
    private const array EVENT_NAME_KEYS = ['event', 'name', 'type', 'event_name'];

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function create(array $data, ?string $userAgent, ?string $clientIp): ?string
    {
        try {
            $session = $this->client()->sessions()->create($data, $userAgent, $clientIp)->data()['session'] ?? null;

            if (!is_string($session) || $session === '') {
                $this->log->warning('session.create_failed', ['reason' => 'no session identifier in response']);

                return null;
            }

            return $session;
        } catch (\Throwable $e) {
            $this->log->warning('session.create_failed', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    public function view(string $uuid): ?array
    {
        try {
            $data = $this->client()->sessions()->view([$uuid])->data();
        } catch (NotFoundException) {
            return ['exists' => false, 'opportunity_id' => null, 'events' => []];
        } catch (\Throwable $e) {
            $this->log->warning('session.view_failed', ['session' => $uuid, 'reason' => $e->getMessage()]);

            return null;
        }

        $entry = $data[$uuid] ?? null;
        if (!is_array($entry)) {
            return ['exists' => false, 'opportunity_id' => null, 'events' => []];
        }

        $snapshot = [
            'exists' => true,
            'opportunity_id' => self::opportunityId($entry),
            'events' => self::eventNames(is_array($entry['events'] ?? null) ? $entry['events'] : []),
        ];

        // A session the EMR knows but whose read-model yielded neither an
        // opportunity nor a single event name is byte-identical to a genuinely
        // fresh session — and is also exactly what an extractor looking under
        // the wrong key produces. Without this line the second case is
        // invisible; with it, "opportunities stopped being recovered" is
        // something an operator can grep for. Info, not warning: for a session
        // minted moments ago this is the correct and expected answer.
        if ($snapshot['opportunity_id'] === null && $snapshot['events'] === []) {
            $this->log->info('session.view_read_model_empty', ['session' => $uuid]);
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $entry the whole session entry, covering both `data.<path>` and bare `<path>` shapes */
    private static function opportunityId(array $entry): ?string
    {
        foreach (self::OPPORTUNITY_PATHS as $path) {
            $value = $entry;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $events
     *
     * @return list<string>
     */
    private static function eventNames(array $events): array
    {
        $names = [];

        foreach ($events as $event) {
            if (is_scalar($event)) {
                $names[] = (string) $event;
                continue;
            }

            if (!is_array($event)) {
                continue;
            }

            foreach (self::EVENT_NAME_KEYS as $key) {
                if (isset($event[$key]) && is_scalar($event[$key])) {
                    $names[] = (string) $event[$key];
                    break;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
