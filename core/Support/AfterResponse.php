<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

/**
 * Work that should happen for this request but not before its response.
 *
 * Tracking calls the buyer has no reason to wait for — a page view, the start
 * of identity verification — are queued here and run once the page has been
 * sent. Under PHP-FPM, {@see self::run()} hands the response to the client with
 * `fastcgi_finish_request()` first, so the visitor's page is complete before
 * the first EMR call opens a connection; under any other SAPI the response has
 * already been emitted and flushed, and the tasks only hold the worker.
 *
 * **Only stateless calls belong here.** Journey state is saved before the
 * response leaves, so a task that writes to it writes to a copy nobody saves —
 * and a task that saved it again would race the next request the browser
 * makes, which can already be in flight. A call whose outcome the journey
 * records (the cart mirror's create-or-update flag) or whose order against
 * other calls matters (a checkout event before the order event) stays inline.
 *
 * The session is closed before the tasks run, because PHP's file sessions lock
 * for the life of the request and a slow EMR would otherwise hold up the
 * buyer's next page. A failing task is logged by class and status alone and
 * never stops the next one: the tasks are tracking, and tracking must never
 * break the storefront (`[20.1]`). The message is not taken, for the reason
 * {@see \AsterMD\Storefront\Emr\EmrCartGateway} gives (`[20.6]`).
 */
final class AfterResponse
{
    /** @var list<array{name: string, task: \Closure(): void}> */
    private array $tasks = [];

    public function __construct(private readonly OperatorLog $log)
    {
    }

    /** @param \Closure(): void $task */
    public function defer(string $name, \Closure $task): void
    {
        $this->tasks[] = ['name' => $name, 'task' => $task];
    }

    /** @return list<string> the queued tasks' names, in the order they will run */
    public function queued(): array
    {
        return array_column($this->tasks, 'name');
    }

    /**
     * Runs every queued task, in the order queued, and empties the queue.
     *
     * Called by `public/index.php` after the app has emitted its response.
     * Tasks queued by a task run in the same pass.
     */
    public function run(): void
    {
        if ($this->tasks === []) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        while (($entry = array_shift($this->tasks)) !== null) {
            try {
                ($entry['task'])();
            } catch (\Throwable $e) {
                $this->log->warning('after_response.task_failed', [
                    'task' => $entry['name'],
                    'exception' => $e::class,
                    'status' => $e->getCode(),
                ]);
            }
        }
    }
}
