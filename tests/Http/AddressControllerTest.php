<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Emr\AddressAutofill;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AddressControllerTest extends TestCase
{
    use TempDatabase;

    /** @var list<string> */
    private array $asked = [];

    private function autofill(): AddressAutofill
    {
        $asked = &$this->asked;

        return new class ($asked) implements AddressAutofill {
            /** @param list<string> $asked */
            public function __construct(private array &$asked)
            {
            }

            public function suggest(string $search): ?array
            {
                $this->asked[] = $search;

                return [['address' => '350 5th Ave, New York, NY, USA', 'main_text' => '350 5th Ave']];
            }

            public function resolve(string $address): ?array
            {
                $this->asked[] = $address;

                return ['address_line' => '350 5th Avenue', 'city' => 'New York', 'territory' => 'NY', 'postal_code' => '10118'];
            }
        };
    }

    private function get(string $path, int $limit = 100): ResponseInterface
    {
        $pdo = $this->tempPdo();

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $pdo,
            AddressAutofill::class => $this->autofill(),
            DatabaseRateLimiter::class => new DatabaseRateLimiter(
                new RateLimitRepository(static fn (): \PDO => $pdo, 'sqlite'),
                ['checkout.address' => ['limit' => $limit, 'window_seconds' => 300]],
                (new CapturedLog())->log,
            ),
        ])->handle((new ServerRequestFactory())->createServerRequest('GET', $path, ['REMOTE_ADDR' => '203.0.113.7']));
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testSuggestionsAreJsonTheBrowserNeverCaches(): void
    {
        $response = $this->get('/checkout/address/suggest/?q=350+5th');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('350 5th Ave', self::body($response)['suggestions'][0]['main_text']);
        self::assertSame(['350 5th'], $this->asked);
    }

    public function testAResolvedAddressIsTheFourCheckoutFields(): void
    {
        $body = self::body($this->get('/checkout/address/resolve/?address=' . rawurlencode('350 5th Ave, New York, NY, USA')));

        self::assertSame(
            ['address_line' => '350 5th Avenue', 'city' => 'New York', 'territory' => 'NY', 'postal_code' => '10118'],
            $body['address'],
        );
    }

    public function testATooShortSearchSpendsNoCall(): void
    {
        $body = self::body($this->get('/checkout/address/suggest/?q=35'));

        self::assertSame([], $body['suggestions']);
        self::assertSame([], $this->asked);
    }

    public function testASpentBucketIsRefusedWithoutAsking(): void
    {
        $response = $this->get('/checkout/address/suggest/?q=350+5th', limit: 0);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([], self::body($response)['suggestions']);
        self::assertSame([], $this->asked);
    }
}
