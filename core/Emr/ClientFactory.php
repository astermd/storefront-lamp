<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Auth\FileTokenStore;
use AsterMD\Storefront\Support\Config;
use Psr\Http\Client\ClientInterface;

/**
 * Builds a configured {@see AsterMDClient} from app config and environment
 * credentials. Credentials (`ASTERMD_CLIENT_ID` / `ASTERMD_CLIENT_SECRET`)
 * are read from `$_ENV` rather than `Config` since they are secrets, never
 * committed alongside `config/app.php`. The token cache is file-backed so it
 * survives across CLI invocations and PHP-FPM worker requests.
 */
final class ClientFactory
{
    public function __construct(private readonly Config $config, private readonly string $rootDir)
    {
    }

    /**
     * Builds the SDK client. Pass `$httpClient` in tests to substitute a fake
     * PSR-18 transport; production callers omit it and get the SDK's default
     * cURL client.
     *
     * @throws \RuntimeException if `ASTERMD_CLIENT_ID` or `ASTERMD_CLIENT_SECRET` is blank
     */
    public function create(?ClientInterface $httpClient = null): AsterMDClient
    {
        $clientId = trim((string) ($_ENV['ASTERMD_CLIENT_ID'] ?? ''));
        $clientSecret = trim((string) ($_ENV['ASTERMD_CLIENT_SECRET'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException(
                'ASTERMD_CLIENT_ID and ASTERMD_CLIENT_SECRET must be set in the environment to create an EMR client.',
            );
        }

        $verbatim = $this->config->get('app.debug.wire_log') === true;

        return new AsterMDClient(
            clientId: $clientId,
            clientSecret: $clientSecret,
            baseHost: (string) $this->config->get('app.emr.base_host'),
            httpClient: $httpClient,
            tokenStore: new FileTokenStore($this->rootDir . '/storage/cache/emr-token.json'),
            // Off unless a deployment asks for it, and when asked for, asked
            // for whole: the point of this switch is reproducing a call by
            // hand, and a redacted transcript cannot be replayed. What it
            // costs is that live bearer tokens, the client secret and patient
            // bodies land in a file, so it is a local-debugging switch and
            // `config:validate` refuses to let it pass unnoticed.
            debug: $verbatim,
            debugFile: $verbatim ? $this->rootDir . '/storage/logs/emr-wire.log' : null,
            // Off on purpose: the redactor is what makes a transcript
            // unreplayable, and replaying the exact bytes is why this switch
            // exists at all. The cost is that the SDK's own PHI-path handling
            // -- which would otherwise drop the identity-verify body -- is
            // dropped with it, so this file spills identity documents as well
            // as cards while it is on. `config:validate` refuses to let that
            // pass unnoticed and names both (`[22.21]`).
            debugRedact: false,
        );
    }

    /**
     * Returns the configured EMR channel ID for this storefront.
     *
     * @throws \RuntimeException if `app.emr.channel_id` is not configured
     */
    public function channelId(): string
    {
        $channelId = $this->config->get('app.emr.channel_id');

        if (!is_string($channelId) || $channelId === '') {
            throw new \RuntimeException('app.emr.channel_id is not configured. Set ASTERMD_CHANNEL_ID in .env.');
        }

        return $channelId;
    }

    /**
     * The asset CDN serves media under a per-environment path segment
     * (`/ui/development/`, `/ui/staging/`, `/ui/production/`) that the SDK's
     * `assetUrl()` does not know about. This storefront's own `APP_ENV`
     * cannot stand in for it — a dev-mode storefront may still point at the
     * staging EMR (as in this sandbox) — so the segment is derived from
     * `app.emr.base_host` instead, which names the EMR environment actually
     * being synced from.
     */
    public function assetEnvironment(): string
    {
        $baseHost = (string) $this->config->get('app.emr.base_host');

        return match (true) {
            str_contains($baseHost, '.dev.') => 'development',
            str_contains($baseHost, '.staging.') => 'staging',
            default => 'production',
        };
    }
}
