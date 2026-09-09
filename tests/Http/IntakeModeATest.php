<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\FakeSessionGateway;
use AsterMD\Storefront\Tests\Support\SampleCatalog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Mode A — the hosted engine — driven through the real app.
 *
 * The claim under test is `[28.3]`'s: the renderer is a configuration choice
 * and switching it changes who draws the questionnaire, not what it means. So
 * the two interesting cases here are the ones nothing else can cover — that
 * the engine receives a definition it can actually parse, and that a
 * disqualifying submission is refused by the server in mode A exactly as in
 * mode C, because the engine's own alerts are display-only and its navigation
 * consults no eligibility state.
 *
 * The definition block is decoded rather than grepped on purpose. It reaches
 * the template pre-serialised and is emitted through `raw` (`[13.4]`), so an
 * autoescaping mistake would leave a block that still contains every expected
 * substring while arriving at the engine as text that parses to nothing.
 */
final class IntakeModeATest extends TestCase
{
    use TempDatabase;

    /** The teleform the sample catalog puts on `semaglutide`, so the cart really does call for a form. */
    private const string TELEFORM = '6a8aec0dec745c70f5f3e69a';

    private const string SESSION = 'sess-1234567890abcdef';

    private ?string $renderer = null;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->renderer = isset($_ENV['INTAKE_RENDERER']) ? (string) $_ENV['INTAKE_RENDERER'] : null;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        if ($this->renderer === null) {
            unset($_ENV['INTAKE_RENDERER']);
        } else {
            $_ENV['INTAKE_RENDERER'] = $this->renderer;
        }
    }

    public function testTheEnginesStylesheetAndScriptAreVisibleInTheMarkup(): void
    {
        $body = (string) $this->get($this->app('js-engine'), '/intake/medical/')->getBody();

        // The declared exception to the local-asset rule belongs where an
        // auditor can see it, not buried inside a JS module.
        self::assertStringContainsString('https://cdn.astermd.com/js-engine/form.min.css', $body);
        self::assertStringContainsString('https://cdn.astermd.com/js-engine/form.min.js', $body);
    }

    public function testTheEmbeddedDefinitionDecodesAsJsonCarryingEveryPage(): void
    {
        $body = (string) $this->get($this->app('js-engine'), '/intake/medical/')->getBody();
        $decoded = self::jsonBlock($body, 'data-intake-definition');

        self::assertIsArray($decoded, 'the block reaches the engine as JSON, not as escaped text [13.4]');
        self::assertSame(self::TELEFORM, $decoded['formId'] ?? null);
        self::assertCount(5, $decoded['pages'] ?? [], 'every authored page is handed to the engine');
    }

    public function testTheModeCFieldMarkupIsNotRenderedAlongsideTheEngine(): void
    {
        $body = (string) $this->get($this->app('js-engine'), '/intake/medical/')->getBody();

        self::assertStringContainsString('data-intake-engine', $body);
        self::assertStringNotContainsString(
            'data-intake-field',
            $body,
            'the engine draws the fields, so the server-rendered partials must not also',
        );
    }

    public function testStoredAnswersAreHandedBackAsTheEnginesInitialValues(): void
    {
        $app = $this->app('js-engine');
        $token = $this->token($app);
        $this->post($app, '/intake/save/', [
            '_csrf' => $token,
            'page' => '0',
            'first_name' => 'Dana',
            'email' => 'dana@example.test',
        ]);

        $body = (string) $this->get($app, '/intake/medical/')->getBody();
        $values = self::jsonBlock($body, 'data-intake-values');

        self::assertSame('Dana', $values['first_name'] ?? null);
        self::assertSame('dana@example.test', $values['email'] ?? null);
    }

    public function testTheTokenAndTheEndpointsAreExposedAsAttributesRatherThanInterpolatedIntoAScript(): void
    {
        $app = $this->app('js-engine');
        $body = (string) $this->get($app, '/intake/medical/')->getBody();
        $token = (string) $_SESSION['_csrf'];

        self::assertStringContainsString('data-save-url="/intake/save/"', $body);
        self::assertStringContainsString('data-submit-url="/intake/submit/"', $body);
        self::assertStringContainsString('data-csrf="' . $token . '"', $body);
        // One occurrence, and the assertion above proves which one it is: a
        // token that also appeared in a script body would be a token the page
        // hands to whatever else runs on it.
        self::assertSame(1, substr_count($body, $token), 'the token appears only as an attribute value');

        foreach (self::inlineScripts($body) as $script) {
            self::assertStringNotContainsString('/intake/save/', $script);
            self::assertStringNotContainsString('/intake/submit/', $script);
        }
    }

    /**
     * `[28.3]`'s guarantee, and the one assertion that proves the engine is not
     * trusted: the same posted answers that stop a mode C journey stop a mode A
     * one, because the server evaluates every submission (`[10.45]`).
     */
    public function testADisqualifyingSubmissionIsRefusedInModeAExactlyAsInModeC(): void
    {
        foreach (['js-engine', 'server'] as $renderer) {
            $app = $this->app($renderer);
            $token = $this->token($app);

            // BMI ≈ 17.1 from the recorded form's height/weight composite,
            // which is what its one firing hard rule compares against.
            $response = $this->post($app, '/intake/submit/', [
                '_csrf' => $token,
                'bmi_height' => '70',
                'bmi_weight' => '120',
            ]);

            self::assertSame(303, $response->getStatusCode(), $renderer);
            self::assertSame('/not-eligible/', $response->getHeaderLine('Location'), $renderer);

            $state = $app->getContainer()?->get(JourneyStore::class)->state();
            self::assertNotNull($state);
            self::assertSame('bmi_low_hard_stop_notice', $state->disqualifiedRule, $renderer);
            self::assertFalse($state->formCompleted(self::TELEFORM), $renderer . ': a refused submission completes nothing');

            $_SESSION = [];
        }
    }

    public function testTheEngineIsToldWhichQuestionnaireItIsDrawingSoItsPostsCanBeFiled(): void
    {
        // The save, submit and capture endpoints are shared by both steps and
        // none of their paths names one, so a post that does not carry the step
        // is filed against whichever the URI happens to suggest -- which would
        // mark the medical form complete on an eligibility answer and open
        // checkout behind a questionnaire nobody was shown.
        $app = $this->app('js-engine');
        $body = (string) $this->get($app, '/intake/medical/')->getBody();

        self::assertStringContainsString('data-step="intake"', $body);
    }

    public function testSwitchingTheRendererBackToServerRendersTheFieldsAgain(): void
    {
        $body = (string) $this->get($this->app('server'), '/intake/medical/')->getBody();

        self::assertStringContainsString('data-intake-field', $body);
        self::assertStringNotContainsString('cdn.astermd.com', $body, 'mode C needs none of the hosted engine');
    }

    /**
     * `intake.renderer` is read from `config/intake.php`, which resolves it
     * from the environment — so the setting is switched the way a deployment
     * switches it, rather than by substituting a Config the rest of the
     * container also reads.
     */
    private function app(string $renderer): App
    {
        $_ENV['INTAKE_RENDERER'] = $renderer;
        $this->seedCart();

        $cacheDir = sys_get_temp_dir() . '/storefront-teleform-' . bin2hex(random_bytes(6));
        $this->registerTempDirForCleanup($cacheDir);

        return AppFactory::create(dirname(__DIR__, 2), [
            \PDO::class => $this->tempPdo(),
            // The `semaglutide` line seedCart() adds needs its sample-seed
            // teleform id, not whatever bin/console theme:sync --apply has
            // currently synced into config/products.generated.php.
            ProductCatalog::class => new FakeCatalog(SampleCatalog::products()),
            // Without a minted session there is no journey to store answers
            // on, and every assertion here is about what the journey holds.
            SessionGateway::class => new FakeSessionGateway(mintUuid: self::SESSION),
            TeleformGateway::class => self::gateway(self::recordedDefinition()),
            // Never the repository's own cache directory: a definition left
            // behind by one test must not be served to the next.
            DefinitionCache::class => new DefinitionCache($cacheDir, 0),
        ]);
    }

    /** The `semaglutide` line is what makes the step ask a questionnaire at all, and the variant is checkout's own precondition. */
    private function seedCart(): void
    {
        $_SESSION['cart'] = ['session' => self::SESSION, 'territory' => null, 'lines' => [[
            'slug' => 'semaglutide', 'name' => 'Semaglutide', 'kind' => 'rx',
            'emr_product_id' => null, 'parent_slug' => null,
            'quantity' => 1, 'unit_price_cents' => 5000, 'variant_id' => 'semaglutide-1m',
        ]]];
    }

    /** @param array<string, mixed> $definition */
    private static function gateway(array $definition): TeleformGateway
    {
        return new class ($definition) implements TeleformGateway {
            /** @param array<string, mixed> $definition */
            public function __construct(private readonly array $definition)
            {
            }

            public function metadata(string $teleformId): ?TeleformMetadata
            {
                return new TeleformMetadata(
                    id: $teleformId,
                    identifier: 'tests/intake-mode-a.json',
                    type: 'intake',
                    layout: 'five-column',
                    dbFields: ['first_name' => 'opportunity.first_name', 'email' => 'opportunity.email'],
                );
            }

            public function definition(TeleformMetadata $metadata): ?array
            {
                return $this->definition;
            }
        };
    }

    /**
     * The recorded staging form rather than a hand-written one: the point of
     * this file is that a real published definition survives the round trip
     * into the engine intact.
     *
     * @return array<string, mixed>
     */
    private static function recordedDefinition(): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/fixtures/teleform-definition.json');

        return (array) json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The contents of one `<script type="application/json">` block, decoded.
     *
     * @return array<string, mixed>|null
     */
    private static function jsonBlock(string $body, string $marker): ?array
    {
        $pattern = '/<script type="application\/json" ' . preg_quote($marker, '/') . '>(.*?)<\/script>/s';
        if (preg_match($pattern, $body, $match) !== 1) {
            self::fail(sprintf('No <script type="application/json" %s> block in the response.', $marker));
        }

        $decoded = json_decode($match[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> the body of every script element that carries no `src` */
    private static function inlineScripts(string $body): array
    {
        preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>(.*?)<\/script>/s', $body, $matches);

        return $matches[1];
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', $path)
                ->withCookieParams(['amd_session' => self::SESSION]),
        );
    }

    /** @param array<string, string> $body */
    private function post(App $app, string $path, array $body): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', $path)
                ->withCookieParams(['amd_session' => self::SESSION])
                ->withParsedBody($body),
        );
    }

    /** Seeds the CSRF token with a GET, the way a visitor arrives at the form before posting it. */
    private function token(App $app): string
    {
        $this->get($app, '/intake/medical/');

        return (string) $_SESSION['_csrf'];
    }
}
