<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Payment\Stripe;

use Stripe\HttpClient\ClientInterface;

/**
 * Writes every Stripe request and response to a file, **verbatim**.
 *
 * The counterpart to {@see \AsterMD\Storefront\Payment\Vrio\VrioWireLog} and
 * {@see \AsterMD\Storefront\Payment\CheckoutChamp\CheckoutChampWireLog}, for
 * the SDK that declares its own HTTP client.
 *
 * **What it costs.** The card never reaches this application -- Elements
 * tokenises it in the browser -- so there is no primary account number here.
 * But every request carries this deployment's **secret key** in its
 * `Authorization` header, and the bodies carry the buyer's email, name and
 * address alongside the amount. A redacted transcript cannot be replayed by
 * hand, so the key is written as sent, and the file is a secret to be
 * destroyed rather than a log to be shipped.
 *
 * The guard rails are the other two logs':
 *
 * - it is off by default, and only `app.debug.wire_log` turns it on;
 * - `bin/console config:validate` reports it as an error rather than a warning;
 * - it writes under `storage/`, which is gitignored.
 *
 * The decorator returns the inner answer untouched and rethrows what the inner
 * client threw, so behaviour with the log on and off is identical. A failure to
 * connect is written too: that is the call a transcript is most often wanted for.
 */
final class StripeWireLog implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly string $logFile,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $startedAt = microtime(true);
        $request = [
            'method' => strtoupper((string) $method),
            'url' => $absUrl,
            'api_mode' => $apiMode,
            'headers' => $headers,
            'params' => $hasFile ? '[file upload]' : $params,
        ];

        try {
            $answer = $this->inner->request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
        } catch (\Throwable $e) {
            $this->write([
                'at' => gmdate('c'),
                'ms' => self::elapsed($startedAt),
                'request' => $request,
                'response' => ['transport_error' => $e::class . ': ' . $e->getMessage()],
            ]);

            throw $e;
        }

        [$body, $status, $responseHeaders] = $answer + [null, null, []];

        $this->write([
            'at' => gmdate('c'),
            'ms' => self::elapsed($startedAt),
            'request' => $request,
            'response' => [
                'status' => $status,
                'request_id' => self::header($responseHeaders, 'request-id'),
                'body' => self::decodeIfJson(is_string($body) ? $body : null),
            ],
        ]);

        return $answer;
    }

    private static function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Stripe's own id for the call, which is what its support asks for first.
     *
     * The SDK hands headers back as a case-insensitive map or a plain array
     * depending on version, so both are read.
     */
    private static function header(mixed $headers, string $name): ?string
    {
        if (!is_iterable($headers)) {
            return null;
        }

        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }

    /**
     * Decoded where it decodes, raw where it does not: an HTML error page from
     * a proxy is exactly the case this log is read for.
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
     * One line per call. Failures are swallowed: a debugging aid that could
     * take a checkout down would be a worse bug than whatever it was switched
     * on to find.
     *
     * @param array<string, mixed> $entry
     */
    private function write(array $entry): void
    {
        try {
            $directory = dirname($this->logFile);
            if (!is_dir($directory)) {
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
