<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Vrio;

use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use PHPUnit\Framework\TestCase;

final class VrioCredentialsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function recordedConfig(): array
    {
        $raw = (string) file_get_contents(__DIR__ . '/../../fixtures/channel-payment-processor.json');

        /** @var array<string, mixed> $block */
        $block = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $config */
        $config = $block['config'];

        return $config;
    }

    public function testTheChannelsFullUrlIsSplitIntoTheBareHostTheClientDemands(): void
    {
        // Recorded live: the client throws "The host must be a bare hostname"
        // on the exact api_endpoint value the channel supplies. Splitting it is
        // capability 2's whole job, and getting it wrong fails on the first
        // real order rather than at boot.
        $credentials = VrioCredentials::fromChannelConfig($this->recordedConfig());

        self::assertSame('api.vrio.app', $credentials->host);
        self::assertSame('', $credentials->basePath);
    }

    public function testAPathOnTheEndpointBecomesTheBasePath(): void
    {
        $credentials = VrioCredentials::fromChannelConfig([
            'api_endpoint' => 'https://api.vrio.app/v1',
            'api_key' => 'k',
            'connection_id' => '1',
            'campaign_id' => '147',
        ]);

        self::assertSame('api.vrio.app', $credentials->host);
        self::assertSame('v1', $credentials->basePath);
    }

    public function testABareHostIsAcceptedUnchanged(): void
    {
        $credentials = VrioCredentials::fromChannelConfig([
            'api_endpoint' => 'api.vrio.app',
            'api_key' => 'k',
            'connection_id' => '1',
            'campaign_id' => '147',
        ]);

        self::assertSame('api.vrio.app', $credentials->host);
    }

    public function testTheRoutingHintsAreCarriedThroughAsIntegers(): void
    {
        // The channel stores them as strings; the provider's own example sends
        // integers for campaign_id and connection_id. Both are accepted, but
        // one shape has to be chosen and pinned.
        $credentials = VrioCredentials::fromChannelConfig($this->recordedConfig());

        self::assertSame(147, $credentials->campaignId);
        self::assertSame(1, $credentials->connectionId);
    }

    public function testAMissingApiKeyIsRejectedAtConstructionRatherThanAtCheckout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VrioCredentials::fromChannelConfig(['api_endpoint' => 'https://api.vrio.app', 'campaign_id' => '1', 'connection_id' => '1']);
    }

    public function testTheApiKeyIsNeverExposedByADebugDump(): void
    {
        $credentials = VrioCredentials::fromChannelConfig($this->recordedConfig());

        self::assertStringNotContainsString('test-api-key-placeholder', print_r($credentials, true));
    }

    public function testTheApiKeyIsNeverExposedByAnEncoderOrASerialiser(): void
    {
        // A private property keeps the key out of json_encode by accident
        // rather than by decision, and serialize() reaches private properties
        // regardless. Both are stated outright here.
        $credentials = new VrioCredentials('api.vrio.app', '', 'test-api-key-placeholder', 147, 1);

        self::assertStringNotContainsString('test-api-key-placeholder', (string) json_encode($credentials));
        self::assertStringNotContainsString('test-api-key-placeholder', serialize($credentials));
    }

    public function testARoundTripThroughSerialisationIsRefusedRatherThanQuietlyEmptied(): void
    {
        // Restoring a keyless credentials object would fail at the first call
        // with a provider-side authentication error, days from the code that
        // caused it.
        $this->expectException(\LogicException::class);

        unserialize(serialize(new VrioCredentials('api.vrio.app', '', 'k', 147, 1)));
    }
}
