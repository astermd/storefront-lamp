<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Completion\Completion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The receipt page (`[17.1]`).
 *
 * Deliberately one line of work: reaching this page is what triggers
 * completion, so the trigger is a call to {@see Completion} and every decision
 * — whether the actions have already fired, what the page shows, when the
 * journey is torn down — belongs there rather than here. A controller that
 * branched on any of it would be a second copy of the fire-once rule, and the
 * two copies would disagree the first time one of them changed.
 *
 * `noindex` for the reason every post-cart page carries it: the URL is
 * reachable without a journey and its content is one buyer's order.
 */
final class ReceiptController
{
    public function __construct(private readonly Completion $completion)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'pages/thank-you.twig', [
            'noindex' => true,
            'receipt' => $this->completion->complete(),
        ]);
    }
}
