<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Attribution;

/**
 * Heuristics derived from the captured parameters, the landing referrer and
 * the user agent.
 *
 * Two orderings here are deliberate and must not be "simplified":
 * source-category matching is first-match-wins down a fixed list (`[5.14]`),
 * and device and browser classification test the impersonators before the
 * impersonated (`[5.15]`) — tablet before mobile because an iPad's user
 * agent also says `Mobile`, Edge and Opera before Chrome and Chrome before
 * Safari because each carries the earlier one's token. An empty user agent
 * yields nulls, which callers omit rather than sending as blanks.
 */
final class DerivedAttribution
{
    /** Tokens that identify social traffic in a UTM source or medium. */
    private const array SOCIAL_TOKENS = [
        'facebook', 'instagram', 'twitter', 'x.com', 'linkedin', 'tiktok',
        'reddit', 'pinterest', 'snapchat', 'youtube', 'threads',
    ];

    /**
     * @param array<string, string> $params canonical parameters
     *
     * @return array{source_category: ?string, source_detail: ?string, device_type: ?string, browser: ?string, os: ?string}
     */
    public static function from(array $params, ?string $referrer, ?string $userAgent, ?string $selfHost = null): array
    {
        [$category, $detail] = self::source($params, self::externalReferrer($referrer, $selfHost));

        return [
            'source_category' => $category,
            'source_detail' => $detail,
            'device_type' => self::device($userAgent),
            'browser' => self::browser($userAgent),
            'os' => self::os($userAgent),
        ];
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function source(array $params, ?string $referrer): array
    {
        $affiliate = $params['affiliate_id'] ?? '';
        if ($affiliate !== '') {
            return ['affiliate', $affiliate];
        }

        $medium = strtolower($params['utm_medium'] ?? '');
        $utmSource = $params['utm_source'] ?? '';
        if ($medium === 'email' || $medium === 'newsletter') {
            $detail = ($params['utm_campaign'] ?? '') !== '' ? $params['utm_campaign'] : $utmSource;

            return ['email_campaign', $detail === '' ? null : $detail];
        }

        $haystack = strtolower($utmSource . ' ' . $medium);
        foreach (self::SOCIAL_TOKENS as $token) {
            if (str_contains($haystack, $token)) {
                return ['social_media', $utmSource === '' ? null : $utmSource];
            }
        }

        if ($referrer !== null && $referrer !== '') {
            return ['referral', $referrer];
        }

        return [null, null];
    }

    /**
     * The referrer, unless it is this storefront referring to itself.
     *
     * A visitor moving from one page of ours to another sends a `Referer`
     * naming our own host, and treating that as a traffic source records the
     * site as its own referral — `source_category: referral`, `source_detail:
     * https://our-own-domain/`. That is not a source, and under `[5.12]`'s
     * first-touch rule it is worse than noise: whatever is captured first
     * stands, so one internally-referred capture can pin a journey's
     * attribution to the storefront itself for its whole life.
     *
     * Only the derived source is affected. The raw referrer is still stored
     * verbatim, because it is a true fact about the request and a support call
     * asking "where did they come from" is better served by an honest internal
     * URL than by a null.
     *
     * A referrer we cannot parse a host out of is left alone: it is not
     * evidence of a same-origin visit, and dropping it would lose a real
     * external source to a malformed header.
     */
    private static function externalReferrer(?string $referrer, ?string $selfHost): ?string
    {
        if ($referrer === null || $referrer === '' || $selfHost === null || $selfHost === '') {
            return $referrer;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return $referrer;
        }

        return strcasecmp($host, $selfHost) === 0 ? null : $referrer;
    }

    private static function device(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $agent = strtolower($userAgent);

        foreach (['ipad', 'tablet', 'playbook', 'silk', 'kindle'] as $token) {
            if (str_contains($agent, $token)) {
                return 'tablet';
            }
        }

        foreach (['mobi', 'iphone', 'ipod', 'android', 'phone'] as $token) {
            if (str_contains($agent, $token)) {
                return 'mobile';
            }
        }

        return 'desktop';
    }

    private static function browser(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        // Order matters: each later entry's token appears in the earlier ones.
        $browsers = ['Edg' => 'Edge', 'OPR' => 'Opera', 'Opera' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'];
        foreach ($browsers as $token => $name) {
            if (str_contains($userAgent, $token)) {
                return $name;
            }
        }

        return null;
    }

    private static function os(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        // iOS before macOS: an iPhone's user agent also says "like Mac OS X".
        $systems = ['iPhone' => 'iOS', 'iPad' => 'iOS', 'iPod' => 'iOS', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Macintosh' => 'macOS', 'Linux' => 'Linux'];
        foreach ($systems as $token => $name) {
            if (str_contains($userAgent, $token)) {
                return $name;
            }
        }

        return null;
    }
}
