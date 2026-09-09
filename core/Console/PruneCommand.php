<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Support\FailureDigest;

use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Retention\LogSweep;
use AsterMD\Storefront\Retention\RetentionPolicy;
use AsterMD\Storefront\Retention\RetentionSweep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `db:prune` — the sweep that takes aged rows out of the tables nothing has
 * ever deleted from, and the aged files out of `storage/logs/`.
 *
 * `checkout_attempts` and `rate_limits` are both written on the checkout path
 * and neither is ever cleaned. Every idempotency key ever claimed and every
 * client address ever counted is still there, which is unbounded growth on the
 * one hand and, on the other, a durable list of addresses this storefront has
 * no use for once their window has closed.
 *
 * `sessions`, `events` and the log directory are `[30.6]`'s three named
 * categories of operational data, and until this command reached them they had
 * a stated retention and no enforcement — which `config/retention.php` says
 * plainly is the same as no retention. Their periods come from that file
 * through {@see RetentionPolicy} (`[30.5]`), not from the options below: an
 * operator changing how long a person's journey is kept is making a policy
 * decision that belongs in configuration where it can be reviewed, not in a
 * crontab flag. **A period of zero disables that expiry**, and the command
 * says so in its output rather than reporting a silent zero that could equally
 * mean "nothing was old enough".
 *
 * **The interesting half of this command is what it refuses to touch.** An
 * attempt row is the serialisation point that stops one submission becoming
 * two charges against a provider with no idempotency of its own, so a sweep
 * that took the wrong row would not merely lose data — it would re-arm the
 * double charge. {@see CheckoutAttemptRepository::expire()} carries the
 * reasoning state by state; the short of it is that a `sent` attempt is never
 * deleted at any age, because the provider was contacted and never answered
 * and no amount of time settles whether money moved. Those rows are counted
 * and reported instead, because each one is also a cart its buyer cannot
 * retry, and both halves of that need a person rather than a timer.
 *
 * **Sessions carry the same shape of refusal, twice over, and for higher
 * stakes.** A deleted attempt re-arms a double charge; a deleted session row
 * is a journey that cannot be resumed and an order that cannot be reconciled,
 * and nothing can put it back. So a session an `orders` row references is
 * never taken at any age — {@see RetentionSweep} holds that predicate, and the
 * rows it holds back are counted and named below — and no configured period
 * may reach inside the window either reconciliation sweep still reads, which
 * is {@see RetentionPolicy::RECONCILIATION_FLOOR_SECONDS}.
 *
 * **Dry run by default**, matching `theme:sync` and `emr:reconcile`: running
 * it with no flags reports and deletes nothing, `--apply` acts, and
 * `--dry-run` overrides `--apply` so a scheduled entry can be made harmless by
 * adding a flag rather than by editing the line.
 *
 * **There is no scheduler in this repository.** Nothing runs this on its own;
 * an operator adds it to cron, systemd or whatever the deployment already uses
 * to run `emr:reconcile`. Once a day is ample — both retentions are measured
 * in hours at the very least, so a missed run costs nothing but a slightly
 * larger table.
 *
 * A failure exits non-zero, for the reason {@see ReconcileCommand} does the
 * same: a scheduler must never read "the database was unreachable" as "there
 * was nothing to prune".
 *
 * So, for a scheduler: `SUCCESS` (0) means **every sweep this run attempted
 * completed** — a dry run that counted, or an `--apply` that deleted.
 * `FAILURE` (1) means one of them did not, and covers three shapes: a retention
 * option that is not a whole number of at least one (nothing is touched at
 * all), a repository call that threw, and a log directory that is there and
 * cannot be listed ({@see \AsterMD\Storefront\Retention\LogDirectoryUnreadable}).
 * Failing on the first is the point of having a floor — a typo in a crontab
 * must not be able to spell "delete everything" and then be forgiven with a
 * zero. Failing on the last is the same principle one directory over: the
 * count for a directory nothing can open is not zero, it is unknown, and a
 * clean `would delete: 0` over an undeleted wire log is the one report this
 * command must never produce (`[30.9]`).
 *
 * **The sweeps are not one transaction, so a failure can be partial.**
 * The attempt sweep runs first, and under `--apply` its rows are already gone
 * when a later call throws. The failure branch therefore names what it deleted
 * before it stopped, because "prune failed" on its own reads as "nothing
 * happened" and this is the one command in the set that destroys rows.
 *
 * **A failure prints the exception class and, for a driver, its SQLSTATE — and
 * never the message.** See {@see self::execute()}.
 */
#[AsCommand(
    name: 'db:prune',
    description: 'Report (and, with --apply, delete) operational data past its retention period',
)]
final class PruneCommand extends Command
{
    /**
     * Long enough that no duplicate submission can still be looking for one of
     * these rows. The key's material includes the PHP session id, and a session
     * here lives thirty days
     * ({@see \AsterMD\Storefront\Http\Middleware\SessionMiddleware}), so this is
     * the outside edge of "the same browser could still derive this key" — even
     * though `[13.32]` clearing the cart on a placement makes the real window
     * minutes rather than weeks.
     */
    private const int DEFAULT_ATTEMPT_DAYS = 30;

    /**
     * Vastly beyond the longest window `config/payment.php` configures (five
     * minutes), which is what makes deleting a counter a no-op for the guard
     * rather than a fresh allowance.
     */
    private const int DEFAULT_RATE_LIMIT_HOURS = 24;

    public function __construct(
        private readonly CheckoutAttemptRepository $attempts,
        private readonly RateLimitRepository $limits,
        private readonly RetentionSweep $retention,
        private readonly LogSweep $logs,
        private readonly RetentionPolicy $policy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete the aged rows (default is a dry run that only reports them)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only, overriding --apply')
            ->addOption(
                'attempt-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Keep checkout attempts for this many days',
                (string) self::DEFAULT_ATTEMPT_DAYS,
            )
            ->addOption(
                'rate-limit-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Keep rate-limit counters for this many hours',
                (string) self::DEFAULT_RATE_LIMIT_HOURS,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply') && !(bool) $input->getOption('dry-run');

        // Both floors are one whole unit, and one unit is already orders of
        // magnitude past the thing each retention has to clear: a day dwarfs
        // the two-minute staleness window a claim is useful for, and an hour
        // dwarfs the five-minute rate-limit window. So the floor is not a
        // rounding convenience -- it is the safety margin, and a value below it
        // has to change nothing rather than be quietly clamped. A typo in a
        // crontab must not be able to spell "delete everything".
        $days = $this->positiveInteger($input->getOption('attempt-days'));
        $hours = $this->positiveInteger($input->getOption('rate-limit-hours'));

        if ($days === null || $hours === null) {
            $output->writeln('<error>Retentions must be whole numbers of at least 1; nothing was pruned.</error>');

            return Command::FAILURE;
        }

        // One instant for the whole run, so no two sweeps measure "old" against
        // slightly different nows. The policy derives its three cutoffs from
        // the same number for the same reason.
        $now = time();
        $attemptsBefore = gmdate('c', $now - $days * 86_400);
        $limitsBefore = $now - $hours * 3_600;

        $unresolved = null;
        $heldByOrders = 0;

        /** @var list<string> $done one line per completed sweep, in the order they ran */
        $done = [];
        $verb = $apply ? 'deleted' : 'would delete';

        try {
            $sweptAttempts = $apply
                ? $this->attempts->expire($attemptsBefore)
                : $this->attempts->countExpirable($attemptsBefore);
            $done[] = 'checkout_attempts older than ' . $days . 'd — ' . $verb . ': ' . $sweptAttempts;

            $unresolved = $this->attempts->countUnresolved($attemptsBefore);

            $sweptLimits = $apply
                ? $this->limits->expire($limitsBefore)
                : $this->limits->countExpirable($limitsBefore);
            $done[] = 'rate_limits with windows closed over ' . $hours . 'h ago — ' . $verb . ': ' . $sweptLimits;

            $sessionCutoff = $this->policy->sessionCutoff($now);
            if ($sessionCutoff === null) {
                $done[] = 'sessions — expiry disabled (retention.operational.session_days is '
                    . $this->policy->sessionDays() . ')';
            } else {
                $heldByOrders = $this->retention->countSessionsHeldByOrders($sessionCutoff);
                $sweptSessions = $apply
                    ? $this->retention->expireSessions($sessionCutoff)
                    : $this->retention->countExpirableSessions($sessionCutoff);
                $done[] = 'sessions last moved before ' . $sessionCutoff . ' — ' . $verb . ': ' . $sweptSessions;
            }

            $eventCutoff = $this->policy->eventCutoff($now);
            if ($eventCutoff === null) {
                $done[] = 'events — expiry disabled (retention.operational.event_days is '
                    . $this->policy->eventDays() . ')';
            } else {
                $sweptEvents = $apply
                    ? $this->retention->expireEvents($eventCutoff)
                    : $this->retention->countExpirableEvents($eventCutoff);
                $done[] = 'events older than ' . $eventCutoff . ' — ' . $verb . ': ' . $sweptEvents;
            }

            $logCutoff = $this->policy->logCutoff($now);
            if ($logCutoff === null) {
                $done[] = 'storage/logs — expiry disabled (retention.operational.log_days is '
                    . $this->policy->logDays() . ')';
            } else {
                $sweptLogs = $apply
                    ? $this->logs->expire($logCutoff)
                    : $this->logs->countExpirable($logCutoff);
                $done[] = 'storage/logs files last written before ' . $logCutoff . ' — ' . $verb . ': ' . $sweptLogs;
            }
        } catch (\Throwable $error) {
            // The class and the SQLSTATE, never the message. This runs from
            // cron, so stdout is a mail spool, a CI artefact and a shell
            // history at once -- the one sink neither of
            // {@see \AsterMD\Storefront\Support\OperatorLog}'s defences
            // reaches, since redaction is key-based and cannot see inside a
            // string and {@see \AsterMD\Storefront\Support\CardScrubber}
            // masks by Luhn check. A driver refusing a row is under no
            // obligation to describe the refusal without quoting it: MySQL's
            // 1366 names the column and the value bytes it could not store,
            // and both tables here are written from the checkout path. Same
            // policy {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}
            // states for the EMR's free text (`[20.6]`); the operator log has
            // the rest.
            $output->writeln('<error>Prune failed: ' . FailureDigest::describe($error) . '</error>');

            // What already happened before it stopped. Only under --apply,
            // because a dry run's completed steps deleted nothing and saying
            // so would read as a partial delete that never occurred. Reported
            // even though these rows are aged and safe to lose: the operator's
            // only record of this run is the line above, and "nothing was
            // pruned" and "the attempts were pruned and the counters were not"
            // are different states to come back to. It matters more now than
            // it did with two tables: the sweeps run in the order they are
            // listed, so a failure in the log sweep means every database
            // delete has already happened.
            if ($apply && $done !== []) {
                foreach ($done as $line) {
                    $output->writeln('<error>' . $line . '</error>');
                }

                $output->writeln(
                    '<error>(this run stopped after that, so re-running it resumes rather than repeats)</error>',
                );
            }

            return Command::FAILURE;
        }

        foreach ($done as $line) {
            $output->writeln($line);
        }

        if ($heldByOrders > 0) {
            // Never swept, at any age, and the only thing this command can do
            // about it is say so. Each of these is a journey whose row is kept
            // because an order points at it — which is the retention working
            // as intended, not a backlog, and an operator comparing the
            // session count against the period needs the difference explained
            // rather than left to look like a sweep that missed.
            $output->writeln('');
            $output->writeln(
                '<comment>Sessions past their period kept because an order references them: '
                . $heldByOrders . '</comment>',
            );
        }

        if ($unresolved > 0) {
            // Never swept, so naming it is the only thing this command can do
            // about it. Each of these is a call the provider never answered:
            // money may have moved with nothing local confirming it, and that
            // buyer cannot retry that cart until somebody says what happened.
            $output->writeln('');
            $output->writeln(
                '<comment>Unresolved checkout attempts older than ' . $days . 'd (kept, and each needs a person): '
                . $unresolved . '</comment>',
            );
            $output->writeln('  Look each one up by the `checkout.attempt_unresolved` log line that recorded it.');
        }

        if (!$apply) {
            $output->writeln('');
            $output->writeln('(dry run — use --apply to delete)');
        }

        return Command::SUCCESS;
    }

    /**
     * A retention option as a whole number of at least one, or null when it is
     * anything else.
     *
     * Deliberately strict rather than a cast: `(int) 'thirty'` is `0`, and a
     * zero retention here means "everything is old enough", which is the one
     * answer this command must never arrive at by accident.
     */
    private function positiveInteger(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value >= 1 ? (int) $value : null;
    }

}
