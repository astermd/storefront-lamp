<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Journey;

/**
 * A journey write that this application refused or could not account for, as
 * opposed to one the database driver rejected.
 *
 * The distinction is the whole reason this class exists, and it is a
 * containment boundary rather than a taxonomy. Journey state holds clinical
 * answers, buyer contact details and the provider's credential handle in one
 * JSON column. When a *driver* rejects that write it is entitled to quote what
 * it refused — MySQL's 1366 names the column and the offending value bytes —
 * so a driver message is foreign text about our most sensitive row, and
 * {@see \AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware} discards it
 * unread (`[20.6]`, `[22.21]`).
 *
 * A message carried by *this* class is different in kind: it is composed here,
 * from values this application already chose to log, and it can therefore be
 * reported in full. Making that a type rather than a judgement call is what
 * stops the two being confused later — the middleware asks "is this ours?"
 * and gets an answer it cannot get wrong, instead of pattern-matching on
 * message text or trusting that a `RuntimeException` from somewhere in the
 * stack was not wrapping a driver.
 *
 * Anything thrown as this class must therefore obey one rule: **its message is
 * composed from identifiers, never from row content and never from another
 * system's error text.** A session uuid is fine; an answer, an email address,
 * a card or a driver string is not.
 */
final class JourneyWriteFailed extends \RuntimeException
{
}
