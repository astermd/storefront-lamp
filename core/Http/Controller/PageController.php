<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Generic "render this template" controller for static/mostly-static pages
 * (intake steps, verify, checkout, upsell, thank-you, not-eligible, ...).
 * The route table (config/routes.php) supplies which template and whether
 * the page is noindex via route arguments, so adding a new static page never
 * requires a new controller class.
 */
final class PageController
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $template = (string) ($args['template'] ?? '');
        if ($template === '') {
            throw new \RuntimeException('PageController route missing template argument.');
        }

        return Twig::fromRequest($request)->render($response, $template, ['noindex' => ($args['noindex'] ?? '') === '1']);
    }
}
