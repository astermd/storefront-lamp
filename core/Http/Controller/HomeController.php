<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Catalog\CatalogProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Renders the marketing home page (`/`). Passes the first six listed
 * catalog products ({@see CatalogProvider::listedProducts()} — `rx`/`otc`
 * only, per `[6.1]`) as `featured_products` for the "Our Treatments" grid —
 * the rest of the page has no data of its own.
 */
final class HomeController
{
    public function __construct(private readonly CatalogProvider $catalog)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $featuredProducts = array_slice($this->catalog->listedProducts(), 0, 6);

        return Twig::fromRequest($request)->render($response, 'pages/home.twig', [
            'featured_products' => $featuredProducts,
        ]);
    }
}
