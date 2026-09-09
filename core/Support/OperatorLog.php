<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

use AsterMD\Storefront\Observability\CorrelationContext;

/**
 * Appends one redacted JSON line per event to the operator log.
 *
 * The storefront degrades silently by design — a failed analytics call must
 * never reach the visitor (`[20.1]`) — so this log is the only place those
 * failures become visible, and writing to it must itself never fail: an
 * unwritable file is swallowed rather than turned into the error it was
 * trying to record. Context is redacted on the way in (`[20.6]`), because a
 * log that echoes an order or an intake answer is a personal-data leak onto
 * disk, and the redaction list is shared with the local audit trail so both
 * strip the same fields.
 */
final class OperatorLog
{
    /**
     * Context keys whose values never reach disk, matched case-insensitively.
     * Anything health-, identity-, card- or credential-shaped belongs here;
     * ids, paths, counts and outcome codes are what a log is for.
     *
     * The list is exact-match, so the same datum has to be listed under every
     * name it travels under: the held credential's `number`/`security_code`,
     * the provider payload's `card_cvv`/`bill_fname`, the order row's
     * `buyer_email`. What is deliberately absent is as load-bearing as what is
     * present -- `card_last_four`, territory and campaign ids stay readable,
     * because a log that redacts what is safe teaches the operator to stop
     * reading it.
     */
    private const array REDACTED_KEYS = [
        'first_name', 'last_name', 'name', 'full_name', 'email', 'phone', 'address', 'address_line',
        'address_line_1', 'address_line_2', 'city', 'postal_code', 'zip', 'dob', 'date_of_birth',
        'ssn', 'card_number', 'card', 'cvv', 'exp_month', 'exp_year', 'password', 'api_key',
        'client_secret', 'access_token', 'answers', 'payload', '_amd',
        // The held card credential, as journey state carries it.
        'number', 'security_code', 'expiry_month', 'expiry_year',
        // The names the checkout form and `$_POST` use, which is where the card
        // enters the request in the first place.
        'card_cvc', 'card_expiry',
        // The provider's own names for the same card fields, which its
        // response envelopes echo back into the operator log.
        'card_cvv', 'card_exp_month', 'card_exp_year',
        // The buyer as the orders table and the provider request body name them.
        'buyer_email', 'buyer_name',
        'bill_fname', 'bill_lname', 'bill_address1', 'bill_address2', 'bill_city', 'bill_zipcode',
        'ship_fname', 'ship_lname', 'ship_address1', 'ship_address2', 'ship_city', 'ship_zipcode',
        // The stored-instrument handle, under every name it travels by: the
        // storefront's own (`handle`) and the provider's two halves.
        //
        // These are not card data and no scrubber can recognise them -- they
        // are ordinary integers -- but together they are **authority to
        // charge**, and the provider refuses either half alone precisely
        // because the pairing is what stands between a guessed card id and a
        // debit. Card ids are issued sequentially, so the customer id is the
        // secret half.
        //
        // They earn their place here for the same reason the card does, one
        // level up: the value-based pass cannot see them, and the whole
        // provider response is logged verbatim on any outcome that is not a
        // placement -- which includes a null order status, the shape 37% of
        // recorded orders have and the shape that still vaults a card.
        'handle', 'customer_id', 'customer_card_id',
    ];

    /**
     * The context keys a call site uses when it already knows the journey.
     * Both spellings are in use, and either one present means the line is
     * already correlated and must be left exactly as the call site wrote it.
     */
    private const array SESSION_KEYS = ['session', 'session_uuid'];

    /**
     * @param ?CorrelationContext $correlation `[20.13]`'s identifier, stamped on every line that does not already
     *                                        carry one; null in a scope that has no journey, such as a test
     */
    public function __construct(
        private readonly string $logFile,
        private readonly ?CorrelationContext $correlation = null,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /**
     * Replaces the value of every redacted key with a marker, recursing into
     * nested arrays. Keys are compared lowercased so `Email`, `EMAIL` and
     * `email` are all caught.
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $out[$key] = '[redacted]';
                continue;
            }

            $out[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }

    /**
     * Redacts, scrubs, encodes and appends one line.
     *
     * Two passes, because they see different things. {@see self::redact()} is
     * key-based and blanks the values of names we chose; {@see CardScrubber} is
     * value-based and finds card data inside a string whose key means nothing
     * to us -- a provider's `gateway_request_text`, a driver message quoting
     * the row it rejected. Both run here, at the sink, rather than only at the
     * call sites that remembered to ask, because this method is the one thing
     * every logged event passes through and the tenth caller to forget is a
     * PAN on disk (`[15.8]`).
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` is not cosmetic: `json_encode` returns
     * `false` on a single malformed byte, and database driver messages quote
     * the offending value straight back at us -- so without it the one line
     * recording a charge we cannot account for is exactly the line most likely
     * to vanish. The fallback below covers whatever else can defeat the
     * encoder (a resource, a recursive structure), so an operator reads a
     * degraded line instead of nothing at all.
     *
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $event, array $context): void
    {
        $envelope = [
            'ts' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'context' => $this->correlated(CardScrubber::scrubArray(self::redact($context))),
        ];

        $line = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($line === false) {
            $line = (string) json_encode([
                'ts' => $envelope['ts'],
                'level' => $level,
                'event' => $event,
                'context' => ['error' => 'context could not be encoded'],
            ], JSON_UNESCAPED_SLASHES);
        }

        @file_put_contents($this->logFile, $line . "\n", FILE_APPEND);
    }

    /**
     * Stamps `[20.13]`'s correlation identifier on a line that does not
     * already name one.
     *
     * **A call site that named the journey always wins.** Some lines are about
     * a journey other than the ambient one — a sweep iterating other people's
     * orders, a resume that was rejected because it referenced a session this
     * request does not own — and overwriting those with the request's own
     * identifier would file the line under the wrong journey, which is worse
     * than filing it under none.
     *
     * **This runs after both defences, and that ordering is deliberate rather
     * than incidental.** The value is read from this application's own journey
     * store moments earlier; it is not foreign data, so the value-based pass
     * has nothing to find in it, and running it through one buys nothing.
     *
     * That ordering was once the *only* thing protecting the identifier. A
     * session uuid whose hex happens to be all digits across two or three of
     * its groups is a run of thirteen to nineteen digits joined by dashes, and
     * about one in ten of those satisfies Luhn — so {@see CardScrubber} masked
     * part of it, and `11111111-1111-4111-8111-111111111111` came back as
     * `[redacted-card]-[redacted-card]-111111111111`. A correlation identifier
     * silently corrupted for a fraction of journeys is worse than none at all,
     * because the fraction is invisible. {@see CardScrubber} now recognises the
     * fixed five-group uuid shape and holds those digits back, so a `session`
     * a **call site** passes survives intact as well — this ordering is no
     * longer load-bearing for correctness, only for the wasted work it avoids.
     *
     * The uuid is not on the redaction list either, deliberately: correlating
     * three systems is the whole point of `[20.13]`, and an identifier that
     * cannot be read cannot correlate anything. What it *unlocks* — the intake
     * answers behind a resume link — is why `[30.7]` audits the reads rather
     * than why this would be hidden here.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function correlated(array $context): array
    {
        if ($this->correlation === null) {
            return $context;
        }

        foreach (self::SESSION_KEYS as $key) {
            if (array_key_exists($key, $context)) {
                return $context;
            }
        }

        $sessionUuid = $this->correlation->sessionUuid();

        return $sessionUuid === null ? $context : ['session' => $sessionUuid] + $context;
    }
}
