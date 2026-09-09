<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Vrio;

use AsterMD\VrioClient\Http\HttpClientInterface;
use AsterMD\VrioClient\Http\Request;
use AsterMD\VrioClient\Http\Response;

/**
 * Writes every provider request and response to a file, **verbatim**.
 *
 * A debugging instrument, off unless a deployment switches it on, and
 * deliberately the one place in this application where the card is not
 * scrubbed. Everything else that touches a provider payload runs it through
 * {@see \AsterMD\Storefront\Support\CardScrubber} on the way to any sink; this
 * exists precisely because that makes a failing call impossible to replay by
 * hand, and reproducing the exact bytes is the only way some provider
 * disagreements get settled.
 *
 * **What it costs, stated plainly rather than buried.** While it is on, this
 * file contains primary account numbers, security codes and expiry dates in
 * clear text, alongside the buyer's name, address and telephone number. That is
 * cardholder data at rest, which `[15.8]` forbids for the application's own
 * storage and which no deployment taking real cards may switch on. It is for a
 * developer reproducing a sandbox call against test cards.
 *
 * Three things keep it from becoming a quiet liability:
 *
 * - it is off by default, and only `app.debug.wire_log` turns it on;
 * - `bin/console config:validate` reports it as an error rather than a warning,
 *   so a deployment cannot go live with it on and not be told;
 * - it writes under `storage/`, which is gitignored, so a transcript cannot be
 *   committed by accident.
 *
 * The decorator returns the inner response untouched, so behaviour with the log
 * on and off is identical — a debugging switch that changed what the provider
 * was sent would be worse than no switch at all.
 */
final class VrioWireLog implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly string $logFile,
    ) {
    }

    public function send(Request $request): Response
    {
        $startedAt = microtime(true);
        $response = $this->inner->send($request);

        $this->write([
            'at' => gmdate('c'),
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'request' => [
                'method' => $request->getMethod(),
                'url' => $request->getUrl(),
                'headers' => $request->getHeaders(),
                'body' => self::decodeIfJson($request->getBody()),
            ],
            'response' => [
                'status' => $response->getStatusCode(),
                'transport_error' => $response->getTransportError(),
                'body' => self::decodeIfJson($response->getBody()),
            ],
        ]);

        return $response;
    }

    /**
     * Decoded where it decodes, raw where it does not.
     *
     * A provider that answers with an HTML error page from a proxy is exactly
     * the case this log is being read for, so a body that is not JSON is kept
     * as the string it was rather than discarded for failing to parse.
     */
    private static function decodeIfJson(?string $body): mixed
    {
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    /**
     * One line per call.
     *
     * Failures are swallowed for the reason every other sink's are: a
     * debugging aid that could take a checkout down would be a worse bug than
     * whatever it was switched on to find.
     *
     * @param array<string, mixed> $entry
     */
    private function write(array $entry): void
    {
        try {
            $directory = dirname($this->logFile);
            if (!is_dir($directory)) {
                // Suppressed rather than checked: an unwritable path must cost
                // a log line and nothing else, and a raised warning is still a
                // failure in a suite configured to treat one as such.
                @mkdir($directory, 0o775, true);
            }

            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line !== false) {
                @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {
            // Intentionally ignored: see the method docblock.
        }
    }
}
