<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

/**
 * Masks card data inside foreign data, whatever key it arrived under and
 * wherever inside a string it sits.
 *
 * This is the second of two defences and does not replace the first.
 * {@see OperatorLog::redact()} is key-based: it knows the names our own
 * structures use and blanks those values whole, which is exactly right for
 * data we shaped and useless for data we did not. A payment provider echoing
 * `gateway_request_text` -- by name, the request it handed to the acquiring
 * gateway -- returns one opaque string with a PAN somewhere in the middle of
 * it, under a key no redaction list would ever think to name. Nothing
 * key-based can see inside that string.
 *
 * So this pass looks at values instead of keys, in two sweeps:
 *
 * 1. **The security code and the expiry.** Neither is Luhn-checkable, so
 *    nothing below can find them by shape; they are found by the key they sit
 *    next to *inside* the string (`cvv=737`, `ccexp=1230`). PCI DSS calls the
 *    security code sensitive authentication data, which may not be stored at
 *    all -- a stricter rule than the one covering the number itself.
 * 2. **The number.** Every 13-19 digit run that passes a Luhn check is
 *    replaced, separators and all. Luhn is what keeps ordinary identifiers out
 *    of the match -- an order id, a transaction id, a timestamp -- because a
 *    log that masks its own references is a log nobody can follow. One
 *    identifier shape a single check digit is too weak to protect is exempted
 *    by shape instead; {@see self::UUID} says which and why.
 *
 * **Strings and arrays only, and values only.** Keys are left alone because
 * renaming them would change the shape an operator reads, and integers are
 * left alone because every recorded provider envelope carries card numbers as
 * strings while numeric identifiers arrive as integers -- matching those would
 * trade a leak we have never seen for a log we cannot read.
 *
 * Both sinks that outlive the request apply this themselves --
 * {@see OperatorLog::write()} and {@see \AsterMD\Storefront\Repository\EventRepository::append()}
 * -- so containment does not depend on a caller remembering to ask for it. Call
 * sites may still scrub earlier, and should where the value also reaches the
 * buyer (`[15.8]`).
 */
final class CardScrubber
{
    /** Replacement written in place of anything that looks like card data. */
    public const string MASK = '[redacted-card]';

    /**
     * Replacement for a string the regex engine could not finish scanning.
     *
     * A PCRE failure -- a backtrack limit, a string that is not valid UTF-8 --
     * means the string was never checked, and an unchecked string is exactly
     * the one that must not be written out. Dropping it whole is the safe
     * direction; losing one log value costs an operator context, and keeping
     * it can cost a card number.
     */
    public const string UNSCANNABLE = '[redacted: could not be scanned]';

    /** Shortest and longest PAN any scheme issues: 13 (old Visa) to 19 (co-branded Visa). */
    private const int PAN_MIN_DIGITS = 13;
    private const int PAN_MAX_DIGITS = 19;

    /**
     * A run of digits held together by single spaces, dashes or dots.
     *
     * `.` belongs here because {@see \AsterMD\Storefront\Payment\PaymentCredential::card()}
     * strips every non-digit from what the buyer typed, so a dotted number is
     * a live PAN by the time it reaches the provider and a live PAN when the
     * provider quotes it back.
     */
    private const string DIGIT_RUN = '/[0-9](?:[ \-.]?[0-9])*/';

    /**
     * A uuid in its canonical 8-4-4-4-12 form, which no card number takes.
     *
     * A uuid whose hex happens to be all digits is, to the pass below, a
     * dash-joined run of 32 digits; three of its groups add up to a PAN length
     * twice over (`8+4+4` and `4+12`), and a run of arbitrary digits passes
     * Luhn about one time in ten. So roughly one journey in ten had its
     * correlation identifier rewritten to
     * `[redacted-card]-[redacted-card]-111111111111`, and an identifier that
     * is silently wrong for an invisible fraction of journeys is worse than
     * no identifier at all -- the log still looks like it has one.
     *
     * Shape is what separates the two cases, which is why this is expressible
     * without weakening anything. A PAN is a *run of digits*, of any length
     * from 13 to 19, in whatever grouping the writer chose; a uuid is a
     * **fixed** five-group hex string of exactly 8-4-4-4-12 characters. No
     * scheme issues a number that can be written that way, and no provider
     * format quotes one that way, so exempting this shape exempts nothing a
     * card can wear.
     *
     * The exemption is for the shape and not for its neighbourhood: only the
     * digit groups *inside* a match are held back, so a real number quoted in
     * the same string is masked exactly as before. What it does cost, and the
     * cost is accepted rather than overlooked, is that a PAN deliberately
     * dressed as a uuid -- 16 of its digits laid out as the first three groups
     * or the last two of a 32-hex-character string -- would pass. That is not
     * a shape provider text produces; the leak this pass was built for was a
     * gateway echoing its own request format, not one disguising it.
     */
    private const string UUID = '/(?<![0-9A-Za-z-])[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}'
        . '-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}(?![0-9A-Za-z-])/';

    /**
     * The security code and the expiry, named by the key they sit beside.
     *
     * The value bound is a whitelist of the forms these two data actually
     * travel in, and nothing else: **one to four digits** (a `CVV`, a `CID`, a
     * lone month or year, `MMYY`), **exactly six** (`MMYYYY`, which is as
     * ordinary an encoding as `MMYY` and was the gap this bound was widened to
     * close), or **two parts joined by `/`, `-` or `.`** with optional spaces
     * (`12/30`, `12 / 30`, `12/2030`, `12.2030`, `2030-12`).
     *
     * What it deliberately refuses is a run of five digits or of seven or
     * more: neither is any expiry or security-code encoding, so a number that
     * long under one of these names is some other datum and is left whole
     * rather than half-masked. `exp=1234567` stays readable.
     *
     * The key must also start on a token boundary, because `exp` is short
     * enough to be the tail of an unrelated name -- `regexp=1230` is not an
     * expiry. The accepted cost of the six-digit form is that a bare `exp` key
     * carrying an unrelated six-digit number is masked; a plausibility rule on
     * the month and year would narrow that, at the price of leaking a real
     * expiry in whatever encoding the rule failed to anticipate, which is the
     * worse way to be wrong here.
     */
    private const string SENSITIVE_PAIR = '/(?<![A-Za-z0-9_])'
        . '(cvv2?|cvc2?|cid|card_cvv|card_cvc|security_code|ccexp|exp|expiry|expiration'
        . '|exp_month|exp_year|card_exp_month|card_exp_year|card_expiry|card_expiration)'
        . '(["\']?\s*[:=]\s*["\']?)'
        . '((?:[0-9]{1,4}\s*[\/\-.]\s*[0-9]{2,4})|[0-9]{6}|[0-9]{1,4})(?![0-9])/i';

    /**
     * The same value with every piece of card data masked.
     *
     * Recurses into arrays, preserving keys and the type of everything it does
     * not touch, so the result can be logged or rendered in place of the
     * original.
     */
    public static function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $out[$key] = self::scrub($item);
            }

            return $out;
        }

        if (!is_string($value)) {
            return $value;
        }

        $withoutCodes = preg_replace_callback(
            self::SENSITIVE_PAIR,
            static fn (array $match): string => $match[1] . $match[2] . self::MASK,
            $value,
        );

        if ($withoutCodes === null) {
            return self::UNSCANNABLE;
        }

        return self::maskCardNumbers($withoutCodes);
    }

    /**
     * The same array with every piece of card data masked, typed as an array.
     *
     * A convenience for the sinks, which have an array in hand and need an
     * array back; {@see self::scrub()} is `mixed` in and `mixed` out because it
     * recurses through values of any type.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    public static function scrubArray(array $value): array
    {
        /** @var array<array-key, mixed> */
        return self::scrub($value);
    }

    /**
     * Masks every card-number-shaped window inside a string.
     *
     * The defect this replaces was structural rather than a bad pattern: Luhn
     * cannot be expressed as a backtrack condition, so a single regex that
     * matched a whole digit run could only ever test that run *as a whole*. A
     * PAN followed by a space and the security code is one run, fails Luhn
     * together, and was written out untouched -- which is the common shape, not
     * the exotic one.
     *
     * So the run is decomposed into its unbroken digit groups and every
     * contiguous span of groups totalling 13-19 digits is Luhn-tested, longest
     * first, so a 16-digit number is still found inside `PAN SP CVV`. Spans are
     * tested at group boundaries and never inside a group, which keeps the
     * property the old lookarounds were there for: a window is never plucked
     * out of the middle of an unbroken number, so a 20-digit identifier stays
     * whole and readable instead of losing a Luhn-passing slice of itself
     * roughly nine times in ten.
     *
     * **The known, priced limit.** A PAN glued to its own expiry or security
     * code with no separator at all -- one unbroken run of, say, 19 or 22
     * digits, of the kind a fixed-width gateway echo could carry -- is not
     * found, because nothing distinguishes it from a long identifier except
     * testing more windows, and the arithmetic of that is unarguable: Luhn is a
     * single check digit, so one decimal digit of evidence, and each extra
     * window tested costs about ten percentage points of false-positive rate
     * (measured `1 - 0.9^k` for `k` windows, to within half a point). Testing
     * every offset masks part of a random 22-digit identifier 99% of the time;
     * testing only the leading and trailing windows, 77%; restricting those to
     * the lengths a trailing field group would leave, 72%. Even gating on an
     * issuer prefix -- the one independent piece of evidence available -- only
     * reaches 20-28%, against 0% today, and buys it with a table of issuer
     * ranges that silently ages. Every one of those trades a certainty (the log
     * an operator has to read to reconcile a charge) for a probability, so the
     * containment for this shape is that separators, quotes, tags and
     * delimiters are what real provider formats use, and all of those are
     * found. It is a decision, not an oversight.
     */
    private static function maskCardNumbers(string $value): string
    {
        $runs = preg_match_all(self::DIGIT_RUN, $value, $matches, PREG_OFFSET_CAPTURE);

        if ($runs === false) {
            return self::UNSCANNABLE;
        }

        $exempt = self::uuidRanges($value);

        if ($exempt === null) {
            return self::UNSCANNABLE;
        }

        $spans = [];

        /** @var array{0: string, 1: int} $run */
        foreach ($matches[0] as $run) {
            foreach (self::maskedSpansIn($run[0], $run[1], $exempt) as [$start, $length]) {
                $spans[] = [$run[1] + $start, $length];
            }
        }

        if ($spans === []) {
            return $value;
        }

        // Built in one pass rather than by repeated splicing, so a value that is
        // nothing but card-shaped digits costs time in proportion to its length
        // and not to its length times the number of numbers in it.
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = '';
        $cursor = 0;

        foreach ($spans as [$start, $length]) {
            $out .= substr($value, $cursor, $start - $cursor) . self::MASK;
            $cursor = $start + $length;
        }

        return $out . substr($value, $cursor);
    }

    /**
     * The `[offset, length]` spans of one digit run that are card numbers.
     *
     * Returned in no particular order and guaranteed not to overlap: once a
     * span is claimed, no later span may reuse any of its groups, so the
     * longest Luhn-valid reading of the run wins and the rest of the run stays
     * readable.
     *
     * $runOffset is where $run starts in the whole value, so the digit groups
     * can be placed against the $exempt ranges, which are absolute.
     *
     * @param list<array{0: int, 1: int}> $exempt half-open `[start, end)` byte ranges no span may enter
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function maskedSpansIn(string $run, int $runOffset, array $exempt): array
    {
        if (preg_match_all('/[0-9]+/', $run, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        /** @var list<array{0: string, 1: int}> $groups */
        $groups = $matches[0];
        $count = count($groups);

        // Which groups sit inside a uuid, resolved once. A group is held back
        // whole or not at all: a uuid's groups never straddle its boundary,
        // and a digit run that reaches past one -- a uuid and a number joined
        // by the single space {@see self::DIGIT_RUN} tolerates -- must still
        // have its other groups read normally, or quoting a reference beside a
        // number would hide the number.
        $held = [];

        for ($index = 0; $index < $count; $index++) {
            $start = $runOffset + $groups[$index][1];
            $end = $start + strlen($groups[$index][0]);

            foreach ($exempt as [$from, $to]) {
                if ($start >= $from && $end <= $to) {
                    $held[$index] = true;
                    break;
                }
            }
        }

        // Every contiguous span of groups whose combined digit count could be a
        // PAN, bucketed by that count. Bucketing rather than sorting is what
        // makes this method's cost linear in the length of the run: there are
        // only seven possible PAN lengths, and within a bucket the spans are
        // already leftmost-first.
        $candidates = array_fill(self::PAN_MIN_DIGITS, self::PAN_MAX_DIGITS - self::PAN_MIN_DIGITS + 1, []);

        for ($first = 0; $first < $count; $first++) {
            if (isset($held[$first])) {
                continue;
            }

            $digits = '';

            for ($last = $first; $last < $count; $last++) {
                if (isset($held[$last])) {
                    break;
                }

                $digits .= $groups[$last][0];
                $length = strlen($digits);

                if ($length > self::PAN_MAX_DIGITS) {
                    break;
                }

                if ($length >= self::PAN_MIN_DIGITS) {
                    $candidates[$length][] = [$first, $last, $digits];
                }
            }
        }

        // Longest first, then leftmost, so `PAN SP CVV` reads as the PAN rather
        // than as two unrelated fragments of it. `$claimed` is a set keyed by
        // group index rather than a list, because a membership test that walks
        // the claims turns a long digit run into quadratic work -- and a
        // provider echoing a large field back is exactly how that gets reached.
        $spans = [];
        $claimed = [];

        for ($length = self::PAN_MAX_DIGITS; $length >= self::PAN_MIN_DIGITS; $length--) {
            foreach ($candidates[$length] as [$first, $last, $digits]) {
                if (self::anyClaimed($claimed, $first, $last) || !self::isLuhnValid($digits)) {
                    continue;
                }

                $start = $groups[$first][1];
                $spans[] = [$start, $groups[$last][1] + strlen($groups[$last][0]) - $start];

                for ($index = $first; $index <= $last; $index++) {
                    $claimed[$index] = true;
                }
            }
        }

        if ($spans === []) {
            return $spans;
        }

        // A run that holds a card number holds the rest of the card: a gateway
        // request dumped as space-separated fields puts the security code and
        // the expiry right beside it, and neither is Luhn-checkable or named
        // here, so shape alone would never find them. Bounded to short groups
        // inside a run already proven to contain a PAN, which is why this
        // cannot reach an identifier an operator needs.
        for ($index = 0; $index < $count; $index++) {
            if (isset($claimed[$index]) || isset($held[$index]) || strlen($groups[$index][0]) > 4) {
                continue;
            }

            $spans[] = [$groups[$index][1], strlen($groups[$index][0])];
        }

        return $spans;
    }

    /**
     * The half-open `[start, end)` byte ranges of every uuid in $value, or
     * null when the scan could not be completed.
     *
     * Null rather than an empty list on failure, for the reason
     * {@see self::UNSCANNABLE} exists: an empty list would read as "no uuids
     * here" and let the masking proceed on a string nothing has checked, so
     * the two failure directions have to be distinguishable.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    private static function uuidRanges(string $value): ?array
    {
        if (preg_match_all(self::UUID, $value, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $ranges = [];

        /** @var array{0: string, 1: int} $match */
        foreach ($matches[0] as $match) {
            $ranges[] = [$match[1], $match[1] + strlen($match[0])];
        }

        return $ranges;
    }

    /**
     * Whether any group in `[$first, $last]` is already part of a masked span.
     *
     * @param array<int, true> $claimed
     */
    private static function anyClaimed(array $claimed, int $first, int $last): bool
    {
        for ($index = $first; $index <= $last; $index++) {
            if (isset($claimed[$index])) {
                return true;
            }
        }

        return false;
    }

    /** Whether a run of digits satisfies the Luhn check digit. */
    private static function isLuhnValid(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
