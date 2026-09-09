<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment;

use AsterMD\Storefront\Support\OperatorLog;

/**
 * Provider category → adapter (`[14.2]`; `docs/ARCHITECTURE.md`, "Payment").
 *
 * Adapters are registered as factories rather than instances so that building
 * one -- which reads credentials and constructs an HTTP client -- happens only
 * for the category actually configured, and only when something asks. That is
 * a hard requirement rather than an optimisation: a page that never reaches
 * checkout must not pay for a provider client, and a misconfigured provider
 * must not break the home page.
 *
 * An unconfigured or unknown category resolves to {@see NullPaymentAdapter}
 * and is logged once, rather than throwing: a channel whose payment processor
 * has not been synced yet should still serve its catalog (`[20.1]`).
 */
final class AdapterRegistry
{
    /** @var array<string, \Closure(): PaymentAdapter> */
    private array $factories = [];

    /** @var array<string, PaymentAdapter> */
    private array $resolved = [];

    public function __construct(private readonly OperatorLog $log)
    {
    }

    /** @param \Closure(): PaymentAdapter $factory */
    public function register(string $providerCategory, \Closure $factory): void
    {
        $this->factories[strtolower(trim($providerCategory))] = $factory;
    }

    public function has(string $providerCategory): bool
    {
        return isset($this->factories[strtolower(trim($providerCategory))]);
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_keys($this->factories);
    }

    public function for(?string $providerCategory): PaymentAdapter
    {
        $key = strtolower(trim((string) $providerCategory));

        if ($key === '' || !isset($this->factories[$key])) {
            $this->log->warning('payment.adapter_unavailable', ['category' => $key === '' ? null : $key]);

            return new NullPaymentAdapter();
        }

        if (!isset($this->resolved[$key])) {
            try {
                $this->resolved[$key] = ($this->factories[$key])();
            } catch (\Throwable $e) {
                $this->log->error('payment.adapter_build_failed', ['category' => $key, 'reason' => $e->getMessage()]);

                return new NullPaymentAdapter();
            }
        }

        return $this->resolved[$key];
    }
}
