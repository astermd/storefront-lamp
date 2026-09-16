<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\CheckoutChamp;

use AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampCredentials;
use PHPUnit\Framework\TestCase;

final class CheckoutChampCredentialsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return $overrides + [
            'api_endpoint' => 'https://api.checkoutchamp.com',
            'api_username' => 'store_api',
            'api_password' => 'a-live-password',
        ];
    }

    public function testTheStoredUrlBecomesTheBareHostTheClientDemands(): void
    {
        // The channel stores a full URL and the client rejects anything that is
        // not a bare hostname, so the split happens here once rather than at
        // every call site.
        $credentials = CheckoutChampCredentials::fromChannelConfig($this->config());

        self::assertSame('api.checkoutchamp.com', $credentials->host);
        self::assertSame('', $credentials->basePath);
    }

    public function testABareHostnameIsAcceptedAsWell(): void
    {
        // parse_url only finds a host when a scheme is present, so a bare one
        // has to be recognised before parsing rather than after.
        $credentials = CheckoutChampCredentials::fromChannelConfig($this->config(['api_endpoint' => 'api.checkoutchamp.com']));

        self::assertSame('api.checkoutchamp.com', $credentials->host);
    }

    public function testAPathOnTheEndpointBecomesTheBasePath(): void
    {
        $credentials = CheckoutChampCredentials::fromChannelConfig($this->config(['api_endpoint' => 'https://api.checkoutchamp.com/v2/']));

        self::assertSame('v2', $credentials->basePath);
    }

    public function testHalfACredentialPairIsRefusedAtConstructionRatherThanAtTheFirstOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CheckoutChampCredentials::fromChannelConfig($this->config(['api_password' => '']));
    }

    public function testAnUnusableEndpointIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CheckoutChampCredentials::fromChannelConfig($this->config(['api_endpoint' => '']));
    }

    public function testTheCampaignComesFromTheDeploymentBecauseTheChannelHasNone(): void
    {
        $credentials = CheckoutChampCredentials::fromChannelConfig($this->config(), '9');

        self::assertSame('9', $credentials->campaignId);
    }

    public function testAHandAddedProcessorCampaignIsAFallbackAndNotAnOverride(): void
    {
        // A deployment that set it in the processor block before it had a
        // configured home still works; a deployment that sets both gets the
        // configured one, because that is the value `config:validate` checks.
        $fromBlock = CheckoutChampCredentials::fromChannelConfig($this->config(['campaign_id' => '11']));
        $configured = CheckoutChampCredentials::fromChannelConfig($this->config(['campaign_id' => '11']), '9');

        self::assertSame('11', $fromBlock->campaignId);
        self::assertSame('9', $configured->campaignId);
    }

    public function testAnAbsentCampaignIsEmptyRatherThanGuessed(): void
    {
        self::assertSame('', CheckoutChampCredentials::fromChannelConfig($this->config())->campaignId);
    }

    public function testThePasswordNeverRendersInADebugDump(): void
    {
        // print_r, var_dump, a Throwable render and a container dump all go
        // through __debugInfo. This provider's secret is a password that also
        // travels in the URL, so it is the one most likely to end up somewhere
        // it was never meant to be.
        $dump = print_r(CheckoutChampCredentials::fromChannelConfig($this->config()), true);

        self::assertStringNotContainsString('a-live-password', $dump);
        self::assertStringContainsString('[REDACTED]', $dump);
    }

    public function testThePasswordNeverRendersInJsonOrASerialisedCopy(): void
    {
        // serialize reaches private properties, so json alone is not enough.
        $credentials = CheckoutChampCredentials::fromChannelConfig($this->config());

        self::assertStringNotContainsString('a-live-password', (string) json_encode($credentials));
        self::assertStringNotContainsString('a-live-password', serialize($credentials));
    }

    public function testTheLoginIdIsKeptBecauseItIsAnAccountNameRatherThanASecret(): void
    {
        // An operator reading a log needs to know which account answered.
        self::assertStringContainsString('store_api', (string) json_encode(CheckoutChampCredentials::fromChannelConfig($this->config())));
    }

    public function testCredentialsRefuseToComeBackFromASerialisedCopy(): void
    {
        // Restoring the redacted shape would produce an object that looks
        // complete and authenticates with nothing, so the failure would arrive
        // as a provider rejection at the first order rather than here.
        $this->expectException(\LogicException::class);

        unserialize(serialize(CheckoutChampCredentials::fromChannelConfig($this->config())));
    }

    public function testThePasswordIsStillReachableByTheOneCallerThatBuildsTheClient(): void
    {
        self::assertSame('a-live-password', CheckoutChampCredentials::fromChannelConfig($this->config())->password());
    }
}
