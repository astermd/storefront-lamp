<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Http\Controller;

use AsterMD\Storefront\Database\ConnectionFactory;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Observability\Reachability;
use AsterMD\Storefront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `/health/` — an unauthenticated JSON status probe for uptime monitors and
 * load balancers (`[20.15]`).
 *
 * **Two blocks, and the split is the whole design.** `checks` holds what this
 * instance is responsible for and what taking it out of rotation would
 * actually fix: its configuration, its database round-trip, its writable
 * storage, its form-definition cache. Any one of them false drops the response
 * to `degraded` with a 503. `info` holds everything else, and a false value
 * there can never flip the verdict.
 *
 * **Reachability of the EMR and the payment provider is `info`, deliberately.**
 * `[20.15]` asks that the endpoint *report* both, and it does. What it must not
 * do is fail on them. This storefront degrades silently when the EMR is
 * unreachable by design (`[20.1]`) — the marketing pages, the catalog and the
 * receipt all still serve — and when the provider is unreachable every
 * instance is equally unreachable, so a 503 would not route around the fault:
 * it would take the whole site out of rotation and turn "checkout is broken"
 * into "the site is gone". A dependency outside this process is reported
 * beside the verdict, not inside it.
 *
 * **The endpoint makes no outbound call on an ordinary hit.** A health
 * endpoint that called two external systems on every probe would multiply
 * every balancer and every monitor in front of every worker into those
 * systems, hardest at the moment they were already struggling — a
 * self-inflicted outage. So reachability is **opt-in and rate-limited**:
 * `?probe=1` asks for a live check, and {@see Reachability} refuses to run one
 * more often than its own floor however many times it is asked, because this
 * URL is public and unauthenticated. Everything else reports the last recorded
 * result with its age, and `unknown` when there has never been one — which is
 * not `ok`.
 *
 * **`url_mode` is `[23.10]`**: an installation running in the degraded URL
 * fallback must say so in its health output. It is detected rather than
 * configured — a front controller visible in the request path is what the
 * fallback looks like from the outside — so it cannot be set to the
 * comfortable answer by hand.
 *
 * The response is marked noindex: a status document has no business appearing
 * in search results.
 */
final class HealthController
{
    /** The query parameter that opts into a live reachability check. */
    private const string PROBE_PARAM = 'probe';

    public function __construct(
        private readonly Config $config,
        private readonly ConnectionFactory $connections,
        private readonly string $rootDir,
        private readonly ?Reachability $reachability = null,
        private readonly ?DefinitionCache $definitions = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cacheState = $this->formDefinitionCacheState();

        $checks = [
            'config' => $this->config->get('app.name') !== null,
            'database' => $this->databaseIsUsable(),
            'storage_writable' => is_writable($this->rootDir . '/storage'),
            'form_definition_cache' => $cacheState['writable'],
        ];
        $ok = !in_array(false, $checks, true);

        // Configuration and dependency facts an operator needs but that do not
        // make this instance unhealthy. Presence only, for the tracking key —
        // a key never appears in a response.
        $info = [
            'analytics_sessions' => $this->config->get('app.session.analytics') === true,
            'attribution_key' => self::presence($this->config->get('app.attribution.key')),
            'attribution_previous_key' => self::presence($this->config->get('app.attribution.previous_key')),
            'url_mode' => self::urlMode($request),
            'form_definitions_cached' => $cacheState['entries'],
        ] + $this->reachabilityFor($request);

        $response->getBody()->write((string) json_encode([
            'status' => $ok ? 'ok' : 'degraded',
            'php' => PHP_VERSION,
            'checks' => $checks,
            'info' => $info,
        ], JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus($ok ? 200 : 503)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * @return array{probe: string, emr: array<string, mixed>, provider: array<string, mixed>}
     */
    private function reachabilityFor(ServerRequestInterface $request): array
    {
        if ($this->reachability === null) {
            $unknown = ['status' => 'unknown', 'latency_ms' => null, 'failure' => null, 'checked_at' => null, 'age_seconds' => null];

            // The `probe` field is present on every snapshot, including this
            // one, so a reader never has to tell "the probe found nothing"
            // from "no probe was configured" by the absence of a key.
            return ['probe' => Reachability::PROBE_NOT_ATTEMPTED, 'emr' => $unknown, 'provider' => $unknown];
        }

        $query = $request->getQueryParams();
        $requested = $query[self::PROBE_PARAM] ?? null;

        // Any non-empty value opts in. A probe parameter is an operator
        // reaching for a live answer, not an API with a vocabulary to get
        // wrong, and the rate limit is what makes being generous here safe.
        $wanted = is_scalar($requested) && (string) $requested !== '' && (string) $requested !== '0';

        return $wanted ? $this->reachability->probe() : $this->reachability->cached();
    }

    /**
     * `[20.15]`'s form-definition cache state.
     *
     * Writability is the half that can be a check: a cache directory this
     * worker cannot write is a fault on this worker, which is exactly what
     * taking it out of rotation fixes. The entry count is not — a cold cache
     * on a freshly deployed instance is normal, and failing on it would refuse
     * traffic to every new deployment until somebody rendered a form.
     *
     * @return array{writable: bool, entries: ?int}
     */
    private function formDefinitionCacheState(): array
    {
        if ($this->definitions === null) {
            return ['writable' => true, 'entries' => null];
        }

        try {
            $state = $this->definitions->state();

            return ['writable' => $state['writable'], 'entries' => $state['entries']];
        } catch (\Throwable) {
            // The endpoint's own inspection must not be what takes the
            // deployment down (`[20.1]`).
            return ['writable' => true, 'entries' => null];
        }
    }

    /**
     * `[23.10]`: whether URLs are running in the degraded fallback mode.
     *
     * The fallback is what a deployment gets where server-side rewriting is
     * unavailable (`[23.9]`): the front controller appears in the path instead
     * of being rewritten away. That is visible from inside the request — the
     * script name is a prefix of the path the client actually asked for — and
     * detecting it beats configuring it, because the value of this field is
     * that it cannot be set to the answer an operator hoped for.
     */
    private static function urlMode(ServerRequestInterface $request): string
    {
        $script = trim((string) ($request->getServerParams()['SCRIPT_NAME'] ?? ''));

        if ($script === '' || $script === '/') {
            return 'rewritten';
        }

        return str_starts_with($request->getUri()->getPath(), $script) ? 'degraded' : 'rewritten';
    }

    private function databaseIsUsable(): bool
    {
        try {
            $this->connections->create()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function presence(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? 'configured' : 'missing';
    }
}
