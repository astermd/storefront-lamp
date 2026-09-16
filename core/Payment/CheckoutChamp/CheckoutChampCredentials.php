<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\CheckoutChamp;

/**
 * Capability 2: the channel's `payment_processor.config` as this provider's
 * client needs it.
 *
 * **This provider authenticates with a password, and sends it in the query
 * string.** That is the provider's own design and the client follows it, which
 * makes the secret here categorically more exposed than an `Authorization`
 * header would be: anything between this application and the internet that
 * records outbound request URLs -- a forward proxy, an egress gateway, an APM
 * agent, a TLS-inspecting appliance -- records it in clear. The consequence for
 * this class is that the password gets the same private-property treatment the
 * other adapter's API key gets; the consequence for a deployment is declared in
 * {@see CheckoutChampAdapter::capabilities()}'s PCI posture rather than left
 * for someone to discover.
 *
 * The endpoint is split for the reason the other adapter splits its own: the
 * channel stores a full URL (`https://api.checkoutchamp.com`) and the client
 * rejects anything that is not a bare hostname. Splitting here means one place
 * rather than every call site.
 *
 * `campaignId` is a `[14.6c]` routing hint -- an opaque provider-side
 * identifier deciding which campaign an order is placed under. **The EMR
 * channel payload does not carry one**, which is the one value a deployment has
 * to supply by hand; `config:validate` reports its absence rather than letting
 * every checkout fail at the provider with "No products exist in the order".
 */
final class CheckoutChampCredentials implements \JsonSerializable
{
    public function __construct(
        public readonly string $host,
        public readonly string $basePath,
        public readonly string $loginId,
        private readonly string $password,
        public readonly string $campaignId,
    ) {
    }

    /**
     * @param array<string, mixed> $config     the channel's `payment_processor.config`
     * @param string               $campaignId the deployment's own routing hint, since the channel carries none
     */
    public static function fromChannelConfig(array $config, string $campaignId = ''): self
    {
        $loginId = trim((string) ($config['api_username'] ?? ''));
        $password = trim((string) ($config['api_password'] ?? ''));

        if ($loginId === '' || $password === '') {
            throw new \InvalidArgumentException('The payment processor configuration carries no api_username/api_password pair.');
        }

        [$host, $basePath] = self::splitEndpoint(trim((string) ($config['api_endpoint'] ?? '')));

        if ($host === '') {
            throw new \InvalidArgumentException('The payment processor configuration carries no usable api_endpoint.');
        }

        // The channel has no campaign, so a deployment sets it in
        // `config/payment.php`. The processor block is read only as a fallback,
        // for a deployment whose operator hand-added it there before this took
        // its configured home.
        $campaignId = trim($campaignId) !== ''
            ? trim($campaignId)
            : trim((string) ($config['campaign_id'] ?? ''));

        return new self($host, $basePath, $loginId, $password, $campaignId);
    }

    /**
     * The password, for the one caller that builds the client.
     *
     * A method rather than a public property so it does not appear in an object
     * dump the way a promoted public readonly would.
     */
    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'loginId' => $this->loginId, 'password' => '[REDACTED]'];
    }

    /**
     * The routing hints an encoder can safely have, and never the password.
     *
     * The login id is kept: it is an account name rather than a secret, and an
     * operator reading a log needs to know which account answered.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'basePath' => $this->basePath,
            'loginId' => $this->loginId,
            'campaignId' => $this->campaignId,
            'password' => '[REDACTED]',
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * Refuses to rebuild credentials from a serialised copy.
     *
     * Restoring the redacted shape would produce an object that looks complete
     * and authenticates with nothing, so the failure would arrive as a provider
     * rejection at the first order rather than at the line that caused it.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('CheckoutChampCredentials cannot be restored from a serialised copy: the password is deliberately not in it.');
    }

    /** @return array{0: string, 1: string} host, basePath */
    private static function splitEndpoint(string $endpoint): array
    {
        if ($endpoint === '') {
            return ['', ''];
        }

        // parse_url only finds a host when a scheme is present, so a bare
        // hostname has to be recognised before parsing rather than after.
        if (!str_contains($endpoint, '://')) {
            $endpoint = 'https://' . $endpoint;
        }

        $parts = parse_url($endpoint);

        return [
            is_string($parts['host'] ?? null) ? $parts['host'] : '',
            trim(is_string($parts['path'] ?? null) ? $parts['path'] : '', '/'),
        ];
    }
}
