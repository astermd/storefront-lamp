<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

/**
 * How a failure is named where its message may not be repeated.
 *
 * Several boundaries in this application must report that something failed
 * without reporting what it said: a database driver refusing a write to the
 * journey column is entitled to quote the row back at us, and that row holds
 * clinical answers, buyer contact details, the provider's credential handle
 * and — since identity verification — a Social Security Number. Neither
 * defence at the log sink can see inside that string. {@see OperatorLog}'s
 * redaction is key-based, and {@see CardScrubber} masks by Luhn check, so it
 * catches a card number and nothing else.
 *
 * `[20.6]` and `[22.21]` therefore forbid the message, and
 * {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter} states the
 * replacement: the exception class and its status answer what an outage
 * actually asks — which failure, how often, since when — and cannot carry text
 * somebody else wrote about our data.
 *
 * This exists because that replacement was written six times. Two middlewares
 * and four scheduled commands each grew their own copy, and this codebase has
 * been bitten before by several readers of one rule agreeing only by
 * coincidence: {@see \AsterMD\Storefront\Funnel\FunnelRules} exists for exactly
 * that reason, after four copies of a funnel rule had quietly drifted apart.
 * A containment rule is a worse thing to let drift than a routing one.
 *
 * **What may be added here, and what may not.** Every value this returns is
 * drawn from the exception's *type* or from a driver's fixed status
 * vocabulary. Nothing composed by another system may join them — not a message,
 * not a query, not a parameter list, however useful it would be to a person
 * reading a log at three in the morning.
 */
final class FailureDigest
{
    /**
     * The exception's class, plus the driver's SQLSTATE when there is one.
     *
     * Shaped for a human reading a console line or a log entry:
     * `PDOException (SQLSTATE HY000)`. {@see self::sqlState()} is the machine
     * -readable half for a structured context array.
     */
    public static function describe(\Throwable $e): string
    {
        $state = self::sqlState($e);

        return $state === null ? $e::class : $e::class . ' (SQLSTATE ' . $state . ')';
    }

    /**
     * The driver's five-character SQLSTATE, when there is one.
     *
     * Restricted to {@see \PDOException} because that is the only class here
     * whose code is a SQLSTATE; every other exception returns an integer from
     * `getCode()`, and a field that is sometimes an error class and sometimes a
     * line number is worse than one that is sometimes absent.
     *
     * `errorInfo` is preferred and `getCode()` is the fallback because the two
     * are populated by different things. A driver raising a real error sets
     * both; a `PDOException` raised anywhere else — including by this
     * application's own connection factory — may set neither, and answering
     * null is the honest result rather than reporting a zero as though it were
     * a state. Both are driver vocabulary carrying no free text, which is the
     * property that lets either be reported at all.
     */
    public static function sqlState(\Throwable $e): ?string
    {
        if (!$e instanceof \PDOException) {
            return null;
        }

        $state = $e->errorInfo[0] ?? null;

        if (is_string($state) && $state !== '') {
            return $state;
        }

        $code = $e->getCode();

        return is_string($code) && $code !== '' ? $code : null;
    }
}
