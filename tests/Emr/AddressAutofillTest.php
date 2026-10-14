<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Emr;

use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Emr\EmrAddressAutofill;
use AsterMD\Storefront\Emr\NullAddressAutofill;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\FakeEmrHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The EMR's answers below are the shapes it gives: suggestions as formatted
 * strings with no ZIP and from any country, and a verifier whose
 * `formatted_address` carries the ZIP.
 */
final class AddressAutofillTest extends TestCase
{
    private const TOKEN_ROUTE = ['/v1/auth/api-credentials/token' => [200, [
        'success' => true,
        'data' => ['access_token' => 'test-token', 'access_token_expiry' => '2099-01-01T00:00:00.000Z'],
    ]]];

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['ASTERMD_CLIENT_ID', 'ASTERMD_CLIENT_SECRET'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['ASTERMD_CLIENT_ID'] = 'test-client-id';
        $_ENV['ASTERMD_CLIENT_SECRET'] = 'test-client-secret';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function autofill(FakeEmrHttpClient $http, ?CapturedLog $log = null): EmrAddressAutofill
    {
        $dir = sys_get_temp_dir() . '/emr-address-autofill-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/app.php', '<?php return ["emr" => ["base_host" => "sales.example.test", "channel_id" => "channel-123"]];');
        $root = sys_get_temp_dir() . '/emr-address-autofill-root-' . uniqid();
        mkdir($root);

        return new EmrAddressAutofill(new ClientFactory(Config::load($dir), $root), ($log ?? new CapturedLog())->log, $http);
    }

    public function testOnlyUsSuggestionsAreOffered(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/address-autofill' => [200, ['success' => true, 'data' => [
                ['unique_id' => 'a', 'address' => '1600 Amphitheatre Parkway, Mountain View, CA, USA', 'main_text' => '1600 Amphitheatre Parkway'],
                ['unique_id' => 'b', 'address' => '1600 Amphitheatre Road, Mount Lonarch VIC, Australia', 'main_text' => '1600 Amphitheatre Road'],
                ['unique_id' => 'c', 'address' => '1600 Amphitheatre Trail, Jaffrey, NH, USA', 'main_text' => '1600 Amphitheatre Trail'],
            ]]],
        ]);

        self::assertSame([
            ['address' => '1600 Amphitheatre Parkway, Mountain View, CA, USA', 'main_text' => '1600 Amphitheatre Parkway'],
            ['address' => '1600 Amphitheatre Trail, Jaffrey, NH, USA', 'main_text' => '1600 Amphitheatre Trail'],
        ], $this->autofill($http)->suggest('1600 Amphitheatre'));
    }

    public function testASuggestionIsResolvedThroughTheVerifierForItsZip(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/address-verify' => [200, ['success' => true, 'data' => [
                // Not "valid" -- no apartment -- and still exactly the address chosen.
                'valid' => false,
                'formatted_address' => '350 5th Avenue, New York, NY 10118-0110, USA',
            ]]],
        ]);

        self::assertSame(
            ['address_line' => '350 5th Avenue', 'city' => 'New York', 'territory' => 'NY', 'postal_code' => '10118'],
            $this->autofill($http)->resolve('350 5th Ave, New York, NY, USA'),
        );
    }

    public function testWithoutTheVerifierTheSuggestionItselfFillsAllButTheZip(): void
    {
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/address-verify' => [403, ['success' => false, 'message' => 'You do not have permission to perform this action.']],
        ]);

        self::assertSame(
            ['address_line' => '350 5th Ave', 'city' => 'New York', 'territory' => 'NY', 'postal_code' => ''],
            $this->autofill($http)->resolve('350 5th Ave, New York, NY, USA'),
        );
    }

    public function testARefusedLookupIsNullAndAnInfoLineThatNamesNoAddress(): void
    {
        $log = new CapturedLog();
        $http = new FakeEmrHttpClient(self::TOKEN_ROUTE + [
            '/extensions/address-autofill' => [403, ['success' => false, 'message' => 'You do not have permission to perform this action.']],
        ]);

        self::assertNull($this->autofill($http, $log)->suggest('350 5th Ave'));

        $line = $log->eventsNamed('address_autofill.unavailable')[0];
        self::assertSame('info', $line['level']);
        self::assertSame('autofill', $line['context']['call']);
        self::assertStringNotContainsString('350 5th', $log->contents());
    }

    public function testTheNullPortCanNeverAsk(): void
    {
        $autofill = new NullAddressAutofill();

        self::assertNull($autofill->suggest('350 5th Ave'));
        self::assertNull($autofill->resolve('350 5th Ave, New York, NY, USA'));
    }
}
