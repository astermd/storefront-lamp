<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\AfterResponse;
use PHPUnit\Framework\TestCase;

final class AfterResponseTest extends TestCase
{
    public function testNothingRunsUntilTheQueueIsDrainedAndThenEverythingRunsInOrder(): void
    {
        $queue = new AfterResponse((new CapturedLog())->log);
        $ran = [];

        $queue->defer('first', static function () use (&$ran): void {
            $ran[] = 'first';
        });
        $queue->defer('second', static function () use (&$ran): void {
            $ran[] = 'second';
        });

        self::assertSame([], $ran);
        self::assertSame(['first', 'second'], $queue->queued());

        $queue->run();

        self::assertSame(['first', 'second'], $ran);
        self::assertSame([], $queue->queued(), 'a drained queue does not run its tasks twice');
    }

    /**
     * A failing task is tracking that did not land, and the next task is
     * still owed its turn. The provider's message never reaches the log: a
     * page title is a prescription name on a product page (`[20.6]`).
     */
    public function testAFailingTaskIsLoggedByClassAndTheRestStillRun(): void
    {
        $log = new CapturedLog();
        $queue = new AfterResponse($log->log);
        $ran = false;

        $queue->defer('page_view', static function (): void {
            throw new \RuntimeException('Semaglutide 2.4mg could not be recorded', 503);
        });
        $queue->defer('after', static function () use (&$ran): void {
            $ran = true;
        });

        $queue->run();

        self::assertTrue($ran);
        $line = $log->lines()[0];
        self::assertSame('after_response.task_failed', $line['event']);
        self::assertSame('page_view', $line['context']['task']);
        self::assertSame(\RuntimeException::class, $line['context']['exception']);
        self::assertSame(503, $line['context']['status']);
        self::assertStringNotContainsString('Semaglutide', $log->contents());
    }

    public function testATaskQueuedByATaskRunsInTheSamePass(): void
    {
        $queue = new AfterResponse((new CapturedLog())->log);
        $ran = [];

        $queue->defer('outer', static function () use ($queue, &$ran): void {
            $ran[] = 'outer';
            $queue->defer('inner', static function () use (&$ran): void {
                $ran[] = 'inner';
            });
        });

        $queue->run();

        self::assertSame(['outer', 'inner'], $ran);
    }
}
