<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Sdk\Enum\PageType;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\PageView;
use AsterMD\Storefront\Emr\SessionEventGateway;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reports every page a visitor is shown as a `page_view` session event.
 *
 * "Shown" is decided from the response, not the request: a GET answered with
 * a 2xx HTML page. A redirect is not a page — the page it leads to reports
 * itself — and neither is an error page, a JSON sub-action or the sitemap.
 * The page's title is read from the `<title>` the theme rendered, so it is
 * whatever the visitor's tab says rather than a second opinion about it.
 *
 * It sits inside {@see AttributionMiddleware}, which is what puts the
 * analytics session on the request; a request with none reports nothing,
 * since inventing an identifier is forbidden outright (`[20.8]`).
 *
 * **The page type comes from the path**, because this pipeline runs outside
 * routing and has no route to ask. The funnel's own steps are core routes and
 * are classified here; the deployment's content pages are listed in
 * `app.session.page_types`, which is checked first, so a client adding an
 * about page classifies it in config rather than in core. Anything unlisted is
 * `other`, which the API accepts.
 *
 * Nothing here may cost the visitor their page (`[20.1]`): the gateway bound
 * in production sends after the response, and a failure while building the
 * event is logged by class alone and the response returned untouched.
 */
final class PageViewMiddleware implements MiddlewareInterface
{
    /** `/products/{slug}/`, the one core route whose path names an EMR product. */
    private const string PRODUCT_PATH = '~^/products/([^/]+)/$~';

    /**
     * The funnel's core routes by path prefix, matched in order.
     *
     * @var array<string, PageType>
     */
    private const array FUNNEL_PREFIXES = [
        '/intake/' => PageType::Intake,
        // Identity verification and the eligibility stop are both part of
        // the questionnaire as the visitor experiences it.
        '/verify/' => PageType::Intake,
        '/not-eligible/' => PageType::Intake,
        '/checkout/' => PageType::Checkout,
        '/upsell/' => PageType::Upsell,
        '/thank-you/' => PageType::ThankYou,
    ];

    /** @param array<string, string> $pageTypes `app.session.page_types`: exact served path → PageType value */
    public function __construct(
        private readonly SessionEventGateway $events,
        private readonly ProductCatalog $catalog,
        private readonly OperatorLog $log,
        private readonly array $pageTypes = [],
        private readonly bool $trace = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $session = $request->getAttribute('session_uuid');
        if (is_string($session) && $session !== '' && self::isPage($request, $response)) {
            try {
                $this->events->pageView($session, $this->describe($request, $response));
            } catch (\Throwable $e) {
                $this->log->warning('page_view.unreported', ['exception' => $e::class]);
            }
        }

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'page-view') : $response;
    }

    /** The page type for a served path; public so the mapping can be asserted without a request. */
    public function classify(string $path): PageType
    {
        $configured = $this->pageTypes[$path] ?? null;
        if (is_string($configured) && ($type = PageType::tryFrom($configured)) !== null) {
            return $type;
        }

        if (preg_match(self::PRODUCT_PATH, $path) === 1) {
            return PageType::Product;
        }

        foreach (self::FUNNEL_PREFIXES as $prefix => $type) {
            if (str_starts_with($path, $prefix)) {
                return $type;
            }
        }

        return PageType::Other;
    }

    /**
     * A rendered page carries no `Content-Type` of its own — PHP's default
     * `text/html` is applied when it is emitted — so an absent header is a
     * page, and anything that names another type (the sitemap, robots, a JSON
     * sub-action) is not.
     */
    private static function isPage(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();
        $type = strtolower($response->getHeaderLine('Content-Type'));

        return $request->getMethod() === 'GET'
            && $status >= 200 && $status < 300
            && ($type === '' || str_contains($type, 'text/html'));
    }

    private function describe(ServerRequestInterface $request, ResponseInterface $response): PageView
    {
        $path = $request->getUri()->getPath();
        $type = $this->classify($path);
        $referrer = trim($request->getHeaderLine('Referer'));

        return new PageView(
            type: $type,
            path: $path,
            url: (string) $request->getUri(),
            title: self::title((string) $response->getBody()),
            referrer: $referrer === '' ? null : $referrer,
            productId: $type === PageType::Product ? $this->productId($path) : null,
        );
    }

    private function productId(string $path): ?string
    {
        if (preg_match(self::PRODUCT_PATH, $path, $match) !== 1) {
            return null;
        }

        $id = $this->catalog->product(rawurldecode($match[1]))['emr_product_id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private static function title(string $html): ?string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $match) !== 1) {
            return null;
        }

        $title = trim((string) preg_replace('~\s+~', ' ', html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $title === '' ? null : $title;
    }
}
