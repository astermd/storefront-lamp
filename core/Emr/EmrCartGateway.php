<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Exception\NotFoundException;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see CartGateway} over the SDK's `carts()` resource.
 *
 * The client is built on first use rather than in the constructor, so a
 * deployment with blank credentials still renders and still sells — it simply
 * mirrors nothing. `NotFound` is separated from every other failure because
 * it is the one the caller can repair: the EMR has no cart for this session,
 * so the next call should be a create rather than another update.
 *
 * Two rules govern what the failures below write to disk.
 *
 * The exception *message* never does. The SDK builds it from the provider's
 * own `message` field, and the payload this class sends carries a product name
 * per line (`[7.11]`), so a provider that echoes the request or explains the
 * rejection puts a prescription name in the operator log next to the session
 * id. {@see OperatorLog::redact()} is key-based and cannot see inside a
 * string, so the message cannot be made safe — it is replaced by the exception
 * class and the status code it carries. Those two answer the questions an
 * outage actually raises (is this auth, rate limiting, validation, transport,
 * or the provider being down, and since when) and neither can carry
 * provider-supplied free text (`[20.6]`).
 *
 * The event name is `cart.mirror_request_failed`, distinct from the
 * `cart.mirror_failed` {@see CartMirror} writes for the same incident.
 * `CartMirror` logs the outcome of mirroring a cart; this logs one failed call
 * to the provider, and a mirror can make two of them (an update that 404s,
 * then a create). Sharing one name made every failure count at least twice in
 * any aggregation, and made "how many mirrors failed" unanswerable.
 */
final class EmrCartGateway implements CartGateway
{
    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function create(string $session, array $items): CartMirrorResult
    {
        try {
            $this->client()->carts()->create($session, $items);

            return CartMirrorResult::Ok;
        } catch (\Throwable $e) {
            $this->log->warning('cart.mirror_request_failed', [
                'session' => $session,
                'operation' => 'create',
                'items' => count($items),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return CartMirrorResult::Failed;
        }
    }

    public function update(string $session, array $items): CartMirrorResult
    {
        try {
            $this->client()->carts()->update($session, $items);

            return CartMirrorResult::Ok;
        } catch (NotFoundException) {
            return CartMirrorResult::NotFound;
        } catch (\Throwable $e) {
            $this->log->warning('cart.mirror_request_failed', [
                'session' => $session,
                'operation' => 'update',
                'items' => count($items),
                'exception' => $e::class,
                'status' => $e->getCode(),
            ]);

            return CartMirrorResult::Failed;
        }
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
