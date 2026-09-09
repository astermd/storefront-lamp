<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Retention;

/**
 * The log directory is there and cannot be listed, so the sweep never happened.
 *
 * This is a type rather than a return value because the two states it separates
 * are indistinguishable from outside and opposite in meaning. A *missing*
 * directory is a deployment that has never written a log — provably nothing to
 * expire, and {@see LogSweep} answers zero for it on purpose. A directory that
 * exists and cannot be opened is a sweep that produced no answer at all, and
 * "zero files were old enough" is the one thing it must never be reported as:
 * what survives is the file `[30.9]` names, holding verbatim card numbers and
 * Social Security Numbers, with nothing else scheduled to remove it.
 *
 * **The class name is the whole diagnostic.** `db:prune` runs from cron and
 * reports a failure through {@see \AsterMD\Storefront\Support\FailureDigest},
 * which prints the exception's class and never its message — so this is named
 * to be read as a console line by an operator who has no other record of the
 * run. The message adds the directory for a stack trace and nothing else: a
 * path this application was configured with is an identifier, not row content,
 * which is the same rule {@see \AsterMD\Storefront\Journey\JourneyWriteFailed}
 * states for what its own messages may carry.
 */
final class LogDirectoryUnreadable extends \RuntimeException
{
    public static function at(string $directory): self
    {
        return new self('The log directory exists but could not be listed: ' . $directory);
    }
}
