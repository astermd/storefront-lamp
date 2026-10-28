<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Stripe;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/**
 * Builds the Stripe client the adapter talks through.
 *
 * **The transport is the test-environment fence**, as it is for the other two
 * providers: under the suite it is {@see StripeRefusingTransport}, so a test
 * that forgot a stub fails instead of creating a payment. Stripe's SDK takes
 * its HTTP client process-wide rather than per client
 * ({@see ApiRequestor::setHttpClient()}), so it is installed on every
 * {@see self::create()} -- the last adapter built is the one whose transport
 * is in force, and a deployment builds exactly one. Null leaves the SDK's own
 * cURL client in place.
 *
 * **The API version is pinned to the one the EMR reads these payments with.**
 * The EMR reads back what this adapter writes -- the line items, the metadata,
 * the saved card -- and a payment written under one version and read under
 * another is two shapes of one object.
 */
final class StripeApiFactory
{
    /** The Stripe API version the EMR's sales service pins. */
    public const string API_VERSION = '2026-09-30.endive';

    public function __construct(private readonly ?ClientInterface $transport = null)
    {
    }

    public function create(StripeCredentials $credentials): StripeClient
    {
        if ($this->transport !== null) {
            ApiRequestor::setHttpClient($this->transport);
        }

        $config = ['api_key' => $credentials->secretKey(), 'stripe_version' => self::API_VERSION];
        if ($credentials->apiBase !== null) {
            $config['api_base'] = $credentials->apiBase;
        }

        return new StripeClient($config);
    }
}
