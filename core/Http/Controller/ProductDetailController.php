<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Funnel\FunnelRules;
use AsterMD\Storefront\Journey\CartStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * `/products/{slug}/` — looks the slug up in the runtime catalog (generated
 * catalog with client overrides merged in, via {@see CatalogProvider})
 * rather than a database table; there's no product repository yet, so this
 * *is* the product data source. An unknown slug is a 404, not an empty page.
 *
 * `assessment_available` is false when adding this product would leave the
 * cart with two or more prescriptions, which collect no questionnaire on the
 * storefront (`[8.0i]`) — the page then offers checkout alone. A cart that
 * cannot be read leaves the page as it is for everyone else: the button is a
 * convenience, and the routing decision behind it applies the rule anyway.
 */
final class ProductDetailController
{
    private readonly FunnelRules $funnel;

    public function __construct(
        private readonly CatalogProvider $catalog,
        private readonly CartStore $carts,
    ) {
        $this->funnel = new FunnelRules($catalog);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');
        $product = $this->catalog->product($slug);
        if ($product === null) {
            throw new HttpNotFoundException($request);
        }

        return Twig::fromRequest($request)->render($response, 'pages/product.twig', [
            'product' => $product,
            'assessment_available' => $this->assessmentAvailable($slug),
        ]);
    }

    private function assessmentAvailable(string $slug): bool
    {
        try {
            return $this->funnel->assessmentAvailableFor($this->carts->cart(), $slug);
        } catch (\Throwable) {
            return true;
        }
    }
}
