<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http;

use AsterMD\Storefront\Bootstrap\AppFactory;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Tests\Support\ConfigVariant;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `robots.txt` (`[24.4]`, `[24.7]`, `[24.13]`).
 *
 * The suite's own configuration sets `discourage_indexing`, so a test that
 * only exercises the default reaches the blanket-refusal branch and nothing
 * else — which is the branch that matters least, because a staging
 * deployment refusing everything is the safe failure. **The branch a live
 * storefront runs in is the one where indexing is allowed and only the funnel
 * is fenced off**, and it is reached here through the `Config::class`
 * container seam rather than by constructing the controller directly, so what
 * is under test is the document a crawler would actually be served.
 */
final class RobotsTest extends TestCase
{
    use ConfigVariant;
    use TempDatabase;

    private const string BASE = 'https://storefront.example';

    /** Every path prefix `[24.6]` names, plus the two the funnel gained after it was written. */
    private const array FUNNEL_PREFIXES = [
        '/cart/',
        '/intake/',
        '/verify/',
        '/checkout/',
        '/upsell/',
        '/thank-you/',
        '/not-eligible/',
    ];

    public function testTheDocumentIsServedAsPlainText(): void
    {
        $response = $this->fetch(null);

        self::assertSame(200, $response['status']);
        self::assertSame('text/plain; charset=utf-8', $response['type']);
    }

    /**
     * `[24.7]`. The master switch is not one directive among others: it
     * replaces the file.
     */
    public function testTheMasterSwitchTurnsTheWholeFileIntoABlanketRefusal(): void
    {
        $body = $this->fetch($this->seoConfig(['discourage_indexing' => true]))['body'];

        self::assertStringContainsString("User-agent: *\nDisallow: /\n", $body);
        self::assertSame(['User-agent: *'], $this->userAgentLines($body));
        self::assertSame(['/'], $this->disallowedPaths($body));
    }

    /**
     * The per-route rules must not survive the master switch. A file that
     * says `Disallow: /checkout/` alongside `Disallow: /` reads as a list of
     * exceptions, which is the opposite of what `[24.7]` asks for.
     */
    public function testTheMasterSwitchSuppressesThePerRouteRules(): void
    {
        $body = $this->fetch($this->seoConfig(['discourage_indexing' => true]))['body'];

        foreach (self::FUNNEL_PREFIXES as $prefix) {
            self::assertStringNotContainsString('Disallow: ' . $prefix, $body);
        }
    }

    /** `[24.6]` — the funnel is fenced off in the configuration a live storefront runs. */
    public function testEveryFunnelPrefixIsDisallowedWhenIndexingIsAllowed(): void
    {
        $disallowed = $this->disallowedPaths($this->fetch($this->indexable())['body']);

        foreach (self::FUNNEL_PREFIXES as $prefix) {
            self::assertContains($prefix, $disallowed, $prefix . ' is not fenced off in robots.txt');
        }
    }

    /**
     * The other half of `[24.6]`: the storefront and product pages stay
     * crawlable. A robots file that fences off the funnel by fencing off the
     * whole site has satisfied nothing.
     */
    public function testTheMarketingSurfaceIsNotDisallowed(): void
    {
        $disallowed = $this->disallowedPaths($this->fetch($this->indexable())['body']);

        foreach (['/', '/treatments/', '/products/', '/terms/', '/privacy/', '/telehealth-consent/'] as $path) {
            self::assertNotContains($path, $disallowed);
        }
    }

    /**
     * Every path the file fences off must be a path the indexing policy also
     * refuses. These are two renderings of one decision, and the failure mode
     * when they disagree is silent: a page that believes it is indexable,
     * behind a `Disallow` that stops a crawler ever reading the page to find
     * out.
     */
    public function testNothingIsDisallowedThatThePolicyWouldAllowToBeIndexed(): void
    {
        $config = $this->indexable();
        $policy = \AsterMD\Storefront\Seo\RobotsPolicy::fromConfig($config);

        foreach ($this->disallowedPaths($this->fetch($config)['body']) as $path) {
            self::assertFalse(
                $policy->isIndexable($path),
                $path . ' is disallowed in robots.txt but the policy says it may be indexed',
            );
        }
    }

    /**
     * A deployment that wants everything crawled clears the rules, and the
     * file it gets must still be a valid one. A `User-agent` group with no
     * directive under it is an incomplete record, so the permissive case is
     * stated explicitly rather than left as an empty group.
     */
    public function testAGroupIsNeverEmittedWithoutADirective(): void
    {
        $body = $this->fetch($this->indexable(['robots_rules' => []]))['body'];

        self::assertStringContainsString("User-agent: *\nAllow: /\n", $body);
    }

    /**
     * A rule is not automatically a refusal. `noarchive` asks a crawler to
     * index the page but not cache it, so translating it into `Disallow`
     * removes from the index the very page the deployment asked to have in
     * it — `Disallow` blocks the fetch, and a page that is never fetched is
     * never indexed.
     */
    public function testOnlyRulesThatRefuseIndexingBecomeDisallowLines(): void
    {
        $rules = ['/faq/' => 'noarchive', '/checkout/*' => 'noindex, nofollow'];
        $disallowed = $this->disallowedPaths($this->fetch($this->indexable(['robots_rules' => $rules]))['body']);

        self::assertNotContains('/faq/', $disallowed);
        self::assertContains('/checkout/', $disallowed);
    }

    /**
     * `[24.13]`. Silence is already permission in `robots.txt`, so an
     * allowing deployment emits no crawler block at all: a redundant `Allow`
     * per named agent is a list that has to be kept correct forever to say
     * nothing.
     */
    public function testAllowingAnswerEnginesEmitsNothingAboutThem(): void
    {
        $body = $this->fetch($this->indexable())['body'];

        foreach ($this->shippedAgents() as $agent) {
            self::assertStringNotContainsString($agent, $body);
        }
    }

    /** `[24.13]` — refusing them names each one, because `*` cannot separate them from search crawlers. */
    public function testRefusingAnswerEnginesDisallowsEachNamedAgent(): void
    {
        $agents = ['GPTBot', 'ClaudeBot'];
        $body = $this->fetch($this->indexable(['ai_crawlers' => ['allow' => false, 'agents' => $agents]]))['body'];

        self::assertSame(['User-agent: *', 'User-agent: GPTBot', 'User-agent: ClaudeBot'], $this->userAgentLines($body));

        foreach ($agents as $agent) {
            self::assertStringContainsString("User-agent: " . $agent . "\nDisallow: /", $body);
        }
    }

    /**
     * Refusing answer engines must not fence off ordinary search crawlers as
     * a side effect — separating the two is the entire reason the agents are
     * named rather than folded into `*`.
     */
    public function testRefusingAnswerEnginesLeavesTheSearchCrawlerGroupAlone(): void
    {
        $body = $this->fetch($this->indexable(['ai_crawlers' => ['allow' => false, 'agents' => ['GPTBot']]]))['body'];

        self::assertNotContains('/', $this->disallowedPaths(explode('User-agent: GPTBot', $body)[0]));
    }

    /** Under `[24.7]` the blanket refusal already covers them, so a second per-agent block says nothing new. */
    public function testTheMasterSwitchMakesTheAnswerEnginePolicyRedundant(): void
    {
        $config = $this->seoConfig(['discourage_indexing' => true, 'ai_crawlers' => ['allow' => false, 'agents' => ['GPTBot']]]);

        self::assertStringNotContainsString('GPTBot', $this->fetch($config)['body']);
    }

    /**
     * The shipped default is permissive, and that is a decision rather than
     * an oversight: refusing answer engines is a commercial choice a client
     * makes, and shipping the refusal would make it silently for them.
     */
    public function testTheShippedConfigurationAllowsAnswerEngines(): void
    {
        self::assertTrue($this->shippedSeo()['ai_crawlers']['allow']);
        self::assertNotSame([], $this->shippedSeo()['ai_crawlers']['agents']);
    }

    /**
     * The sitemap is announced absolutely and from the configured site URL,
     * not from the request. A `Sitemap:` line is only meaningful as an
     * absolute URL, and building it from the request host would let any host
     * that reaches this application publish its own sitemap reference.
     */
    public function testTheSitemapIsAnnouncedAsAnAbsoluteUrl(): void
    {
        $body = $this->fetch($this->indexable())['body'];

        self::assertStringContainsString('Sitemap: ' . self::BASE . '/sitemap.xml', $body);
        self::assertStringNotContainsString(self::BASE . '//', $body);
    }

    /**
     * `[24.7]` again, from the other side: the sitemap stays announced even
     * when everything is refused. Withdrawing the announcement would leave
     * the two documents telling a crawler different stories about whether a
     * sitemap exists — the sitemap answers by being empty, not by vanishing.
     */
    public function testTheSitemapIsStillAnnouncedUnderTheMasterSwitch(): void
    {
        $body = $this->fetch($this->seoConfig(['discourage_indexing' => true]))['body'];

        self::assertStringContainsString('Sitemap: ' . self::BASE . '/sitemap.xml', $body);
    }

    /**
     * @return array{status: int, type: string, body: string}
     */
    private function fetch(?Config $config): array
    {
        $overrides = [\PDO::class => $this->tempPdo()];
        if ($config !== null) {
            $overrides[Config::class] = $config;
        }

        $app = AppFactory::create(dirname(__DIR__, 2), $overrides);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/robots.txt'));

        return [
            'status' => $response->getStatusCode(),
            'type' => $response->getHeaderLine('Content-Type'),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * The shipped configuration with indexing switched on, which is the
     * deployment that matters.
     *
     * @param array<string, mixed> $seo
     */
    private function indexable(array $seo = []): Config
    {
        return $this->seoConfig($seo + ['discourage_indexing' => false]);
    }

    /** @param array<string, mixed> $seo */
    private function seoConfig(array $seo): Config
    {
        $app = $this->shippedApp();
        $app['url'] = self::BASE;
        $app['seo'] = $seo + $app['seo'];

        return $this->configWith(['app' => $app]);
    }

    /** @return array<string, mixed> */
    private function shippedSeo(): array
    {
        return $this->shippedApp()['seo'];
    }

    /** @return array<string, mixed> */
    private function shippedApp(): array
    {
        $root = dirname(__DIR__, 2);
        $env = $_ENV;

        return (static function () use ($root, $env): array {
            return (array) require $root . '/config/app.php';
        })();
    }

    /** @return list<string> */
    private function shippedAgents(): array
    {
        return array_values(array_map(strval(...), $this->shippedSeo()['ai_crawlers']['agents']));
    }

    /** @return list<string> */
    private function disallowedPaths(string $body): array
    {
        return $this->linesStartingWith($body, 'Disallow: ');
    }

    /** @return list<string> */
    private function userAgentLines(string $body): array
    {
        return array_map(
            static fn (string $agent): string => 'User-agent: ' . $agent,
            $this->linesStartingWith($body, 'User-agent: '),
        );
    }

    /** @return list<string> */
    private function linesStartingWith(string $body, string $prefix): array
    {
        $found = [];
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, $prefix)) {
                $found[] = substr($line, strlen($prefix));
            }
        }

        return $found;
    }
}
