<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Verification;

/**
 * Walks the checks `config/verification.php` declares, in the order it
 * declares them, and returns the first decisive answer.
 *
 * The sequence is **configuration, not code**. `[22.15]` makes verification a
 * step in the flow definition rather than a fixed position, and grepping the
 * spec for a check order returns nothing at all — no vendor, no waterfall, no
 * retry policy. So the escalation this theme offers lives in a config file a
 * deployment can shorten, reorder or replace, and this class only knows the
 * rule for walking it:
 *
 * - a `passed` or `failed` verdict is decisive and stops the sequence;
 * - an `inconclusive` verdict escalates to the next check;
 * - running out of checks is itself inconclusive, **never** a refusal.
 *
 * That last one is the rule worth stating twice. Three checks that could not
 * decide anything do not add up to a decision, and under a blocking placement
 * the difference is whether a real buyer is asked again or turned away.
 *
 * **Each check is sent every field it names that the journey actually has** —
 * `required` and `narrowing` both. The distinction is recorded, not cosmetic:
 * called with its required fields alone, `crosscheck` comes back
 * `address_invalid` and `phone_invalid`, penalising two fields that were never
 * sent. Sending the minimum is a way to fail a check that was never going to
 * pass.
 *
 * **A check whose required fields the journey does not have is skipped**
 * rather than sent to be refused. The provider answers a missing required
 * field with a 400, which the gateway reads as inconclusive — the same outcome
 * skipping produces, minus a metered call and minus a warning about a bug that
 * does not exist. And nothing beyond what a check names is ever sent: an SSN
 * offered to a check that ignores it is an SSN handed to a provider for no
 * reason (`[22.21]`).
 */
final class IdentityVerificationSequence
{
    /** @var list<array{slug: string, required: list<string>, narrowing: list<string>}> */
    private readonly array $checks;

    /**
     * @param list<mixed> $checks `config/verification.php`'s `checks` list. Entries that
     *                            do not carry a non-empty `slug` are dropped rather than
     *                            fatal: this is a file an operator edits by hand, and a
     *                            typo in one entry must not take the step down.
     */
    public function __construct(private readonly IdentityGateway $gateway, array $checks)
    {
        $this->checks = self::normalise($checks);
    }

    /**
     * @param array<string, mixed> $identity the journey's buyer fields, under the names
     *                                       `config/verification.php` uses
     */
    public function run(array $identity): IdentityVerdict
    {
        $last = IdentityVerdict::inconclusive();

        foreach ($this->checks as $check) {
            if (!self::satisfies($identity, $check['required'])) {
                continue;
            }

            $verdict = $this->gateway->verify($check['slug'], self::payload($identity, $check));

            if (!$verdict->isInconclusive()) {
                return $verdict;
            }

            $last = $verdict;
        }

        return $last;
    }

    /**
     * The fields this check names, in the order it names them, limited to
     * those the journey holds.
     *
     * @param array<string, mixed>                                            $identity
     * @param array{slug: string, required: list<string>, narrowing: list<string>} $check
     *
     * @return array<string, mixed>
     */
    private static function payload(array $identity, array $check): array
    {
        $payload = [];

        foreach ([...$check['required'], ...$check['narrowing']] as $field) {
            if (self::present($identity, $field)) {
                $payload[$field] = $identity[$field];
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $identity
     * @param list<string>         $required
     */
    private static function satisfies(array $identity, array $required): bool
    {
        foreach ($required as $field) {
            if (!self::present($identity, $field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A blank string counts as absent, because a form that posts an empty
     * field is the ordinary case and `phone: ""` earns a 400 rather than an
     * answer.
     *
     * @param array<string, mixed> $identity
     */
    private static function present(array $identity, string $field): bool
    {
        $value = $identity[$field] ?? null;

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null && $value !== [];
    }

    /**
     * @param list<mixed> $checks
     *
     * @return list<array{slug: string, required: list<string>, narrowing: list<string>}>
     */
    private static function normalise(array $checks): array
    {
        $normalised = [];

        foreach ($checks as $check) {
            $slug = is_array($check) ? ($check['slug'] ?? null) : null;

            if (!is_string($slug) || $slug === '') {
                continue;
            }

            $normalised[] = [
                'slug' => $slug,
                'required' => self::fields(is_array($check) ? ($check['required'] ?? null) : null),
                'narrowing' => self::fields(is_array($check) ? ($check['narrowing'] ?? null) : null),
            ];
        }

        return $normalised;
    }

    /** @return list<string> */
    private static function fields(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $field): bool => is_string($field) && $field !== '',
        ));
    }
}
