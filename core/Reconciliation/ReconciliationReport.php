<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Reconciliation;

/**
 * What one forward sweep found and what it managed to do about it.
 *
 * Two kinds of figure live here and they are not interchangeable, which is why
 * they are named apart rather than summed into a single "outstanding":
 *
 * - **{@see self::$unsynced}, {@see self::$backlog}, {@see self::$repeatFailures}
 *   and {@see self::$unreconcilable} describe the whole table.** These are
 *   `[20.12]`'s monitored counts. They are deliberately not bounded by the
 *   batch size, because a figure that was would read as healthy on exactly the
 *   deployment whose arrears had overflowed one run.
 * - **{@see self::$examined}, {@see self::$synced}, {@see self::$failed} and
 *   {@see self::$skipped} describe this run.** They are bounded by the batch
 *   size and say what the sweep actually touched.
 *
 * `backlog` and `unreconcilable` are disjoint. An order with no analytics
 * session cannot be synced by any number of retries — the EMR keys a treatment
 * record on the session uuid, and `[20.8]` forbids inventing one — so it is a
 * permanent gap for an operator to resolve by hand rather than a queue that
 * will drain. Adding the two together would report a growing backlog that no
 * amount of running this job could ever reduce.
 */
final readonly class ReconciliationReport
{
    /**
     * @param int          $unsynced                 orders the EMR has never been told about, at all
     * @param int          $backlog                  of those, the ones past the grace window that a retry could still fix
     * @param int          $repeatFailures           of those, the ones old enough that every attempt so far has failed (`[21.8]` (c))
     * @param int          $unreconcilable           never told, and with no session to tell anyone under
     * @param int          $examined                 rows this run read, bounded by the batch size
     * @param int          $synced                   rows that carried a treatment reference afterwards
     * @param int          $failed                   rows that were attempted and are still null
     * @param int          $skipped                  rows this run refused to attempt, with a reason logged for each
     * @param list<string> $repeatFailureReferences  the aged references this run saw, for the operator line
     * @param bool         $dryRun                   whether anything was actually sent
     */
    public function __construct(
        public int $unsynced = 0,
        public int $backlog = 0,
        public int $repeatFailures = 0,
        public int $unreconcilable = 0,
        public int $examined = 0,
        public int $synced = 0,
        public int $failed = 0,
        public int $skipped = 0,
        public array $repeatFailureReferences = [],
        public bool $dryRun = true,
    ) {
    }

    /**
     * The report as the operator log and the console both render it.
     *
     * One shape for both destinations on purpose: an operator reading a log
     * line and an operator reading the command's output are answering the same
     * question, and two spellings of the same counts is how they drift.
     *
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'unsynced' => $this->unsynced,
            'backlog' => $this->backlog,
            'repeat_failures' => $this->repeatFailures,
            'unreconcilable' => $this->unreconcilable,
            'examined' => $this->examined,
            'synced' => $this->synced,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'dry_run' => $this->dryRun,
        ];
    }
}
