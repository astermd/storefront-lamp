<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Catalog\CatalogProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * `/treatments/` — lists the catalog's listed products only (`rx`/`otc`,
 * via {@see CatalogProvider::listedProducts()} — lab and free-addon
 * products are never listing surfaces `[6.1]`) plus the distinct set of
 * category tags across them, for the treatments page's category filter.
 */
final class ProductListController
{
    public function __construct(private readonly CatalogProvider $catalog)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'pages/treatments.twig', [
            'products' => $this->catalog->listedProducts(),
            'categories' => $this->catalog->categories(),
        ]);
    }
}
