<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Middleware;

use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Journey\JourneyWriteFailed;
use AsterMD\Storefront\Support\FailureDigest;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Writes the journey's durable state back once, after the handler has run.
 *
 * Its position in the pipeline is load-bearing, and it is the matching half
 * of {@see AttributionMiddleware}'s: it sits inside CSRF, so a request
 * rejected for a bad CSRF token never writes journey state — correct,
 * because such a request mutated nothing — and outside the template
 * globals, so the save happens only after the handler is done with the
 * state it was given.
 *
 * This is the only place journey state is persisted: handlers mutate the
 * state object they were given and never touch a repository, which is what
 * keeps a handler from being clobbered by the save-back it did not know was
 * coming. The flush sits in a `finally` block so a crashing handler still
 * leaves the visitor's progress recorded — the error page is a better
 * outcome than the error page plus a lost step. A failed write is logged and
 * swallowed for the same reason every other analytics write is: a lost
 * analytics write must not become the visitor's problem (`[20.1]`), but it
 * must leave a line an operator can find, which is why a write that matched
 * no row is raised by the store rather than returned quietly.
 *
 * The store arrives as a closure for the same reason {@see AttributionMiddleware}'s
 * resolver does: resolving it has to happen inside this middleware's error
 * boundary rather than while the pipeline is being assembled. The flush itself
 * is what reaches the database now that
 * {@see \AsterMD\Storefront\Repository\SessionRepository} connects on first
 * query, and it is already inside that boundary.
 */
final class JourneyStateMiddleware implements MiddlewareInterface
{
    /** @param \Closure(): JourneyStore $journey resolved on first use, inside this middleware's error boundary */
    public function __construct(
        private readonly \Closure $journey,
        private readonly OperatorLog $log,
        private readonly bool $trace = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } finally {
            $this->flush();
        }

        return $this->trace ? $response->withAddedHeader('X-MW-Trace', 'journey') : $response;
    }

    /**
     * The session id is read from the store only if the store itself could be
     * built — when the failure is the connection underneath it, there is no
     * journey to name, and the log line says so by omission.
     */
    private function flush(): void
    {
        $journey = null;

        try {
            $journey = ($this->journey)();
            $journey->flush();
        } catch (\Throwable $e) {
            // The message is deliberately absent, and this is the one log line
            // in the pipeline where that matters most.
            //
            // The column this write failed on holds clinical answers, the
            // buyer's contact details and the provider's credential handle,
            // and a database driver is under no obligation to describe a
            // refusal without quoting what it refused -- MySQL's 1366 names
            // the column and the value bytes it could not store. Neither
            // defence at the sink can see that: redaction is key-based, so it
            // cannot look inside a string, and {@see CardScrubber} masks by
            // Luhn check, which is a card number and nothing else. Measured
            // against the real scrubber, a diagnosis, an email, a phone number
            // and both halves of the charge authority pass through intact.
            //
            // So the containment is that the message is never taken at all
            // (`[20.6]`, `[22.21]`). This is the policy
            // {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}
            // already states for foreign free text: the class and the SQLSTATE
            // answer every question an outage actually raises -- which failure,
            // how often, since when -- and cannot carry text somebody else
            // wrote about our data. Anyone tempted to restore the message for
            // diagnosis should reach for the failing query's own logs instead.
            $this->log->error('journey.save_failed', array_filter([
                'session' => $journey?->sessionUuid(),
                'exception' => $e::class,
                'sqlstate' => FailureDigest::sqlState($e),
                // Reported only when this application composed it. A
                // {@see JourneyWriteFailed} message is built from identifiers
                // we already log; anything else is somebody else's text about
                // our row, and the paragraph above is why that is not taken.
                'reason' => $e instanceof JourneyWriteFailed ? $e->getMessage() : null,
            ], static fn (mixed $value): bool => $value !== null));
        }
    }

}
