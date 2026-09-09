<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see VerificationGateway} over the SDK's `verification()` resource.
 *
 * Bound only when `features.emr_verification` is on, because on this
 * deployment the whole resource is refused: `verifyEmail` and `verifyAddress`
 * both answer 403 "You do not have permission to perform this action" against
 * a token minted moments earlier. That is why every failure here is an
 * **info** line rather than a warning — a refusal is the expected state until
 * the grant lands, and an alert an operator is told to expect is an alert they
 * learn to ignore (`[20.10]`). It is also why every `\Throwable` is caught: a
 * verification outage must degrade the form, never the order (`[20.1]`).
 *
 * Nothing about the buyer reaches the log. The email being checked is the
 * argument, and an API error message can quote its own input, so the log line
 * carries the exception class and the status code and not the message —
 * the same shape {@see \AsterMD\Storefront\Forms\EmrLeadGateway} uses for
 * exactly this reason.
 *
 * The client is built on first use rather than in the constructor, so a
 * deployment with the flag on and credentials missing still renders checkout.
 */
final class EmrVerificationGateway implements VerificationGateway
{
    /** The provider's verdict for a deliverable address. */
    private const string EMAIL_VALID = 'valid';

    /** The provider's verdict for an undeliverable one; anything else — `unknown` included — is inconclusive. */
    private const string EMAIL_INVALID = 'invalid';

    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * Whether the provider believes mail sent here would arrive.
     *
     * The SDK documents three verdicts and is explicit that `unknown` also
     * covers the provider being rate-limited, so it is mapped to null — "could
     * not determine" — rather than to false. A buyer whose provider was busy
     * must not be told their email address is wrong.
     */
    public function emailIsDeliverable(string $email): ?bool
    {
        try {
            $result = $this->client()->verification()->verifyEmail($email)->data()['result'] ?? null;
        } catch (\Throwable $e) {
            $this->unavailable('email', $e);

            return null;
        }

        return match (is_string($result) ? strtolower(trim($result)) : '') {
            self::EMAIL_VALID => true,
            self::EMAIL_INVALID => false,
            default => null,
        };
    }

    /**
     * The provider's normalised form of an address, for offering back to the
     * buyer as a correction.
     *
     * An address the provider rejects returns null alongside one it could not
     * look at, because the return shape has no third state to say them apart
     * and neither may block an order. What this method is for is the case it
     * can express: a real address, spelled the way the carrier spells it.
     *
     * @return array<string, string>|null
     */
    public function normaliseAddress(string $address): ?array
    {
        try {
            $data = $this->client()->verification()->verifyAddress($address)->data();
        } catch (\Throwable $e) {
            $this->unavailable('address', $e);

            return null;
        }

        $formatted = $data['formatted_address'] ?? null;

        if (($data['valid'] ?? null) !== true || !is_string($formatted) || trim($formatted) === '') {
            return null;
        }

        return ['formatted_address' => trim($formatted)];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /** Records a check that could not be run, naming which one and why, and never what was checked. */
    private function unavailable(string $check, \Throwable $e): void
    {
        $this->log->info('verification.unavailable', [
            'check' => $check,
            'exception' => $e::class,
            'status' => $e->getCode(),
        ]);
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
