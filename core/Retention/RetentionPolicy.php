<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Retention;

use AsterMD\Storefront\Support\Config;

/**
 * The configured retention periods, read in one place.
 *
 * `[30.5]` makes the periods configuration because the correct one varies by
 * jurisdiction; `[30.6]` makes them enforced by a job because data that is
 * merely supposed to expire does not expire. This class is the seam between
 * the two — `config/retention.php` states the policy, `db:prune` carries it
 * out, and neither reads the other's shape.
 *
 * Every period is expressed as a **cutoff timestamp** rather than a day
 * count, because that is what a caller actually needs and because deriving it
 * once here means the local database, the audit trail and the log directory
 * cannot end up comparing against three slightly different "now"s in one run.
 *
 * A period of zero or less disables that expiry rather than deleting
 * everything. Deleting the whole table is never what an operator meant by
 * setting a period to nothing, and the failure would be unrecoverable.
 */
final class RetentionPolicy
{
    /** Cutoffs are ISO-8601 UTC, matching the fixed-width format every timestamp column stores. */
    public const string TIMESTAMP_FORMAT = 'c';

    /**
     * How recent a journey has to be before no configured period may reach it.
     *
     * `[30.5]` lets a deployment choose its own retention, and a short one is
     * a legitimate choice — but a period is a statement about *age*, and there
     * is a window in which age is not the only thing that matters. Both
     * reconciliation sweeps work backwards from now over recent activity:
     * {@see \AsterMD\Storefront\Reconciliation\ReverseReconciliation} asks the
     * provider what it charged in the last two days and looks for the local
     * rows, and {@see \AsterMD\Storefront\Reconciliation\ForwardReconciliation}
     * escalates an order still unsynced a day after placement. A session
     * deleted inside that window is a charge whose journey no longer exists to
     * be matched to — and unlike a row that expires a week early, nothing can
     * recover it.
     *
     * Two days, which is the widest of those windows. It is a floor and not a
     * ceiling: a period longer than this is honoured as written, and only one
     * shorter is held back. `RetentionPolicyTest` reads both sweeps'
     * constructor defaults and fails if either grows past this number, so the
     * two cannot drift apart in silence.
     *
     * It does not cover an operator who widens a single reverse sweep with
     * `--hours`; that run reads further back than anything here knows about,
     * and the answer to it is to run the sweep before the prune rather than to
     * pick a number large enough for every possible flag.
     */
    public const int RECONCILIATION_FLOOR_SECONDS = 172_800;

    public function __construct(
        private readonly int $sessionDays,
        private readonly int $eventDays,
        private readonly int $logDays,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            (int) $config->get('retention.operational.session_days', 90),
            (int) $config->get('retention.operational.event_days', 365),
            (int) $config->get('retention.operational.log_days', 30),
        );
    }

    public function sessionDays(): int
    {
        return $this->sessionDays;
    }

    public function eventDays(): int
    {
        return $this->eventDays;
    }

    public function logDays(): int
    {
        return $this->logDays;
    }

    /**
     * The cutoff for analytics session rows, or null when session expiry is
     * disabled.
     *
     * Compared against `sessions.updated_at` rather than `created_at`: a
     * journey's age for retention purposes is when it last moved, and
     * `JourneyStore` only writes when the state's fingerprint changed, so
     * that column is a true "last moved" rather than "last seen".
     *
     * Held back by {@see self::RECONCILIATION_FLOOR_SECONDS}, so a period
     * shorter than the reconciliation window cannot delete the journey behind
     * a charge that is still being matched.
     */
    public function sessionCutoff(?int $now = null): ?string
    {
        return $this->floored(self::cutoff($this->sessionDays, $now), $now);
    }

    /**
     * The cutoff for audit-trail rows, or null when event expiry is disabled.
     *
     * Held back by the same floor as the sessions, and for a related reason:
     * the trail is what an investigation into a mismatched order reads once
     * the sweep has found one, so expiring it inside the window would answer
     * the sweep's question with silence.
     */
    public function eventCutoff(?int $now = null): ?string
    {
        return $this->floored(self::cutoff($this->eventDays, $now), $now);
    }

    /**
     * The cutoff for files under `storage/logs/`, or null when log expiry is
     * disabled.
     *
     * Deliberately not held back by the reconciliation floor. Neither sweep
     * reads a log file — they read `orders` and the provider — and the wire
     * log is the one artefact on this list that can hold verbatim card and
     * identity payloads, so a deployment that wants it gone in a day gets it
     * gone in a day.
     */
    public function logCutoff(?int $now = null): ?string
    {
        return self::cutoff($this->logDays, $now);
    }

    private static function cutoff(int $days, ?int $now): ?string
    {
        if ($days <= 0) {
            return null;
        }

        return gmdate(self::TIMESTAMP_FORMAT, ($now ?? time()) - ($days * 86400));
    }

    /**
     * The cutoff, or the reconciliation floor when the cutoff is more recent
     * than it.
     *
     * A string comparison rather than arithmetic on the two timestamps,
     * because that is the same comparison the sweep itself makes: the format
     * is fixed-width UTC, so lexicographic order is chronological order, and
     * keeping both ends of the decision in one representation means the value
     * that is compared is the value that was clamped.
     */
    private function floored(?string $cutoff, ?int $now): ?string
    {
        if ($cutoff === null) {
            return null;
        }

        $floor = gmdate(self::TIMESTAMP_FORMAT, ($now ?? time()) - self::RECONCILIATION_FLOOR_SECONDS);

        return $cutoff > $floor ? $floor : $cutoff;
    }
}
