<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see SessionEventGateway} over the SDK's `sessionEvents()` resource.
 *
 * Failures are swallowed and logged by exception class and status code, never
 * by message, for the reason {@see EmrCartGateway} gives (`[20.6]`): a page
 * title or path is the provider's to echo back, and a product page's title is
 * a prescription name. The client is built on first use so a deployment with
 * blank credentials still renders.
 */
final class EmrSessionEventGateway implements SessionEventGateway
{
    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function pageView(string $session, PageView $page): void
    {
        $this->send($session, 'page_view', fn (AsterMDClient $client) => $client->sessionEvents()->pageView(
            $session,
            $page->type,
            $page->path,
            $page->details(),
        ));
    }

    public function beginIdentityVerification(string $session): void
    {
        $this->send(
            $session,
            'begin_identity_verification',
            static fn (AsterMDClient $client) => $client->sessionEvents()->beginIdentityVerification($session),
        );
    }

    /** @param \Closure(AsterMDClient): mixed $call */
    private function send(string $session, string $event, \Closure $call): void
    {
        try {
            $call($this->client ??= $this->clients->create($this->httpClient));
        } catch (\Throwable $e) {
            $this->log->warning('session_event.request_failed', [
                'session' => $session,
                'event' => $event,
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);
        }
    }
}
