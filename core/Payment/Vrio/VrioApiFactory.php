<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

use AsterMD\VrioClient\API;
use AsterMD\VrioClient\Http\HttpClientInterface;

/**
 * Builds the provider's client, and is the seam a test replaces.
 *
 * The transport is injectable because the payload is the part worth testing: a
 * test that stubs the client asserts only that a method was called, while one
 * that swaps the transport can assert the exact JSON body the provider would
 * have received.
 *
 * Debug logging is left off. The package writes the request URL verbatim and
 * redacted logs still record who bought what and when, so turning it on is a
 * deployment's deliberate decision, documented for the operator rather than
 * defaulted here.
 */
final class VrioApiFactory
{
    public function __construct(private readonly ?HttpClientInterface $transport = null)
    {
    }

    public function create(VrioCredentials $credentials): API
    {
        $options = ['host' => $credentials->host, 'timeout' => 30, 'connectTimeout' => 10];

        if ($credentials->basePath !== '') {
            $options['basePath'] = $credentials->basePath;
        }

        return new API($credentials->apiKey(), $options, $this->transport);
    }
}
