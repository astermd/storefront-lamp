<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private int $now = 1_700_000_000;

    /** @var array<string, mixed> */
    private array $originalSession = [];

    protected function setUp(): void
    {
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
    }

    private function limiter(int $limit = 3, int $windowSeconds = 60): RateLimiter
    {
        // The clock is injected rather than read, so a test can jump a window
        // instead of sleeping through one.
        return new RateLimiter($limit, $windowSeconds, fn (): int => $this->now);
    }

    public function testAllowsUpToTheLimitInsideOneWindow(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->allow('capture'));
        self::assertTrue($limiter->allow('capture'));
        self::assertTrue($limiter->allow('capture'));
    }

    public function testRefusesTheCallAfterTheLimit(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 3; $i++) {
            $limiter->allow('capture');
        }

        self::assertFalse($limiter->allow('capture'));
        self::assertFalse($limiter->allow('capture'), 'a refusal does not reopen the window either');
    }

    /**
     * The visitor who tripped the limit recovers on their own once the window
     * has run out — no operator has to clear anything.
     */
    public function testAllowsAgainOnceTheWindowHasPassed(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 4; $i++) {
            $limiter->allow('capture');
        }

        $this->now += 60;

        self::assertTrue($limiter->allow('capture'));
    }

    /**
     * Separate counters per key, so flooding the capture endpoint cannot lock
     * a visitor out of an unrelated one.
     */
    public function testTwoKeysAreCountedIndependently(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 4; $i++) {
            $limiter->allow('capture');
        }

        self::assertFalse($limiter->allow('capture'));
        self::assertTrue($limiter->allow('save'));
    }
}
