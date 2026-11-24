<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Attribution;

/**
 * The attribution captured for one journey: canonical parameters, the
 * landing referrer, and the values derived from them.
 *
 * Immutable by construction, because the governing rule is that attribution
 * is captured once on first touch and never overwritten for the life of the
 * journey (`[5.12]`) — the original referrer keeps credit through the whole
 * funnel. `null` means "never captured"; an instance whose every field is
 * empty means "captured, and there was nothing there", which is why an
 * empty landing referrer still counts as a capture (`[5.13]`) and why
 * {@see self::firstTouch()} keys off the existence of the instance rather
 * than its emptiness.
 *
 * Decryption happens before this class, not inside it: the caller decodes
 * `_amd`, logs a failure for operators, and passes both parameter bags in,
 * so precedence (`[5.11]` — encrypted wins per key, plain fills the gaps)
 * is decided here on already-normalised keys and holds even when the two
 * sides spell the same parameter differently.
 *
 * The affiliate link is the one value that is not decrypted here. The sales
 * service resolves a link to its owning affiliate itself, and accepts either
 * the plain link id or the raw `_amd` token, so the storefront forwards the
 * token as it arrived rather than its own reading of it. A marketing token
 * decrypts to an object carrying the link id beside a `data` list, and
 * {@see AmdPayload} reads only a bare list, so the link id is not something
 * the storefront can recover from the token itself. It is kept apart from
 * `$params` because it is not a tracking parameter, and keeping the token
 * out of the normalised bag is what `[5.4]` asks for.
 */
final class Attribution
{
    /**
     * @param array<string, string>      $params
     * @param array{source_category: ?string, source_detail: ?string, device_type: ?string, browser: ?string, os: ?string} $derived
     */
    private function __construct(
        public readonly array $params,
        public readonly ?string $referrer,
        public readonly array $derived,
        public readonly ?string $affiliateLinkId = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $plainQuery      the request's query parameters, `_amd` already removed
     * @param array<array-key, mixed> $encryptedParams the decoded `_amd` payload, or `[]`
     * @param ?string                 $selfHost        this storefront's own host, so a referrer naming it is not
     *                                                 recorded as a traffic source referring the site to itself
     * @param ?string                 $amdToken        the raw `_amd` value exactly as it arrived, still encrypted
     */
    public static function capture(
        array $plainQuery,
        array $encryptedParams,
        ?string $referrer,
        ?string $userAgent,
        ?string $selfHost = null,
        ?string $amdToken = null,
    ): self {
        $plain = AttributionNormalizer::normalize($plainQuery);
        $encrypted = AttributionNormalizer::normalize($encryptedParams);

        // Array union keeps the left-hand value on a key collision, which is
        // exactly "encrypted wins, plain fills the gaps" (`[5.11]`).
        $params = $encrypted + $plain;
        ksort($params);

        $referrer = $referrer === null ? null : trim($referrer);

        return new self(
            $params,
            $referrer,
            DerivedAttribution::from($params, $referrer, $userAgent, $selfHost),
            self::affiliateLinkId($amdToken, $plainQuery),
        );
    }

    /**
     * The token beats a plain `affid` beside it: the token is the form an
     * affiliate link is issued in, and the one the sales service resolves
     * the owning affiliate from. Only `affid` names a link:
     * the other affiliate aliases carry a network's own affiliate identifier,
     * which is not a link id and stays in `$params` as `affiliate_id`.
     *
     * @param array<array-key, mixed> $plainQuery
     */
    private static function affiliateLinkId(?string $amdToken, array $plainQuery): ?string
    {
        foreach ([$amdToken, $plainQuery['affid'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /** Whatever was captured first stands (`[5.12]`, `[5.13]`). */
    public static function firstTouch(?self $existing, self $incoming): self
    {
        return $existing ?? $incoming;
    }

    public function get(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->params === []
            && $this->affiliateLinkId === null
            && ($this->referrer === null || $this->referrer === '');
    }

    /** @return array{params: array<string, string>, referrer: ?string, derived: array<string, ?string>, affiliate_link_id: ?string} */
    public function toArray(): array
    {
        return [
            'params' => $this->params,
            'referrer' => $this->referrer,
            'derived' => $this->derived,
            'affiliate_link_id' => $this->affiliateLinkId,
        ];
    }

    /** @param array<string, mixed> $data as produced by {@see self::toArray()} */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $params */
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];
        /** @var array<string, ?string> $derived */
        $derived = is_array($data['derived'] ?? null) ? $data['derived'] : [];
        $referrer = isset($data['referrer']) && is_string($data['referrer']) ? $data['referrer'] : null;
        $affiliateLinkId = isset($data['affiliate_link_id']) && is_string($data['affiliate_link_id'])
            && $data['affiliate_link_id'] !== '' ? $data['affiliate_link_id'] : null;

        return new self($params, $referrer, $derived + [
            'source_category' => null,
            'source_detail' => null,
            'device_type' => null,
            'browser' => null,
            'os' => null,
        ], $affiliateLinkId);
    }

    /**
     * The attribution the EMR session-creation call carries (`[4.4]`): the
     * landing referrer, the UTM triple, the first five custom
     * sub-identifiers and the affiliate link. Channel binding and geo are
     * derived server-side by the EMR and are deliberately not sent, and empty
     * sections are omitted rather than sent blank (`[14.6]`).
     *
     * @return array<string, mixed>
     */
    public function emrSessionPayload(): array
    {
        $payload = [];

        if ($this->referrer !== null && $this->referrer !== '') {
            $payload['referrer'] = $this->referrer;
        }

        $utm = array_filter([
            'source' => $this->get('utm_source'),
            'medium' => $this->get('utm_medium'),
            'campaign' => $this->get('utm_campaign'),
        ], static fn (?string $value): bool => $value !== null && $value !== '');
        if ($utm !== []) {
            $payload['utm'] = $utm;
        }

        $custom = [];
        foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $key) {
            $value = $this->get($key);
            if ($value !== null && $value !== '') {
                $custom[$key] = $value;
            }
        }
        if ($custom !== []) {
            $payload['custom_params'] = $custom;
        }

        if ($this->affiliateLinkId !== null) {
            $payload['affiliate_link_id'] = $this->affiliateLinkId;
        }

        return $payload;
    }
}
