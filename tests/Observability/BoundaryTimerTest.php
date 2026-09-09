<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Observability;

use AsterMD\Storefront\Observability\Boundary;
use AsterMD\Storefront\Observability\BoundaryTimer;
use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

final class BoundaryTimerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/boundary-timer-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        $lines = [];

        foreach (explode("\n", trim((string) file_get_contents($this->logFile))) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            self::assertIsArray($decoded);
            $lines[] = $decoded;
        }

        return $lines;
    }

    private function timer(): BoundaryTimer
    {
        $ticks = [1_000_000_000, 1_042_000_000];

        return new BoundaryTimer(
            new OperatorLog($this->logFile),
            static function () use (&$ticks): int {
                /** @var list<int> $ticks */
                return array_shift($ticks) ?? 2_000_000_000;
            },
        );
    }

    public function testASuccessfulCallIsLoggedWithItsOutcomeAndLatency(): void
    {
        $result = $this->timer()->measure(
            Boundary::EmrSession,
            static fn (): string => 'uuid-1',
            static fn (string $value): array => ['outcome' => 'created'],
        );

        self::assertSame('uuid-1', $result);

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertSame('external.emr.session', $lines[0]['event']);
        self::assertSame('info', $lines[0]['level']);
        self::assertSame('created', $lines[0]['context']['outcome']);
        self::assertEquals(42.0, $lines[0]['context']['latency_ms']);
    }

    public function testAThrowingCallIsLoggedAndTheExceptionStillReachesTheCaller(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            $this->timer()->measure(
                Boundary::ProviderPlacement,
                static fn (): never => throw new \RuntimeException('provider exploded'),
            );
        } finally {
            $lines = $this->lines();
            self::assertCount(1, $lines);
            self::assertSame('error', $lines[0]['level']);
            self::assertSame('external.provider.placement', $lines[0]['event']);
            self::assertSame('exception', $lines[0]['context']['outcome']);
            self::assertSame('RuntimeException', $lines[0]['context']['failure']);
            self::assertStringNotContainsString('provider exploded', (string) file_get_contents($this->logFile));
        }
    }

    /**
     * Observability may not become the failure it was added to record
     * (`[20.1]`).
     */
    public function testADescriberThatThrowsDoesNotBreakTheCall(): void
    {
        $result = $this->timer()->measure(
            Boundary::CartMirror,
            static fn (): int => 7,
            static fn (int $value): never => throw new \LogicException('bad describer'),
        );

        self::assertSame(7, $result);
        self::assertSame('undescribed', $this->lines()[0]['context']['outcome']);
    }

    public function testContextIsRedactedOnTheWayIn(): void
    {
        $this->timer()->measure(
            Boundary::OpportunityWrite,
            static fn (): bool => true,
            null,
            ['email' => 'buyer@example.test', 'fields' => 12],
        );

        $line = $this->lines()[0];
        self::assertSame('[redacted]', $line['context']['email']);
        self::assertSame(12, $line['context']['fields']);
    }
}
