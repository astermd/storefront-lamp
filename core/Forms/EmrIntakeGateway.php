<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Enum\Event;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see IntakeGateway} over the SDK's `intakeSubmissions()` resource.
 *
 * The client is built on first use rather than in the constructor, so a
 * deployment with blank credentials still renders and still sells — it simply
 * records nothing.
 *
 * **Create versus update is decided by the event, not by a flag.** The SDK's
 * own docblock ties the two together: an `*Initiated` event is what brings the
 * server-side record into existence, and `*InProgress` / `*Completed` amend it.
 * Deriving the call from the event keeps one fact in one place; carrying a
 * separate "is this the first save" boolean alongside the event would let the
 * two disagree, and the failure — an update against a record that was never
 * created — is invisible until someone reads the EMR.
 *
 * **What a failure writes to disk.** Never the exception *message*. The SDK
 * builds it from the provider's own `message` field and the payload this class
 * sends is the visitor's clinical answers, so a provider that echoes the
 * request or explains the rejection would put PHI in the operator log next to
 * the session id. {@see OperatorLog::redact()} is key-based and cannot see
 * inside a string, so the message cannot be made safe — it is replaced by the
 * exception class and the status code it carries, which between them answer
 * the questions an outage actually raises (auth, rate limiting, validation,
 * transport, or the provider being down) and neither of which can carry
 * provider-supplied free text (`[20.6]`).
 *
 * The field *count* is logged because it distinguishes "we sent nothing" from
 * "the provider rejected a full form", and a count cannot leak an answer.
 */
final class EmrIntakeGateway implements IntakeGateway
{
    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function record(string $session, Event $event, string $teleformId, array $data, ?array $progress): bool
    {
        $initiating = self::initiates($event);

        try {
            $submissions = $this->client()->intakeSubmissions();

            if ($initiating) {
                $submissions->create($session, $event, $teleformId, $data, $progress);
            } else {
                $submissions->update($session, $event, $teleformId, $data, $progress);
            }

            return true;
        } catch (\Throwable $e) {
            $this->log->warning('intake.submission_failed', [
                'session' => $session,
                'operation' => $initiating ? 'create' : 'update',
                'event' => $event->value,
                'teleform_id' => $teleformId,
                'fields' => count($data),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return false;
        }
    }

    /** Whether this event is the one that brings the server-side record into being. */
    private static function initiates(Event $event): bool
    {
        return $event === Event::PreQualifyingInitiated || $event === Event::IntakeInitiated;
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
