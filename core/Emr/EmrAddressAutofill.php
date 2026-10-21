<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Storefront\Checkout\FormattedAddress;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see AddressAutofill} over the SDK's `verification()` resource.
 *
 * **Suggestions are not restricted to the US by the EMR**, and are biased
 * toward wherever the request comes from -- which is this server, not the
 * buyer. A partial street answers matches in Australia and Nigeria alongside
 * American ones, so only the suggestions ending in the US are kept, and the
 * buyer narrows the rest by typing on.
 *
 * **A suggestion carries no ZIP.** It is `350 5th Ave, New York, NY, USA`, and
 * the SDK has no call that resolves its place id into components. Resolving
 * therefore asks the address verifier, whose `formatted_address` does carry
 * one -- `350 5th Avenue, New York, NY 10118-0110, USA` -- and reads the fields
 * out of that. The verifier's `valid` flag is deliberately ignored: it judges
 * whether the address is complete enough to deliver to, which a street address
 * with no apartment can fail while still being exactly the address chosen.
 * When the verifier cannot answer, the suggestion's own text is read instead,
 * which fills everything but the ZIP.
 *
 * Failures are info lines that name the call and never the address, for the
 * reasons {@see EmrVerificationGateway} gives: an outage here is a missing
 * convenience, and an API error can quote its input.
 */
final class EmrAddressAutofill implements AddressAutofill
{
    private const int MAX_SUGGESTIONS = 5;

    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function suggest(string $search): ?array
    {
        try {
            $data = $this->client()->verification()->autofillAddress($search)->data();
        } catch (\Throwable $e) {
            $this->unavailable('autofill', $e);

            return null;
        }

        $suggestions = [];

        foreach ($data as $item) {
            $address = is_array($item) ? ($item['address'] ?? null) : null;

            if (!is_string($address) || !FormattedAddress::isUnitedStates($address)) {
                continue;
            }

            $main = $item['main_text'] ?? null;
            $suggestions[] = [
                'address' => trim($address),
                'main_text' => is_string($main) && trim($main) !== '' ? trim($main) : trim($address),
            ];

            if (count($suggestions) === self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }

    public function resolve(string $address): ?array
    {
        try {
            $formatted = $this->client()->verification()->verifyAddress($address)->data()['formatted_address'] ?? null;
        } catch (\Throwable $e) {
            $this->unavailable('verify', $e);
            $formatted = null;
        }

        $parsed = is_string($formatted) ? FormattedAddress::parse($formatted) : null;

        return $parsed ?? FormattedAddress::parse($address);
    }

    /** Records a lookup that could not run, naming which one and why, and never what was looked up. */
    private function unavailable(string $call, \Throwable $e): void
    {
        $this->log->info('address_autofill.unavailable', [
            'call' => $call,
            'exception' => $e::class,
            'status' => $e->getCode(),
        ]);
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
