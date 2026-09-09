<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Verification;

use AsterMD\Storefront\Journey\JourneyState;

/**
 * What one identity check said (`[22.20]`).
 *
 * **Three states, not two**, and this object exists because collapsing them is
 * the defect that matters here. Recorded live on 2026-08-25 against the
 * configured provider: every identity call returns HTTP 200 and the answer is
 * only ever in the body, `valid` is nullable, and a request the storefront
 * built wrongly comes back as a 400 exception rather than a verdict at all. So
 * "did not pass" covers a refused identity, a provider that answered without
 * deciding, and our own bug — and `[20.1]` says only the first of those may be
 * shown to a buyer as a failed check or used to block one.
 *
 * The statuses are {@see JourneyState}'s own vocabulary rather than a private
 * set, because the step writes this straight onto the journey and
 * {@see JourneyState::recordVerification()} refuses anything outside it. A
 * private set would let the two drift and fail at runtime instead of here.
 *
 * `score` and `threshold` are carried exactly as the provider sent them,
 * unrounded and un-coerced: crosscheck is recorded scoring exactly `0` against
 * a threshold of `0.75`, and rounding either would make "below threshold"
 * unprovable from the compliance record afterwards.
 *
 * `reasons` are the provider's **codes only**. The messages beside them are
 * free-form text that can quote the checked identity back at us, and `[22.21]`
 * keeps identity detail out of anything that gets logged — so the message is
 * dropped at the boundary rather than trusted not to be echoed later.
 */
final class IdentityVerdict
{
    /** @param list<string> $reasons */
    private function __construct(
        public readonly string $status,
        public readonly ?string $check,
        public readonly ?string $basis,
        public readonly int|float|null $score,
        public readonly int|float|null $threshold,
        public readonly array $reasons,
        public readonly bool $cached,
    ) {
    }

    /**
     * Reads one `verifyIdentity()` response body.
     *
     * `valid` is compared strictly against `true` and `false`. Anything else —
     * null, absent, a truthy string — is inconclusive, because nothing in the
     * envelope promises the field is a boolean and a loose read of a garbled
     * body would either open a blocking placement or refuse a real buyer.
     *
     * `$check` is what we asked for; `$data['check']` is what the provider says
     * it ran, and the echo wins where present. A `slug` inside the payload is
     * recorded as ignored — the enum decides — so the echo is the only honest
     * record of which check produced this answer.
     *
     * @param string              $check the check slug that was requested
     * @param array<string, mixed> $data  the response envelope's `data()`
     */
    public static function fromProviderData(string $check, array $data): self
    {
        $valid = $data['valid'] ?? null;
        $echoed = $data['check'] ?? null;
        $basis = $data['basis'] ?? null;

        return new self(
            status: match (true) {
                $valid === true => JourneyState::VERIFICATION_PASSED,
                $valid === false => JourneyState::VERIFICATION_FAILED,
                default => JourneyState::VERIFICATION_INCONCLUSIVE,
            },
            check: is_string($echoed) && $echoed !== '' ? $echoed : $check,
            basis: is_string($basis) && $basis !== '' ? $basis : null,
            score: self::number($data['score'] ?? null),
            threshold: self::number($data['threshold'] ?? null),
            reasons: self::reasons($data['reasons'] ?? null),
            cached: ($data['cached'] ?? null) === true,
        );
    }

    /**
     * A check that produced no answer about the buyer: it could not run, the
     * provider declined to decide, or the sequence ran out of checks.
     *
     * `$reason` is a code of our own (`unavailable`, `request_rejected`) rather
     * than the provider's, so an operator reading the record can tell an
     * outage from a refusal without the two sharing a vocabulary.
     */
    public static function inconclusive(?string $check = null, ?string $reason = null): self
    {
        return new self(
            status: JourneyState::VERIFICATION_INCONCLUSIVE,
            check: $check,
            basis: null,
            score: null,
            threshold: null,
            reasons: $reason === null || $reason === '' ? [] : [$reason],
            cached: false,
        );
    }

    /** True only for a provider verdict of `true` — never for a check that did not run. */
    public function isPassed(): bool
    {
        return $this->status === JourneyState::VERIFICATION_PASSED;
    }

    /** True only for a provider verdict of `false`: the one state that is a statement about the buyer. */
    public function isFailed(): bool
    {
        return $this->status === JourneyState::VERIFICATION_FAILED;
    }

    public function isInconclusive(): bool
    {
        return $this->status === JourneyState::VERIFICATION_INCONCLUSIVE;
    }

    /**
     * Everything about this verdict that is safe to write down, and nothing
     * else (`[22.21]`).
     *
     * What is absent is the point: not one field of the identity that produced
     * it, and not one line of provider prose. The outcome, which check reached
     * it, on what basis, against what numbers, and whether the provider was
     * actually called are what an operator needs and all they get.
     *
     * @return array{check: ?string, status: string, basis: ?string, score: int|float|null, threshold: int|float|null, reasons: list<string>, cached: bool}
     */
    public function logContext(): array
    {
        return [
            'check' => $this->check,
            'status' => $this->status,
            'basis' => $this->basis,
            'score' => $this->score,
            'threshold' => $this->threshold,
            'reasons' => $this->reasons,
            'cached' => $this->cached,
        ];
    }

    /** Numbers pass through untouched; anything else is dropped rather than cast to a misleading zero. */
    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * The coded signals, with anything uncoded dropped.
     *
     * @return list<string>
     */
    private static function reasons(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $codes = [];

        foreach ($raw as $reason) {
            $code = is_array($reason) ? ($reason['code'] ?? null) : null;

            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
