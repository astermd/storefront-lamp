<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioWireLog;
use AsterMD\VrioClient\Http\HttpClientInterface;
use AsterMD\VrioClient\Http\Request;
use AsterMD\VrioClient\Http\Response;
use AsterMD\Storefront\Bootstrap\AppFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The verbatim wire log, and the three guarantees that make it safe to ship.
 *
 * This is the one place in the application that deliberately does not scrub a
 * card: while it is on, `storage/logs/` holds primary account numbers, security
 * codes, live bearer tokens and the OAuth client secret in clear text. That is
 * cardholder data at rest, which `[15.8]` forbids the application's own storage
 * from holding, and it is only defensible because it is off unless a deployment
 * asks for it and cannot be left on unnoticed.
 *
 * Those are exactly the properties nothing asserted when the feature landed.
 * Three mutations survived the whole suite: making the validator's check never
 * fire, and forcing either log on unconditionally — the last of which would have
 * written real card numbers to disk on every checkout with 1339 tests green.
 * A switch whose guard rail is only a comment is not a guard rail.
 */
final class WireLogTest extends TestCase
{
    private const string PAN = '4111111100084444';

    /** Distinct from every other digit run in this fixture, so a containment check cannot pass by luck. */
    private const string SECURITY_CODE = '806';

    public function testTheShippedDefaultIsOff(): void
    {
        // Asserted against an environment this test controls, never against the
        // developer's own `.env` — a machine that happens to have the switch on
        // would otherwise turn this guarantee into a test that fails for the
        // right reason at the wrong time, and a machine that happens to have it
        // off would prove nothing about the default.
        $shipped = \AsterMD\Storefront\Support\Config::load(dirname(__DIR__, 2) . '/config', []);

        self::assertFalse($shipped->get('app.debug.wire_log'), 'the shipped default must be off');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function offValues(): iterable
    {
        yield 'unset' => [''];
        yield 'false' => ['false'];
        yield 'zero' => ['0'];
        yield 'nonsense' => ['yes-please'];
    }

    /**
     * Only an explicit truthy value turns it on: `filter_var` with
     * `FILTER_VALIDATE_BOOL` returns false for anything it does not recognise,
     * and every read of this flag is a strict `=== true`, so the three of them
     * cannot disagree about a value one of them coerced differently.
     *
     */
    #[DataProvider('offValues')]
    public function testOnlyAnExplicitTruthyValueTurnsItOn(string $value): void
    {
        $config = \AsterMD\Storefront\Support\Config::load(
            dirname(__DIR__, 2) . '/config',
            $value === '' ? [] : ['WIRE_LOG' => $value],
        );

        self::assertNotTrue($config->get('app.debug.wire_log'));
    }

    /**
     * The wiring, not the flag.
     *
     * Reading the configuration back proves only that a key holds false; what
     * matters is that nothing decorates the transport. Forcing either binding on
     * unconditionally left the whole suite green — which is to say the switch's
     * only real guarantee was untested — so these two reach into the built
     * container and look at what was actually constructed.
     */
    /**
     * The question is whether anything is *logging* the traffic, not whether
     * the transport slot is empty.
     *
     * Under the test environment that slot holds
     * {@see \AsterMD\Storefront\Payment\RefusingTransport}, which fences the
     * suite off from the live provider — so asserting the slot is null would
     * assert the fence away. What must stay true is narrower and is the actual
     * guarantee: no transcript is written unless the switch is on.
     */
    public function testTheProviderTransportIsUndecoratedUnlessTheSwitchIsOn(): void
    {
        self::assertNotInstanceOf(
            VrioWireLog::class,
            $this->providerTransport([]),
            'off: nothing is writing a provider transcript, which carries primary account numbers',
        );
        self::assertInstanceOf(VrioWireLog::class, $this->providerTransport(['WIRE_LOG' => 'true']));
    }

    public function testTheEmrClientIsUndecoratedUnlessTheSwitchIsOn(): void
    {
        self::assertNotInstanceOf(
            \AsterMD\Sdk\Http\CurlLoggingClient::class,
            $this->emrHttpClient([]),
            'off: nothing is logging EMR traffic, which carries live bearer tokens',
        );
        self::assertInstanceOf(
            \AsterMD\Sdk\Http\CurlLoggingClient::class,
            $this->emrHttpClient(['WIRE_LOG' => 'true']),
        );
    }

    /** @param array<string, string> $env */
    private function providerTransport(array $env): ?HttpClientInterface
    {
        $adapter = self::unwrap($this->containerFor($env)->get(PaymentAdapter::class), 'apiFactory');
        $factory = (new \ReflectionProperty($adapter, 'apiFactory'))->getValue($adapter);

        return (new \ReflectionProperty($factory, 'transport'))->getValue($factory);
    }

    /**
     * The adapter itself, with any decorators the container wrapped it in
     * peeled off.
     *
     * The container hands out a decorated port — observability wraps the
     * payment adapter to record outcome and latency — and this test is about
     * what the *innermost* adapter is holding, because that is where the wire
     * log would attach. Peeling by looking for the property rather than by
     * naming a decorator class keeps the assertion honest through the next
     * decorator somebody adds: a wrapper this cannot see through fails the
     * test loudly instead of quietly finding nothing to inspect.
     *
     * A missing `inner` at the end of the chain is deliberately allowed to
     * throw rather than returning null, because a silent null here would turn
     * the strongest guarantee in this file into an assertion about nothing.
     */
    private static function unwrap(object $port, string $property): object
    {
        $seen = 0;
        while (!property_exists($port, $property)) {
            $port = (new \ReflectionProperty($port, 'inner'))->getValue($port);
            self::assertIsObject($port, 'a decorator chain that does not end in an object');
            self::assertLessThan(10, ++$seen, 'the decorator chain does not terminate');
        }

        return $port;
    }

    /** @param array<string, string> $env */
    private function emrHttpClient(array $env): object
    {
        $client = $this->containerFor($env)->get(\AsterMD\Storefront\Emr\ClientFactory::class)->create();
        $transport = (new \ReflectionProperty($client, 'transport'))->getValue($client);

        return (new \ReflectionProperty($transport, 'httpClient'))->getValue($transport);
    }

    /**
     * The real container, over an environment this test controls.
     *
     * `channel.generated.php` has to exist for a provider adapter to resolve at
     * all, so a deployment that has never synced cannot answer the provider half
     * of this question — it is skipped rather than asserted vacuously.
     *
     * @param array<string, string> $env
     */
    private function containerFor(array $env): \Psr\Container\ContainerInterface
    {
        $root = dirname(__DIR__, 2);

        if (!is_file($root . '/config/channel.generated.php')) {
            self::markTestSkipped('needs a synced channel for a provider adapter to resolve');
        }

        // Set explicitly rather than unset. `AppFactory::create()` loads the
        // deployment's own `.env` through an *immutable* Dotenv, which will not
        // overwrite a value already in `$_ENV` but will happily supply one that
        // is absent — so unsetting the key would hand the machine's own
        // configuration back and make the off case pass or fail according to
        // whoever ran it.
        $restore = $_ENV['WIRE_LOG'] ?? null;
        $_ENV['WIRE_LOG'] = $env['WIRE_LOG'] ?? 'false';

        try {
            $container = AppFactory::create($root, [\PDO::class => new \PDO('sqlite::memory:')])->getContainer();
        } finally {
            if ($restore === null) {
                unset($_ENV['WIRE_LOG']);
            } else {
                $_ENV['WIRE_LOG'] = $restore;
            }
        }

        self::assertNotNull($container);

        return $container;
    }

    public function testTheEmrLogIsGenuinelyUnredactedWhichIsWhyItIsGuardedSoHeavily(): void
    {
        // The feature's whole purpose, and the reason every guarantee above
        // matters. The SDK attaches a redactor by default that strips bearer
        // tokens, the client secret and patient bodies; this deployment passes
        // `debugRedact: false` deliberately, because a redacted transcript
        // cannot be replayed by hand and replaying it is the only reason to
        // switch this on.
        //
        // Re-enabling the redactor is a change that would look like a security
        // improvement and would instead quietly break the feature while leaving
        // every other assertion here green.
        $client = $this->emrHttpClient(['WIRE_LOG' => 'true']);

        self::assertNull(
            (new \ReflectionProperty($client, 'redactor'))->getValue($client),
            'the EMR transcript is verbatim, which is exactly why it may never ship on',
        );
    }
    public function testTheConfigurationValidatorRefusesADeploymentWithItOn(): void
    {
        // An error rather than a warning: a warning is something an operator
        // learns to scroll past, and this is the one switch that should stop a
        // release. Asserted through the real command so the check cannot be
        // moved somewhere it never runs — which is where it started, behind a
        // payment pass that returns early on half the deployments that could
        // have it on.
        $output = $this->validateWith(['WIRE_LOG' => 'true']);

        self::assertSame(2, $output['exit'], 'a deployment with it on does not pass validation');
        self::assertStringContainsString('wire_log is ON', $output['text']);
    }

    /**
     * The refusal has to name identity documents, not only cards and tokens.
     *
     * `[22.21]` forbids identity documents reaching a debug log outright, and
     * this switch puts them there: the SDK marks the identity-verify path as
     * PHI and would drop its body, but only through a redactor this
     * application deliberately does not install, because a redacted transcript
     * cannot be replayed by hand. The flag is all or nothing, so turning the
     * wire log on turns the PHI suppression off with it.
     *
     * Asserted on the wording because the wording is the whole control. An
     * operator who reads "card numbers and bearer tokens" on a deployment that
     * takes no payments concludes the switch is harmless to them, and turns it
     * on next to a Social Security Number.
     */
    public function testTheRefusalNamesTheIdentityDocumentsItAlsoSpills(): void
    {
        $output = $this->validateWith(['WIRE_LOG' => 'true']);

        self::assertStringContainsString('Social Security Numbers', $output['text']);
        self::assertStringContainsString('dates of birth', $output['text']);
    }

    public function testTheValidatorStillRefusesItWhenThereIsNoProviderToCheck(): void
    {
        // The placement bug this pins: the check used to live inside the
        // payment pass, which returns early when no adapter is configured. A
        // deployment with a half-configured provider would have been told about
        // the provider and never about the card numbers going to disk.
        $output = $this->validateWith(['WIRE_LOG' => 'true'], withAdapter: false);

        self::assertSame(2, $output['exit']);
        self::assertStringContainsString('wire_log is ON', $output['text']);
    }

    public function testAnOrdinaryDeploymentValidatesWithNoMentionOfIt(): void
    {
        $output = $this->validateWith([]);

        self::assertStringNotContainsString('wire_log', $output['text']);
    }

    public function testTheDecoratorWritesTheCardVerbatimWhichIsThePointOfIt(): void
    {
        // Deliberately asserting that a PAN and a security code DO reach the
        // file. A scrubbed transcript cannot be replayed by hand, and replaying
        // the exact bytes is the only reason this exists — so a future change
        // that quietly started scrubbing here would break the feature while
        // looking like an improvement.
        $file = sys_get_temp_dir() . '/wire-' . bin2hex(random_bytes(4)) . '.log';
        $body = json_encode(['card_number' => self::PAN, 'card_cvv' => self::SECURITY_CODE]);

        (new VrioWireLog($this->transport('{"response":{"success":true}}'), $file))
            ->send(new Request('POST', 'https://api.example/orders', ['Content-Type: application/json'], (string) $body));

        $written = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString(self::PAN, $written);
        self::assertStringContainsString(self::SECURITY_CODE, $written);
        self::assertStringContainsString('https://api.example/orders', $written);
        self::assertStringContainsString('"status":200', $written);
    }

    public function testTheDecoratorReturnsTheInnerResponseUntouched(): void
    {
        // A debugging switch that changed what the provider was sent, or what
        // the storefront read back, would be worse than no switch at all.
        $inner = new Response(201, '{"response":{"success":true}}');
        $file = sys_get_temp_dir() . '/wire-' . bin2hex(random_bytes(4)) . '.log';

        $returned = (new VrioWireLog(new class ($inner) implements HttpClientInterface {
            public function __construct(private readonly Response $response)
            {
            }

            public function send(Request $request): Response
            {
                return $this->response;
            }
        }, $file))->send(new Request('POST', 'https://api.example/orders', [], '{}'));

        unlink($file);

        self::assertSame($inner, $returned);
    }

    public function testAnUnwritableLogNeverBreaksTheCharge(): void
    {
        // The failure mode that would make this feature worse than useless: a
        // debugging aid taking down a checkout is a worse bug than whatever it
        // was switched on to find.
        $returned = (new VrioWireLog($this->transport('{}'), '/proc/definitely/not/writable/wire.log'))
            ->send(new Request('POST', 'https://api.example/orders', [], '{}'));

        self::assertSame(200, $returned->getStatusCode());
    }

    public function testABodyThatIsNotJsonIsKeptRatherThanDiscarded(): void
    {
        // An HTML error page from a proxy is exactly the case this log gets
        // read for, so a body that fails to parse must survive as the string it
        // was rather than becoming null.
        $file = sys_get_temp_dir() . '/wire-' . bin2hex(random_bytes(4)) . '.log';

        (new VrioWireLog($this->transport('<html>502 Bad Gateway</html>'), $file))
            ->send(new Request('POST', 'https://api.example/orders', [], 'not json either'));

        $written = (string) file_get_contents($file);
        unlink($file);

        self::assertStringContainsString('502 Bad Gateway', $written);
        self::assertStringContainsString('not json either', $written);
    }

    private function transport(string $body): HttpClientInterface
    {
        return new class ($body) implements HttpClientInterface {
            public function __construct(private readonly string $body)
            {
            }

            public function send(Request $request): Response
            {
                return new Response(200, $this->body);
            }
        };
    }

    /**
     * Runs the real `config:validate` with the given environment.
     *
     * @param  array<string, string>          $env
     * @return array{exit: int, text: string}
     */
    private function validateWith(array $env, bool $withAdapter = true): array
    {
        $root = dirname(__DIR__, 2);
        // The environment is this test's, not the machine's: inheriting $_ENV
        // would make the 'no mention of it' case fail on any developer who has
        // the switch on, which is precisely the population most likely to run it.
        $config = \AsterMD\Storefront\Support\Config::load($root . '/config', $env);

        $command = new \AsterMD\Storefront\Console\ValidateCommand(
            new \AsterMD\Storefront\Catalog\CatalogProvider($config),
            new \AsterMD\Storefront\Emr\ClientFactory($config, $root),
            $root . '/config',
            null,
            $config,
            $withAdapter
                ? static fn (): PaymentAdapter => new \AsterMD\Storefront\Payment\NullPaymentAdapter()
                : null,
        );

        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute([]);

        return ['exit' => $tester->getStatusCode(), 'text' => $tester->getDisplay()];
    }
}
