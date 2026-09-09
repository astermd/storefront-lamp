<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Seo;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\ShippedCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * What the application actually renders into `<head>`, walked through the
 * real stack.
 *
 * Every case here is about a single writer. The metadata a page needs is
 * decided in one place and rendered in one place, because the shape this
 * replaces had four writers for one decision — a route argument, a controller
 * variable, a hard-coded tag in the page template and a second one in the
 * layout — and three funnel pages emitted the indexing directive twice with
 * different values as a result.
 */
final class HeadMetadataTest extends TestCase
{
    use ConfigVariant;

    /** Pages that render without a journey, so the `<head>` can be read directly. */
    public static function publicPaths(): \Generator
    {
        yield 'home' => ['/'];
        yield 'treatments' => ['/treatments/'];
        yield 'terms' => ['/terms/'];
        yield 'privacy' => ['/privacy/'];
        yield 'telehealth consent' => ['/telehealth-consent/'];
        yield 'product' => ['/products/' . self::firstProductSlug() . '/'];
        yield 'not eligible' => ['/not-eligible/'];
        yield 'receipt' => ['/thank-you/'];
    }

    // ------------------------------------------------------ [23.6] canonical

    #[DataProvider('publicPaths')]
    public function testEveryPageCarriesExactlyOneCanonicalLink(string $path): void
    {
        $body = $this->body($this->get($this->app(), $path));

        self::assertSame(1, substr_count($body, 'rel="canonical"'), "canonical count on {$path}");
        self::assertMatchesRegularExpression(
            '#<link rel="canonical" href="https?://[^"]+' . preg_quote($path, '#') . '" */?>#',
            $body,
            "canonical href on {$path}",
        );
    }

    // -------------------------------------------------- [24.2] one directive

    #[DataProvider('publicPaths')]
    public function testTheIndexingDirectiveIsRenderedExactlyOnce(string $path): void
    {
        // `/not-eligible/` rendered it twice, with two different values, from
        // two different templates.
        $body = $this->body($this->get($this->app(), $path));

        self::assertSame(1, substr_count($body, 'name="robots"'), "robots tag count on {$path}");
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow"', $body);
    }

    public function testNoPageTemplateWritesAnIndexingDirectiveOfItsOwn(): void
    {
        $offenders = [];
        foreach ($this->pageTemplates() as $file) {
            if (str_contains((string) file_get_contents($file), 'name="robots"')) {
                $offenders[] = basename(dirname($file)) . '/' . basename($file);
            }
        }

        self::assertSame([], $offenders, 'the layout is the only writer of the indexing directive');
    }

    #[DataProvider('publicPaths')]
    public function testTheDirectiveIsEnforcedAsAHeaderAsWellAsATag(string $path): void
    {
        self::assertSame('noindex, nofollow', $this->get($this->app(), $path)->getHeaderLine('X-Robots-Tag'));
    }

    // ---------------------------------------------- [24.7] the master switch

    public function testAnIndexablePageRendersNoDirectiveAtAll(): void
    {
        // The absence of a directive is what "indexable" means to a crawler;
        // this is also the guard that the case above is not passing because
        // the tag is hard-coded somewhere.
        $body = $this->body($this->get($this->app(indexable: true), '/treatments/'));

        self::assertStringNotContainsString('name="robots"', $body);
        self::assertSame('', $this->get($this->app(indexable: true), '/treatments/')->getHeaderLine('X-Robots-Tag'));
    }

    public function testTheFunnelStaysRefusedEvenWhereIndexingIsAllowed(): void
    {
        $body = $this->body($this->get($this->app(indexable: true), '/not-eligible/'));

        self::assertSame(1, substr_count($body, 'name="robots"'));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow"', $body);
    }

    // --------------------------------------------------------- [24.4] titles

    #[DataProvider('publicPaths')]
    public function testEveryTitleGoesThroughTheOneTemplate(string $path): void
    {
        // Three separator conventions shipped before this — `—`, `|`, and both
        // in one title — because every template wrote its own.
        $title = $this->title($this->body($this->get($this->app(), $path)));

        self::assertNotSame('', $title, "empty title on {$path}");
        self::assertStringNotContainsString('|', $title, "second separator convention on {$path}");

        if ($title !== self::configured('default_title')) {
            self::assertStringEndsWith(' — AsterMD', $title, "title template on {$path}");
        }
    }

    public function testAPageWithNothingToAddRendersTheDefaultTitleVerbatim(): void
    {
        $title = $this->title($this->body($this->get($this->app(), '/')));

        self::assertSame(self::configured('default_title'), $title);
    }

    // --------------------------------------------------- [24.4] descriptions

    #[DataProvider('publicPaths')]
    public function testNoPageRendersAnEmptyDescription(string $path): void
    {
        $body = $this->body($this->get($this->app(), $path));

        self::assertSame(1, substr_count($body, 'name="description"'), "description count on {$path}");
        self::assertStringNotContainsString('<meta name="description" content=""', $body);
    }

    public function testAProductWithNoDescriptionOfItsOwnFallsBackToTheConfiguredOne(): void
    {
        // Every product this EMR syncs carries `description: ""`, so this is
        // the case that fires on the real catalog rather than an edge one.
        $body = $this->body($this->get($this->app(), '/products/' . self::firstProductSlug() . '/'));

        self::assertStringContainsString(
            '<meta name="description" content="' . htmlspecialchars(self::configured('default_description'), ENT_QUOTES) . '"',
            $body,
        );
    }

    // -------------------------------------------------- [24.4] the social card

    public function testTheSocialCardSharesTheResolvedTitleAndDescription(): void
    {
        $body = $this->body($this->get($this->app(), '/treatments/'));
        $title = $this->title($body);

        self::assertStringContainsString('<meta property="og:type" content="website"', $body);
        self::assertStringContainsString('<meta property="og:site_name" content="AsterMD"', $body);
        self::assertStringContainsString('<meta property="og:title" content="' . $title . '"', $body);
        self::assertMatchesRegularExpression('#<meta property="og:url" content="https?://[^"]+/treatments/"#', $body);
        self::assertStringContainsString('<meta name="twitter:title" content="' . $title . '"', $body);
        self::assertStringContainsString('<meta name="twitter:card" content="summary"', $body);
    }

    public function testAnUnconfiguredSocialImageRendersNoTagRatherThanAnEmptyOne(): void
    {
        $body = $this->body($this->get($this->app(), '/treatments/'));

        self::assertStringNotContainsString('og:image', $body);
        self::assertStringNotContainsString('twitter:image', $body);
    }

    public function testAConfiguredSocialImageIsRenderedAbsolute(): void
    {
        $app = $this->app(seo: ['social_image' => '/assets/img/card.png']);

        $body = $this->body($this->get($app, '/treatments/'));

        self::assertMatchesRegularExpression(
            '#<meta property="og:image" content="https?://[^"]+/assets/img/card\.png"#',
            $body,
        );
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image"', $body);
    }

    // ------------------------------------------------------- the error pages

    public function testTheNotFoundPageRefusesIndexingEvenWhereTheSiteIsIndexable(): void
    {
        // Rendered by the error middleware rather than by a controller, so it
        // reaches the layout by a path no rule can name — an error page can be
        // served at any URL at all — and it refuses indexing itself.
        $response = $this->get($this->app(indexable: true), '/no-such-page/');

        self::assertSame(404, $response->getStatusCode());
        $body = $this->body($response);
        self::assertSame(1, substr_count($body, 'name="robots"'));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow"', $body);

        // `[24.2]` wants the directive enforced twice, and an error response
        // is no exception. The error boundary sits outside the middleware that
        // stamps the header, so the boundary stamps it itself rather than the
        // boundary being moved inside a pipeline whose order decides what
        // catches a throw.
        self::assertSame(
            \AsterMD\Storefront\Seo\RobotsPolicy::REFUSED,
            $response->getHeaderLine('X-Robots-Tag'),
        );
    }

    public function testAnErrorPagePublishesNoStructuredData(): void
    {
        // The `structured_data` global is published for every path, so the
        // site-level Organization/WebSite graph was rendering on the 404. It
        // is site identity rather than a claim about the missing page, so it
        // is noise rather than a lie — but a document that reports a failure
        // is not a document a crawler should be handed a graph for.
        $body = $this->body($this->get($this->app(), '/no-such-page/'));

        self::assertStringNotContainsString('application/ld+json', $body);

        // The guard that this is the error pages and not structured data as a
        // whole having been switched off.
        self::assertStringContainsString(
            'application/ld+json',
            $this->body($this->get($this->app(), '/treatments/')),
        );
    }

    public function testAClientErrorRefusesIndexingTwiceAsWell(): void
    {
        // The other branch of the boundary: a themed 4xx that is not a 404.
        $app = $this->app(indexable: true);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('OPTIONS', '/treatments/'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame(
            \AsterMD\Storefront\Seo\RobotsPolicy::REFUSED,
            $response->getHeaderLine('X-Robots-Tag'),
        );
        self::assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow"',
            $this->body($response),
        );
    }

    // ------------------------------------------------------------- machinery

    /** @param array<string, mixed> $seo */
    private function app(bool $indexable = false, array $seo = []): App
    {
        return AppFactory::create(dirname(__DIR__, 2), [
            Config::class => $this->configWith([
                'app' => $this->shippedApp($seo + ($indexable ? ['discourage_indexing' => false] : [])),
                // The product page in publicPaths() has to render whether or
                // not this deployment has been synced, so the catalog comes
                // from the same place the slug did.
                'products.generated' => ShippedCatalog::catalog(),
            ]),
        ]);
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    private function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    private function title(string $body): string
    {
        preg_match('#<title>(.*?)</title>#s', $body, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES);
    }

    /**
     * The shipped `config/app.php` with `seo` keys replaced, so a case varies
     * one setting rather than restating a deployment.
     *
     * @param array<string, mixed> $seo
     *
     * @return array<string, mixed>
     */
    private function shippedApp(array $seo): array
    {
        $env = $_ENV;
        /** @var array<string, mixed> $shipped */
        $shipped = require dirname(__DIR__, 2) . '/config/app.php';
        /** @var array<string, mixed> $shippedSeo */
        $shippedSeo = $shipped['seo'];
        $shipped['seo'] = $seo + $shippedSeo;

        return $shipped;
    }

    private static function configured(string $key): string
    {
        $env = $_ENV;
        /** @var array{seo: array<string, string>} $app */
        $app = require dirname(__DIR__, 2) . '/config/app.php';

        return $app['seo'][$key];
    }

    private static function firstProductSlug(): string
    {
        return ShippedCatalog::firstSlug();
    }

    /** @return list<string> */
    private function pageTemplates(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/theme/templates/pages/*.twig') ?: [];
        $files = array_merge($files, glob(dirname(__DIR__, 2) . '/theme/templates/pages/*/*.twig') ?: []);

        return array_values($files);
    }
}
