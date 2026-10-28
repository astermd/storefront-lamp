<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Payment\Stripe;

use AsterMD\Storefront\Payment\Stripe\StripeCredentials;
use PHPUnit\Framework\TestCase;

final class StripeCredentialsTest extends TestCase
{
    public function testTheSyncedConfigBuildsCredentials(): void
    {
        $credentials = StripeCredentials::fromChannelConfig([
            'integration_name' => 'Stripe',
            'api_endpoint' => 'https://api.stripe.com/',
            'api_key' => 'sk_test_secret',
            'publishable_key' => 'pk_test_publishable',
        ]);

        self::assertSame('sk_test_secret', $credentials->secretKey());
        self::assertSame('pk_test_publishable', $credentials->publishableKey);
        self::assertSame('https://api.stripe.com', $credentials->apiBase);
    }

    public function testAnEmptyEndpointMeansStripesDefault(): void
    {
        $credentials = StripeCredentials::fromChannelConfig(['api_key' => 'sk_test_secret', 'publishable_key' => 'pk_test_publishable']);

        self::assertNull($credentials->apiBase);
    }

    public function testEitherKeyMissingIsRefusedAtConstruction(): void
    {
        foreach ([['publishable_key' => 'pk_test_x'], ['api_key' => 'sk_test_x']] as $config) {
            try {
                StripeCredentials::fromChannelConfig($config);
                self::fail('Credentials were built without both keys.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheSecretKeyNeverAppearsInADumpOrAnEncoding(): void
    {
        $credentials = new StripeCredentials('sk_test_secret', 'pk_test_publishable');

        ob_start();
        var_dump($credentials);
        self::assertStringNotContainsString('sk_test_secret', (string) ob_get_clean());
        self::assertStringNotContainsString('sk_test_secret', (string) json_encode($credentials));
        self::assertStringNotContainsString('sk_test_secret', serialize($credentials));
    }

    public function testASerialisedCopyCannotBeRestored(): void
    {
        $this->expectException(\LogicException::class);

        unserialize(serialize(new StripeCredentials('sk_test_secret', 'pk_test_publishable')));
    }
}
