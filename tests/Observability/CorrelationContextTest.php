<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Observability;

use AsterMD\Storefront\Observability\CorrelationContext;
use AsterMD\Storefront\Support\OperatorLog;
use PHPUnit\Framework\TestCase;

final class CorrelationContextTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/correlation-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /** @return array<string, mixed> */
    private function firstLine(): array
    {
        $decoded = json_decode(trim((string) file_get_contents($this->logFile)), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testEveryLoggedLineCarriesTheJourneyIdentifierWithoutTheCallSitePassingIt(): void
    {
        $log = new OperatorLog($this->logFile, new CorrelationContext(static fn (): ?string => 'session-abc'));
        $log->warning('cart.mirror_failed', ['items' => 2]);

        self::assertSame('session-abc', $this->firstLine()['context']['session']);
        self::assertSame(2, $this->firstLine()['context']['items']);
    }

    /**
     * A session uuid whose hex is all digits across two or three groups is a
     * dash-joined run of PAN length, and roughly one in ten of those satisfies
     * Luhn — so the identifier is stamped after the value-based pass, which
     * has nothing to find in a value this application just read from its own
     * journey store.
     */
    public function testTheIdentifierSurvivesTheCardScrubberIntact(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $log = new OperatorLog($this->logFile, new CorrelationContext(static fn (): ?string => $uuid));
        $log->error('checkout.declined', []);

        self::assertSame($uuid, $this->firstLine()['context']['session']);
    }

    public function testACallSiteThatAlreadyNamesTheSessionIsNeverOverwritten(): void
    {
        $log = new OperatorLog($this->logFile, new CorrelationContext(static fn (): ?string => 'ambient'));
        $log->info('reconcile.batch', ['session' => 'explicit']);

        self::assertSame('explicit', $this->firstLine()['context']['session']);
    }

    public function testTheAlternativeSpellingIsAlsoRespected(): void
    {
        $log = new OperatorLog($this->logFile, new CorrelationContext(static fn (): ?string => 'ambient'));
        $log->info('session.adopted', ['session_uuid' => 'explicit']);

        self::assertArrayNotHasKey('session', $this->firstLine()['context']);
    }

    public function testNoJourneyMeansNoKeyRatherThanAnEmptyOne(): void
    {
        $log = new OperatorLog($this->logFile, new CorrelationContext(static fn (): ?string => null));
        $log->info('sync.started', []);

        self::assertArrayNotHasKey('session', $this->firstLine()['context']);
    }

    /**
     * The resolver reaches into the container, and the container can fail —
     * a log line must never become the outage it was recording (`[20.1]`).
     */
    public function testAResolverThatThrowsIsSwallowedAndTheLineIsStillWritten(): void
    {
        $log = new OperatorLog($this->logFile, new CorrelationContext(
            static fn (): never => throw new \RuntimeException('no database'),
        ));
        $log->error('journey.save_failed', ['exception' => 'PDOException']);

        self::assertSame('PDOException', $this->firstLine()['context']['exception']);
        self::assertArrayNotHasKey('session', $this->firstLine()['context']);
    }

    public function testAnExplicitlySetIdentifierBeatsTheResolver(): void
    {
        $context = new CorrelationContext(static fn (): ?string => 'resolved');
        $context->set('pushed');

        self::assertSame('pushed', $context->sessionUuid());
    }

    /**
     * A resolver that logs would re-enter this class while it is answering,
     * and the guard is what stops that becoming an unbounded recursion.
     */
    public function testAReentrantResolverAnswersNullRatherThanRecursing(): void
    {
        $context = null;
        $context = new CorrelationContext(static function () use (&$context): ?string {
            self::assertInstanceOf(CorrelationContext::class, $context);

            return $context->sessionUuid() ?? 'outer';
        });

        self::assertSame('outer', $context->sessionUuid());
    }
}
