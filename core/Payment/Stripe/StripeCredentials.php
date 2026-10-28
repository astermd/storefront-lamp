<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Stripe;

/**
 * The channel's Stripe credentials, as `theme:sync` copied them from the
 * EMR's `payment_processor.config`.
 *
 * Two keys with opposite rules. The secret key (`api_key`, an `sk_` or
 * restricted `rk_` key) creates and reads payments and must never leave this
 * process: it is private, redacted from every dump and refused by
 * `unserialize`, the same treatment {@see \AsterMD\Storefront\Payment\Vrio\VrioCredentials}
 * gives its key. The publishable key (`pk_`) exists to be put on the page --
 * Stripe's own script needs it to mount the card fields -- so it is public.
 *
 * `api_endpoint` is optional: the EMR stores Stripe's API origin with the
 * integration, and an empty one means Stripe's default.
 */
final class StripeCredentials implements \JsonSerializable
{
    public function __construct(
        private readonly string $secretKey,
        public readonly string $publishableKey,
        public readonly ?string $apiBase = null,
    ) {
    }

    /** @param array<string, mixed> $config the channel's `payment_processor.config` */
    public static function fromChannelConfig(array $config): self
    {
        $secret = trim((string) ($config['api_key'] ?? ''));
        if ($secret === '') {
            throw new \InvalidArgumentException('The payment processor configuration carries no api_key.');
        }

        $publishable = trim((string) ($config['publishable_key'] ?? ''));
        if ($publishable === '') {
            throw new \InvalidArgumentException('The payment processor configuration carries no publishable_key.');
        }

        $endpoint = rtrim(trim((string) ($config['api_endpoint'] ?? '')), '/');

        return new self($secret, $publishable, $endpoint === '' ? null : $endpoint);
    }

    /**
     * The secret key, for the one caller that builds the client.
     *
     * A method rather than a public property so it does not appear in an
     * object dump the way a promoted public readonly would.
     */
    public function secretKey(): string
    {
        return $this->secretKey;
    }

    /** @return array<string, ?string> */
    public function __debugInfo(): array
    {
        return ['publishableKey' => $this->publishableKey, 'apiBase' => $this->apiBase, 'secretKey' => '[REDACTED]'];
    }

    /** @return array<string, ?string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return array<string, ?string> */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Refuses to rebuild credentials from a serialised copy, for the reason
     * {@see \AsterMD\Storefront\Payment\Vrio\VrioCredentials::__unserialize()}
     * does: the copy has no key in it, and an object that looks complete and
     * authenticates with nothing fails at the first payment instead of here.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('StripeCredentials cannot be restored from a serialised copy: the secret key is deliberately not in it.');
    }
}
