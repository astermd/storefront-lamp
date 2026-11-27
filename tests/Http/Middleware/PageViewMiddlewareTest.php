<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http\Middleware;

use AsterMD\Sdk\Enum\PageType;
use AsterMD\Storefront\Emr\PageView;
use AsterMD\Storefront\Emr\SessionEventGateway;
use AsterMD\Storefront\Http\Middleware\PageViewMiddleware;
use AsterMD\Storefront\Tests\Domain\FakeCatalog;
use AsterMD\Storefront\Tests\Support\CapturedLog;
use AsterMD\Storefront\Tests\Support\RecordingSessionEventGateway;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PageViewMiddlewareTest extends TestCase
{
    private const string SESSION = 'sess-page-view-0123456789';

    private const string PAGE = '<!doctype html><html><head><title>Semaglutide &amp; you | AsterMD</title></head><body>…</body></html>';

    /**
     * The configured map wins, the funnel's core routes come next, and
     * anything unlisted is `other` — including a path that merely starts like
     * a product page. A configured value the API would refuse is ignored
     * rather than sent.
     */
    public function testEveryPathIsClassifiedIntoTheApisVocabulary(): void
    {
        $middleware = $this->middleware(new RecordingSessionEventGateway(), pageTypes: [
            '/' => 'home',
            '/terms/' => 'cms',
            '/about/' => 'cms',
            '/treatments/' => 'not-a-page-type',
        ]);

        $expected = [
            '/' => PageType::Home,
            '/terms/' => PageType::Cms,
            '/about/' => PageType::Cms,
            '/products/semaglutide/' => PageType::Product,
            '/products/semaglutide/extra/' => PageType::Other,
            '/intake/' => PageType::Intake,
            '/intake/medical/' => PageType::Intake,
            '/verify/' => PageType::Intake,
            '/not-eligible/' => PageType::Intake,
            '/checkout/' => PageType::Checkout,
            '/checkout/confirm/' => PageType::Checkout,
            '/upsell/' => PageType::Upsell,
            '/thank-you/' => PageType::ThankYou,
            '/treatments/' => PageType::Other,
        ];

        foreach ($expected as $path => $type) {
            self::assertSame($type, $middleware->classify($path), $path);
        }
    }

    public function testAProductPageReportsItsEmrProductTitleUrlAndReferrer(): void
    {
        $events = new RecordingSessionEventGateway();

        $this->handle($this->middleware($events), $this->page('/products/semaglutide/?utm_source=x')
            ->withHeader('Referer', 'https://google.com'));

        self::assertCount(1, $events->pageViews);
        self::assertSame(self::SESSION, $events->pageViews[0]['session']);
        $page = $events->pageViews[0]['page'];
        self::assertSame(PageType::Product, $page->type);
        self::assertSame('/products/semaglutide/', $page->path, 'the path carries no query string');
        self::assertSame('http://shop.example.test/products/semaglutide/?utm_source=x', $page->url);
        self::assertSame('Semaglutide & you | AsterMD', $page->title, 'the title the tab shows, entities decoded');
        self::assertSame('https://google.com', $page->referrer);
        self::assertSame('64b2f0c7e3a1d2b3c4e5f6b1', $page->productId);
    }

    /** Only a product page names a product: a product id anywhere else is a claim about nothing. */
    public function testOnlyAProductPageCarriesAProductId(): void
    {
        $events = new RecordingSessionEventGateway();

        $this->handle($this->middleware($events), $this->page('/checkout/'));

        self::assertNull($events->pageViews[0]['page']->productId);
        self::assertNull($events->pageViews[0]['page']->referrer, 'no Referer header, no referrer');
    }

    /**
     * A redirect is not a page (the page it leads to reports itself), nor is
     * a POST, an error page, a non-HTML answer, or a request with no analytics
     * session to report against.
     */
    public function testOnlyAPageTheVisitorWasShownIsReported(): void
    {
        $events = new RecordingSessionEventGateway();
        $middleware = $this->middleware($events);

        $this->handle($middleware, $this->page('/checkout/'), new Response(302));
        $this->handle($middleware, $this->page('/checkout/')->withMethod('POST'));
        $this->handle($middleware, $this->page('/products/missing/'), self::html(404));
        $this->handle($middleware, $this->page('/sitemap.xml'), self::html()->withHeader('Content-Type', 'application/xml'));
        $this->handle($middleware, $this->page('/')->withAttribute('session_uuid', null));

        self::assertSame([], $events->pageViews);

        $this->handle($middleware, $this->page('/'), self::html()->withHeader('Content-Type', 'text/html; charset=utf-8'));

        self::assertCount(1, $events->pageViews, 'an explicit text/html page is still a page');
    }

    /** Reporting is never the visitor's problem (`[20.1]`): the page they asked for still arrives. */
    public function testAFailureWhileReportingNeverCostsTheVisitorTheirPage(): void
    {
        $log = new CapturedLog();
        $throwing = new class implements SessionEventGateway {
            public function pageView(string $session, PageView $page): void
            {
                throw new \RuntimeException('down');
            }

            public function beginIdentityVerification(string $session): void
            {
            }
        };

        $response = $this->handle($this->middleware($throwing, log: $log), $this->page('/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PAGE, (string) $response->getBody());
        self::assertSame('page_view.unreported', $log->lines()[0]['event']);
    }

    /** @param array<string, string> $pageTypes */
    private function middleware(SessionEventGateway $events, array $pageTypes = [], ?CapturedLog $log = null): PageViewMiddleware
    {
        return new PageViewMiddleware(
            $events,
            new FakeCatalog(['semaglutide' => ['slug' => 'semaglutide', 'emr_product_id' => '64b2f0c7e3a1d2b3c4e5f6b1']]),
            ($log ?? new CapturedLog())->log,
            $pageTypes,
        );
    }

    private function page(string $pathAndQuery): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://shop.example.test' . $pathAndQuery)
            ->withAttribute('session_uuid', self::SESSION);
    }

    private function handle(PageViewMiddleware $middleware, ServerRequestInterface $request, ?ResponseInterface $response = null): ResponseInterface
    {
        $handler = new class ($response ?? self::html()) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $middleware->process($request, $handler);
    }

    /** A rendered page as Twig leaves it: a body and no Content-Type of its own. */
    private static function html(int $status = 200): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(self::PAGE);

        return $response;
    }
}
