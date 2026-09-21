<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

use AsterMD\CheckoutChampClient\API;
use AsterMD\CheckoutChampClient\Http\HttpClientInterface;

/**
 * Builds the provider's client, and is the seam a test replaces.
 *
 * The transport is injectable because the payload is the part worth testing: a
 * test that stubs the client asserts only that a method was called, while one
 * that swaps the transport can assert the exact request the provider would have
 * received -- which for this provider means the exact **query string**, since
 * that is where it takes its parameters.
 *
 * The package's own debug logging is left off, as the other adapter's is. It
 * masks credentials in the URL it writes, which is more than this application's
 * own wire log does, but a redacted transcript still records who bought what
 * and when -- so switching it on stays a deployment's deliberate decision
 * rather than a default set here. {@see CheckoutChampWireLog} is the switch
 * this application offers, and it is the one `config:validate` polices.
 */
final class CheckoutChampApiFactory
{
    public function __construct(private readonly ?HttpClientInterface $transport = null)
    {
    }

    public function create(CheckoutChampCredentials $credentials): API
    {
        $options = ['host' => $credentials->host, 'timeout' => 30, 'connectTimeout' => 10];

        if ($credentials->basePath !== '') {
            $options['basePath'] = $credentials->basePath;
        }

        return new API($credentials->loginId, $credentials->password(), $options, $this->transport);
    }
}
