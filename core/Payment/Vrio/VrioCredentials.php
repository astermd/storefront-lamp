<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

/**
 * Capability 2: the channel's `payment_processor.config` as this provider's
 * client needs it.
 *
 * The one real transformation is the endpoint. The channel stores a full URL
 * (`https://api.vrio.app`), and the client rejects anything that is not a bare
 * hostname -- verified against the live channel, where passing the stored value
 * straight through throws before a single order can be placed. So the URL is
 * split here, once, rather than at every call site.
 *
 * `campaign_id` and `connection_id` are `[14.6c]` routing hints: opaque
 * provider-side identifiers deciding which campaign and merchant router an
 * order is placed under. The storefront passes them through and never
 * interprets them.
 *
 * **What is protected, exactly.** The API key is a private property, which
 * keeps it out of `json_encode` on its own; `jsonSerialize()` is declared
 * anyway so that stays a decision rather than a side effect of a keyword.
 * `var_dump` and `print_r` go through `__debugInfo()`, and `serialize` through
 * `__serialize()` -- `serialize` reaches private properties, so the key was
 * plainly in the output before. `var_export` cannot be intercepted for a plain
 * object and still prints the key; nothing here calls it.
 */
final class VrioCredentials implements \JsonSerializable
{
    public function __construct(
        public readonly string $host,
        public readonly string $basePath,
        private readonly string $apiKey,
        public readonly int $campaignId,
        public readonly int $connectionId,
    ) {
    }

    /** @param array<string, mixed> $config the channel's `payment_processor.config` */
    public static function fromChannelConfig(array $config): self
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \InvalidArgumentException('The payment processor configuration carries no api_key.');
        }

        $endpoint = trim((string) ($config['api_endpoint'] ?? ''));
        [$host, $basePath] = self::splitEndpoint($endpoint);

        if ($host === '') {
            throw new \InvalidArgumentException('The payment processor configuration carries no usable api_endpoint.');
        }

        return new self(
            host: $host,
            basePath: $basePath,
            apiKey: $apiKey,
            campaignId: (int) ($config['campaign_id'] ?? 0),
            connectionId: (int) ($config['connection_id'] ?? 0),
        );
    }

    /**
     * The key, for the one caller that builds the client.
     *
     * A method rather than a public property so it does not appear in an
     * object dump the way a promoted public readonly would.
     */
    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'apiKey' => '[REDACTED]'];
    }

    /**
     * The routing hints an encoder can safely have, and never the key.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'host' => $this->host,
            'basePath' => $this->basePath,
            'campaignId' => $this->campaignId,
            'connectionId' => $this->connectionId,
            'apiKey' => '[REDACTED]',
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
     * Credentials are built from channel configuration, once, by
     * {@see self::fromChannelConfig()}; nothing else has any business making one.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('VrioCredentials cannot be restored from a serialised copy: the API key is deliberately not in it.');
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
