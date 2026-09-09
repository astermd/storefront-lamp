<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Catalog\CatalogProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * `/products/{slug}/` — looks the slug up in the runtime catalog (generated
 * catalog with client overrides merged in, via {@see CatalogProvider})
 * rather than a database table; there's no product repository yet, so this
 * *is* the product data source. An unknown slug is a 404, not an empty page.
 */
final class ProductDetailController
{
    public function __construct(private readonly CatalogProvider $catalog)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');
        $product = $this->catalog->product($slug);
        if ($product === null) {
            throw new HttpNotFoundException($request);
        }

        return Twig::fromRequest($request)->render($response, 'pages/product.twig', ['product' => $product]);
    }
}
