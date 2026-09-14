<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Completion\Completion;
use AsterMD\Storefront\Completion\ReceiptViewModel;
use AsterMD\Storefront\Domain\ProductCatalog;
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
    public function __construct(
        private readonly Completion $completion,
        private readonly ProductCatalog $catalog,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $receipt = $this->completion->complete();

        return Twig::fromRequest($request)->render($response, 'pages/thank-you.twig', [
            'noindex' => true,
            'receipt' => $receipt,
            'line_images' => $this->lineImages($receipt),
        ]);
    }

    /**
     * Each line's product photo, by slug, read from the catalog at render time.
     *
     * It is deliberately *not* part of {@see ReceiptViewModel}, which is a snapshot of
     * what was charged and carries no catalog dependency at all. Freezing the
     * money and freezing the picture are separate questions: a later price or
     * a renamed variant must never rewrite a kept receipt, but a photo is
     * illustration rather than a charged fact, so there is nothing to protect
     * by storing a copy of it.
     *
     * A slug the catalog no longer knows, or a product with no photo, is
     * absent from this map and renders the placeholder tile. That is the
     * ordinary case rather than an edge one: a receipt outlives the catalog it
     * was bought from, and the buyer keeps this page.
     *
     * @return array<string, string>
     */
    private function lineImages(ReceiptViewModel $receipt): array
    {
        $images = [];
        foreach ($receipt->lines as $line) {
            $slug = $line['slug'];
            if (isset($images[$slug])) {
                continue;
            }

            $image = $this->catalog->product($slug)['image'] ?? null;
            if (is_string($image) && $image !== '') {
                $images[$slug] = $image;
            }
        }

        return $images;
    }
}
