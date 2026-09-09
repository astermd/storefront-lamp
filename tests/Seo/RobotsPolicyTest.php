<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Seo;

use AsterMD\Storefront\Seo\RobotsPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The indexing decision, asked of the one object that owns it.
 *
 * `robots.txt` used to classify directive text for itself, with a private
 * copy of the `noindex` test living in the controller. Two readings of one
 * decision is precisely what {@see RobotsPolicy} exists to prevent, and the
 * failure mode is silent: a `Disallow` for a page that believes it may be
 * indexed blocks the fetch that would have told a crawler otherwise.
 */
final class RobotsPolicyTest extends TestCase
{
    private const array RULES = [
        '/checkout/*' => 'noindex, nofollow',
        '/health/' => 'noindex',
        '/faq/' => 'noarchive',
    ];

    public function testAPathWithNoRuleIsRefusedByNothing(): void
    {
        $policy = new RobotsPolicy(self::RULES, false);

        self::assertFalse($policy->refusesIndexing('/treatments/'));
        self::assertTrue($policy->isIndexable('/treatments/'));
    }

    public function testARefusingRuleIsReadTheSameWayWhicheverFormItTakes(): void
    {
        $policy = new RobotsPolicy(self::RULES, false);

        self::assertTrue($policy->refusesIndexing('/checkout/payment/'));
        self::assertTrue($policy->refusesIndexing('/health/'));
    }

    /**
     * The distinction the classification exists for. `noarchive` asks a
     * crawler to index the page and not cache it, so translating it into a
     * refusal would block the fetch and the page would never be indexed at
     * all — the opposite of what the deployment asked for.
     */
    public function testADirectiveThatOnlyQualifiesIndexingIsNotARefusal(): void
    {
        $policy = new RobotsPolicy(self::RULES, false);

        self::assertSame('noarchive', $policy->directiveFor('/faq/'));
        self::assertFalse($policy->refusesIndexing('/faq/'));
        self::assertTrue($policy->isIndexable('/faq/'), 'a page that may be indexed may be advertised');
    }

    public function testTheMasterSwitchRefusesEveryPath(): void
    {
        $policy = new RobotsPolicy(self::RULES, true);

        foreach (['/', '/treatments/', '/faq/', '/checkout/payment/'] as $path) {
            self::assertTrue($policy->refusesIndexing($path), $path);
            self::assertFalse($policy->isIndexable($path), $path);
        }
    }

    /**
     * One decision, so the two questions cannot answer differently. Before
     * this, "indexable" meant "has no directive at all" while `robots.txt`
     * meant "says noindex", and a rule such as `noarchive` would have dropped
     * its path from the sitemap while leaving it crawlable.
     */
    public function testBeingIndexableIsExactlyNotBeingRefused(): void
    {
        foreach ([false, true] as $discouraged) {
            $policy = new RobotsPolicy(self::RULES, $discouraged);

            foreach (['/', '/treatments/', '/faq/', '/health/', '/checkout/payment/'] as $path) {
                self::assertSame(!$policy->refusesIndexing($path), $policy->isIndexable($path), $path);
            }
        }
    }

    /**
     * Precedence is decided by the rules, not by the order they were typed
     * in — which is what the class promises and what everything downstream
     * depends on. A hub page that is too thin to index sits under a branch
     * whose articles are merely uncached, so the exact rule and the prefix
     * rule are the same length and one of them has to win on merit.
     *
     * The stakes are not the tag: `refusesIndexing()` feeds `robots.txt` and
     * `sitemap.xml` as well, so losing this tie makes a page indexable *and*
     * advertises it.
     */
    public function testAnExactRuleBeatsAPrefixRuleOfTheSameLengthWhicheverIsWrittenFirst(): void
    {
        $exactFirst = new RobotsPolicy(['/faq/' => 'noindex, nofollow', '/faq/*' => 'noarchive'], false);
        $prefixFirst = new RobotsPolicy(['/faq/*' => 'noarchive', '/faq/' => 'noindex, nofollow'], false);

        foreach (['exact first' => $exactFirst, 'prefix first' => $prefixFirst] as $order => $policy) {
            self::assertSame('noindex, nofollow', $policy->directiveFor('/faq/'), $order);
            self::assertTrue($policy->refusesIndexing('/faq/'), $order);
            self::assertFalse($policy->isIndexable('/faq/'), $order);

            // The prefix still governs everything beneath it, which is the
            // half a tie-break must not cost.
            self::assertSame('noarchive', $policy->directiveFor('/faq/answers/'), $order);
        }
    }

    /**
     * A longer prefix still beats a shorter one, so breaking the tie above
     * did not flatten the ordering it sits inside.
     */
    public function testALongerPrefixBeatsAShorterOne(): void
    {
        $policy = new RobotsPolicy(['/checkout/*' => 'noindex, nofollow', '/checkout/promo/*' => 'noarchive'], false);

        self::assertSame('noarchive', $policy->directiveFor('/checkout/promo/spring/'));
        self::assertSame('noindex, nofollow', $policy->directiveFor('/checkout/payment/'));
    }

    /**
     * A rule list written as a list — `['/checkout/*', '/intake/*']` rather
     * than a map — gives PHP integer keys, and an integer is not a path.
     *
     * The policy is asked for a directive on **every** request, so a rule it
     * cannot read has to be a rule that does nothing rather than a fatal:
     * throwing here takes every page down with it, `/health/` included, and
     * a deployment whose health check 500s is pulled out of rotation
     * entirely. `[20.1]` puts the whole of this on the side that must never
     * break the storefront.
     */
    public function testARuleWithNoPathToMatchIsSkippedRatherThanThrowing(): void
    {
        /** @var array<string, string> $written a deployment can write this, which is the point */
        $written = ['/checkout/*', '/intake/*'];
        $policy = new RobotsPolicy($written, false);

        self::assertNull($policy->directiveFor('/'));
        self::assertNull($policy->directiveFor('/checkout/payment/'));
        self::assertTrue($policy->isIndexable('/'));
    }

    /**
     * Skipping the unreadable rule is only safe because it is also
     * *reported*. Coercing the key instead would leave a rule keyed `0`
     * matching nothing while looking like a fence, and the deployment that
     * meant to close the funnel would never find out.
     */
    public function testAnUnreadableRuleIsNamedSoADeploymentCanBeStopped(): void
    {
        /** @var array<string, string> $written */
        $written = ['/checkout/*', '/health/' => 'noindex'];
        $policy = new RobotsPolicy($written, false);

        $problems = $policy->problems();

        self::assertCount(1, $problems);
        self::assertStringContainsString('0', $problems[0]);
        self::assertStringContainsString('/checkout/*', $problems[0]);
    }

    public function testTheShippedRulesAreReadableAsWritten(): void
    {
        self::assertSame([], (new RobotsPolicy(self::RULES, false))->problems());
    }

    public function testDirectiveTextIsClassifiedWithoutRegardToCase(): void
    {
        // Configuration is written by hand, so the reading cannot depend on
        // how the person who wrote the rule capitalised it.
        $policy = new RobotsPolicy(['/x/' => 'NoIndex, NoFollow'], false);

        self::assertTrue($policy->refusesIndexing('/x/'));
    }
}
