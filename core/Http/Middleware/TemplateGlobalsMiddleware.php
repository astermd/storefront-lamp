<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\CartLine;
use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Domain\StepRouter;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Seo\StructuredData\StructuredData;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\Url;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Publishes request-scoped and config-derived values as Twig globals
 * (site name/url, the current CSRF token, the Maps API key, the footer's
 * treatments column, the current path, the cart, and the page's structured
 * data) before the route handler runs, so every template can reference them
 * without each controller having to pass them through explicitly.
 *
 * The `cart` global exists here rather than in each controller for the same
 * reason `footer_products` does: the mini-cart drawer is included by a
 * partial no controller owns, so a global is the only place every page can
 * get the cart's presentation shape from. Building that shape is exactly the
 * kind of per-line resolution (variant name, image, the three card shapes a
 * line can render as) that has no business living in a template, so it is
 * assembled once here, in {@see self::cartViewModel()}, rather than repeated
 * in Twig on every page that includes the drawer.
 *
 * This runs BEFORE the route handler, which matters for one reason: the
 * cart a mutation-handling controller (`CartController`) saves is not the
 * cart this middleware just published. Publishing a live reference rather
 * than a snapshot would fix that for the same request, but the snapshot
 * shape the drawer needs (counts, totals, per-line presentation) has to be
 * computed from *some* fixed state of the cart, and computing it twice —
 * once here and once after the handler runs — is exactly the duplicated
 * assembly this docblock's second paragraph says does not belong in a
 * controller either. So the globals published on a mutating request are
 * correct for what the cart looked like on the way in, not on the way out —
 * and that is safe only because {@see \AsterMD\Storefront\Http\Controller\CartController}
 * always answers a mutation with a 303 redirect rather than rendering
 * anything itself: the response that would show a stale drawer is never
 * rendered, and the GET that follows the redirect rebuilds the globals from
 * the cart the handler actually saved. Every mutation response being a 303,
 * with no exception, is the invariant that keeps this true, and is covered
 * by its own test.
 */
final class TemplateGlobalsMiddleware implements MiddlewareInterface
{
    /**
     * What the drawer's continue button says for each step it can send
     * someone to. Keyed by step name so the wording and the destination are
     * declared in one place and cannot drift apart.
     */
    private const array CONTINUE_LABELS = [
        'prequalification' => 'Continue Assessment',
        'intake' => 'Start Assessment',
        'intake.medical' => 'Continue Assessment',
        'checkout' => 'Continue Checkout',
        'not_eligible' => 'Review Your Assessment',
        'receipt' => 'View Your Order',
    ];

    private const int MAX_FOOTER_PRODUCTS = 6;

    /** @param \Closure(): CartStore $carts resolved on first use, inside this middleware's error boundary */
    public function __construct(
        private readonly Twig $twig,
        private readonly Config $config,
        private readonly CatalogProvider $catalog,
        private readonly FlowDefinition $flow,
        private readonly OperatorLog $log,
        private readonly \Closure $carts,
        private readonly StepRouter $router,
        private readonly \Closure $journeys,
        private readonly bool $trace = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = Url::canonicalizePath($request->getUri()->getPath());

        $environment = $this->twig->getEnvironment();
        $environment->addGlobal('site', [
            'name' => $this->config->get('app.name', 'AsterMD'),
            'url' => $this->config->get('app.url'),
        ]);
        $environment->addGlobal('csrf_token', $request->getAttribute('csrf_token'));
        $environment->addGlobal('maps_api_key', $this->config->get('app.features.google_maps_api_key'));
        $environment->addGlobal('footer_products', $this->footerProducts());
        $environment->addGlobal('current_path', $path);
        $environment->addGlobal('cart', $this->cartViewModel($path));
        // `[24.8]`. A global rather than something a controller passes, for
        // the same reason `footer_products` and `cart` are globals: the
        // partial that renders it is included by the layout, which no
        // controller owns. The document is assembled from the path alone, so
        // it needs nothing this middleware was not already given.
        $environment->addGlobal(
            'structured_data',
            StructuredData::fromConfig($this->config, $this->catalog)->json($path),
        );

        $response = $handler->handle($request);

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'globals') : $response;
    }

    /**
     * Name/slug pairs for the footer's "Treatments" column, capped at
     * {@see self::MAX_FOOTER_PRODUCTS} so the footer stays a summary link
     * list rather than a full catalog dump. Sourced from
     * {@see CatalogProvider::listedProducts()} (`rx`/`otc` only, `[6.1]`) so
     * a lab or free-addon product never surfaces as a footer link.
     *
     * @return list<array{name: string, slug: string}>
     */
    private function footerProducts(): array
    {
        $products = array_slice($this->catalog->listedProducts(), 0, self::MAX_FOOTER_PRODUCTS);

        return array_map(
            static fn (array $product): array => [
                'name' => (string) ($product['name'] ?? ''),
                'slug' => (string) ($product['slug'] ?? ''),
            ],
            $products,
        );
    }

    /**
     * The cart drawer's whole view-model, or an empty one when {@see CartStore}
     * could not be built — the same must-not-block-the-page contract
     * {@see StepGuardMiddleware} already honours for the guard, applied here
     * for the drawer, since every page on the site includes the drawer
     * partial. A database outage is no longer one of the things that reaches
     * this fallback: the cart's working copy is in the PHP session and
     * {@see \AsterMD\Storefront\Repository\SessionRepository} connects on first
     * query, so the drawer keeps showing the real cart through an outage.
     *
     * @return array{count: int, subtotal_cents: int, notice: ?string, lines: list<array<string, mixed>>}
     */
    private function cartViewModel(string $path): array
    {
        $store = $this->resolveCartStore($path);
        if ($store === null) {
            return ['count' => 0, 'subtotal_cents' => 0, 'notice' => null, 'lines' => []];
        }

        $cart = $store->cart();
        $continue = $this->continueStep($cart, $path);

        return [
            'count' => $cart->itemCount(),
            'subtotal_cents' => $cart->subtotalCents(),
            // Single-read: the notice is shown on the next page and never
            // again, which is what makes it safe to consume it here rather
            // than leave it for a controller that may never run (a plain
            // page view has no controller-side notice consumer at all).
            'notice' => $store->takeNotice(),
            'lines' => array_map(
                fn (CartLine $line): array => $this->lineViewModel($line, $cart, $continue),
                $cart->lines(),
            ),
        ];
    }

    /**
     * Null when the store could not be built at all. Swallowing the failure
     * here and logging it is the same degrade-silently contract
     * {@see StepGuardMiddleware::resolveStores()} already honours (`[20.1]`):
     * the drawer is a detail on every page, and no detail on every page is
     * worth a sitewide 500.
     */
    private function resolveCartStore(string $path): ?CartStore
    {
        try {
            return ($this->carts)();
        } catch (\Throwable $e) {
            $this->log->warning('cart.globals_unavailable', [
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * One line's presentation shape. `variant_name` and `image` are resolved
     * against the catalog rather than stored on the line itself, since a
     * line only snapshots what the mirror needs (`[7.11]`) and the catalog is
     * the source of truth for everything else. `continue_url`/`continue_label`
     * encode the three card shapes the mockup drew: a bundled child has
     * neither (it is not something the buyer continues on its own), a
     * prescription continues to the intake it still needs, and everything
     * else continues straight to checkout.
     *
     * Resolving `intake`/`checkout` goes through {@see self::stepPath()}
     * rather than `$this->flow->pathFor()` directly, because this global is
     * published on every page. Both steps exist in the shipped funnel today,
     * so `pathFor()` throwing is unreachable right now, but a future edit
     * that renames or drops one of them must not turn a cart-drawer detail
     * into a sitewide 500 — the same must-not-block-the-page reasoning
     * {@see self::resolveCartStore()} applies to a database outage. A line
     * whose continue step cannot be resolved gets `null`/`null`, the same
     * shape a bundled child already renders as: a card with no footer
     * action.
     *
     * @return array<string, mixed>
     */
    private function lineViewModel(CartLine $line, Cart $cart, ?array $continue): array
    {
        $product = $this->catalog->product($line->slug);
        $isChild = $line->isChild();

        $continueUrl = $isChild ? null : ($continue['url'] ?? null);
        $continueLabel = $continueUrl === null ? null : ($continue['label'] ?? null);

        return [
            'slug' => $line->slug,
            'name' => $line->name,
            'kind' => $line->kind,
            'quantity' => $line->quantity,
            'unit_price_cents' => $line->unitPriceCents,
            'line_total_cents' => $line->lineTotalCents(),
            'variant_id' => $line->variantId,
            'variant_name' => self::variantName($product, $line->variantId),
            'image' => $product['image'] ?? null,
            'is_child' => $isChild,
            'parent_name' => $isChild ? $cart->line((string) $line->parentSlug)?->name : null,
            'can_change_quantity' => $line->kind !== 'rx' && !$isChild,
            'max_quantity' => self::maxQuantity($product),
            'continue_url' => $continueUrl,
            'continue_label' => $continueLabel,
        ];
    }

    /**
     * Where the drawer's "continue" action sends the visitor, resolved once
     * for the cart rather than guessed per line.
     *
     * It asks the routing decision (`[8.1]`) with journey state, because the
     * kind of a line says what was bought and not what is left to do: an Rx
     * line whose questionnaire is already finished was still being offered
     * "Continue Assessment" back to the form it had completed. The label
     * follows the answer, so the button never names a step the visitor is not
     * being sent to.
     *
     * A cart the router sends `home` gets no action at all — the drawer is
     * already on every page, so a button pointing at the page behind it is
     * noise. The journey is read through the same swallow-and-log contract as
     * the cart store: a drawer on every page must not be able to 500 one
     * (`[20.1]`), and with no journey the router still answers correctly for
     * everything that does not depend on completion.
     *
     * @return array{url: ?string, label: ?string}|null
     */
    private function continueStep(Cart $cart, string $path): ?array
    {
        if ($cart->isEmpty()) {
            return null;
        }

        try {
            $state = ($this->journeys)()->state();
        } catch (\Throwable $e) {
            $this->log->warning('cart.drawer_journey_unavailable', ['path' => $path, 'reason' => $e::class]);
            $state = null;
        }

        $step = $this->router->nextStep($cart, $state);
        if ($step === 'home') {
            return null;
        }

        // The welcome page is the right answer for a visitor who has not
        // started their questionnaire yet, and it is the *drawer's* answer
        // alone. Offering it as a link closes no loop; answering it from the
        // routing decision would open one, because form submission is not a
        // funnel step and the eligibility step forwards through that same
        // decision — a visitor routed back to the welcome page would keep
        // being sent there by the very button that is meant to take them on.
        // A link has no such round trip: it is followed once, by choice.
        //
        // Conditional on the decision having already settled on the intake
        // step, which is what makes the welcome page enterable: its own
        // requirement is that pre-qualification is satisfied, and the routing
        // decision asks that question first — so an answer of `intake.medical`
        // is itself the proof that no eligibility form is outstanding.
        if ($step === 'intake.medical' && ($state === null || $state->formStatus === [])) {
            $step = 'intake';
        }

        $url = $this->stepPath($step);

        return [
            'url' => $url,
            'label' => $url === null ? null : (self::CONTINUE_LABELS[$step] ?? 'Continue'),
        ];
    }

    /**
     * Null when $step names no configured funnel step, logging
     * `cart.flow_step_missing` so the gap is visible to an operator rather
     * than surfacing only as a silently missing continue link on the drawer.
     */
    private function stepPath(string $step): ?string
    {
        try {
            return $this->flow->pathFor($step);
        } catch (\InvalidArgumentException) {
            $this->log->warning('cart.flow_step_missing', ['step' => $step]);

            return null;
        }
    }

    /** @param array<string, mixed>|null $product */
    private static function variantName(?array $product, ?string $variantId): ?string
    {
        if ($product === null || $variantId === null) {
            return null;
        }

        foreach ((array) ($product['variants'] ?? []) as $variant) {
            if (is_array($variant) && (string) ($variant['id'] ?? '') === $variantId) {
                return isset($variant['name']) ? (string) $variant['name'] : null;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $product */
    private static function maxQuantity(?array $product): int
    {
        if ($product !== null && isset($product['max_buy_qty']) && is_numeric($product['max_buy_qty'])) {
            return max(1, (int) $product['max_buy_qty']);
        }

        return CartRules::DEFAULT_MAX_QUANTITY;
    }
}
