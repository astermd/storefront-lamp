<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Retention;

/**
 * `[30.6]`'s third category: the files under `storage/logs/`.
 *
 * The rule names "debug logs" alongside sessions and events, and `[30.9]`
 * insists deletion reaches the copies that are easy to forget. The wire log is
 * the one that earns this class: it ships off, `config:validate` refuses it
 * outright, and a deployment that ever turned it on has verbatim card and
 * identity payloads sitting on disk with nothing scheduled to remove them.
 * The operator log is the same shape one level down — key-based redaction
 * cannot see inside a driver's sentence, so the value-based pass is the only
 * thing between a quoted row and a durable file.
 *
 * **Age is the file's own modification time**, not a date parsed out of its
 * name. Rotated wire logs are named by day, `app.log` is not named by anything,
 * and a rule that only worked on the ones with a date in the name would leave
 * the unrotated file — the one being appended to right now, and therefore the
 * one holding today's payloads — outside the policy. Modification time answers
 * for both: a file still being written to is never old, and one nothing has
 * touched since its period expired is.
 *
 * **Only regular files directly inside the directory.** Directories are left
 * alone, so an operator's own `archive/` is not something a retention job
 * decides about; and dotfiles are skipped, because `.gitignore` is what keeps
 * this directory in the repository at all and taking it would make the next
 * checkout's storage layout depend on whether the pruner had run.
 *
 * **A missing directory is not a failure; an unlistable one is.** A deployment
 * that has never written a log and one whose directory an operator removed both
 * have nothing to expire, and neither is something a scheduler should be woken
 * for. A directory that is *there* and cannot be opened is the opposite state
 * wearing the same clothes, and {@see LogDirectoryUnreadable} says why the
 * difference is worth a type.
 */
final class LogSweep
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * How many files {@see self::expire()} would take, without taking them.
     *
     * Shares that method's candidate list, so the dry run and the delete
     * cannot describe different sweeps — including the refusal, so an operator
     * who dry-runs against a directory nothing can list hears about it before
     * they reach for `--apply`.
     *
     * @throws LogDirectoryUnreadable
     */
    public function countExpirable(string $modifiedBefore): int
    {
        return count($this->expirable($modifiedBefore));
    }

    /**
     * @return int files deleted — a file the filesystem refuses to unlink is
     *             not counted, because the count is what the operator is told
     *             was removed
     *
     * @throws LogDirectoryUnreadable
     */
    public function expire(string $modifiedBefore): int
    {
        $deleted = 0;

        foreach ($this->expirable($modifiedBefore) as $path) {
            if (@unlink($path)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * The files old enough to take.
     *
     * The cutoff arrives as the same ISO-8601 string every other sweep here
     * compares against — {@see RetentionPolicy} derives all three from one
     * instant — and is turned back into a Unix timestamp once, rather than
     * every file's `mtime` being formatted for a string comparison. A cutoff
     * that cannot be parsed yields no candidates, which fails closed: the run
     * reports zero rather than treating "unknown" as "everything".
     *
     * **A directory that cannot be listed throws.** `scandir()` answers
     * `false` there, and reading that as an empty listing was a silent zero of
     * the worst available shape: `is_dir()` above cannot catch it, because it
     * needs execute permission on the *parent* rather than read permission
     * here, so the guard passes and the sweep reports that nothing was old
     * enough while the wire log survives. The same policy the database sweep
     * beside this one already follows — {@see RetentionSweep} lets its driver
     * exceptions out and `db:prune` turns them into a non-zero exit — because
     * the one thing a scheduler must never read as "there was nothing to
     * prune" is "I could not look".
     *
     * The warning is suppressed because the throw is the signal: an
     * installation whose INI hides warnings would see nothing, and one that
     * promotes them to exceptions would see a generic `ErrorException` in
     * place of a class named after the fault.
     *
     * @return list<string> absolute paths
     *
     * @throws LogDirectoryUnreadable when the directory is there and cannot be opened
     */
    private function expirable(string $modifiedBefore): array
    {
        $cutoff = strtotime($modifiedBefore);
        if ($cutoff === false || !is_dir($this->directory)) {
            return [];
        }

        $entries = @scandir($this->directory);

        if ($entries === false) {
            throw LogDirectoryUnreadable::at($this->directory);
        }

        $expirable = [];

        foreach ($entries as $entry) {
            if (!is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $this->directory . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }

            $modified = @filemtime($path);
            if ($modified !== false && $modified < $cutoff) {
                $expirable[] = $path;
            }
        }

        return $expirable;
    }
}
