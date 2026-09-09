<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Journey;

use AsterMD\Storefront\Support\Config;

/**
 * The session and attribution settings, read from config once and passed as
 * one value instead of six constructor arguments.
 *
 * The tracking keys are ordered: the current key first, then any previous
 * key still inside its rotation overlap window (`[5.2b]`). Blank env slots
 * are dropped so an unconfigured deployment simply never decrypts a payload
 * and falls back to plain parameters.
 */
final class SessionOptions
{
    /**
     * @param list<string> $trackingKeys
     * @param list<string> $excludePaths
     */
    public function __construct(
        public readonly string $cookieName = 'amd_session',
        public readonly string $resumeParam = 'amd_session',
        public readonly string $payloadParam = '_amd',
        public readonly int $lifetimeDays = 30,
        public readonly array $trackingKeys = [],
        public readonly array $excludePaths = [],
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $keys = [];
        foreach (['app.attribution.key', 'app.attribution.previous_key'] as $configKey) {
            $key = trim((string) $config->get($configKey, ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        /** @var list<string> $excludePaths */
        $excludePaths = array_values((array) $config->get('app.session.exclude_paths', []));

        return new self(
            cookieName: (string) $config->get('app.session.cookie', 'amd_session'),
            resumeParam: (string) $config->get('app.session.resume_param', 'amd_session'),
            payloadParam: (string) $config->get('app.attribution.payload_param', '_amd'),
            lifetimeDays: (int) $config->get('app.session.lifetime_days', 30),
            trackingKeys: $keys,
            excludePaths: $excludePaths,
        );
    }
}
