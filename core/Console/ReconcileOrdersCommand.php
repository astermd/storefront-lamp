<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Support\FailureDigest;

use AsterMD\Storefront\Reconciliation\ReverseReconciliation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `provider:reconcile` — one run of `[21.9a]`'s reverse sweep, from a terminal.
 *
 * The counterpart to `emr:reconcile` and deliberately not a second spelling of
 * it. That one starts from a local order and asks whether the EMR heard about
 * it; this one starts from the provider and asks whether **this storefront**
 * ever heard about the charge. They sweep in opposite directions, they answer
 * to different `[20.12]` figures, and registering both under one name would
 * leave whichever loaded second silently unreachable.
 *
 * **There is no scheduler in this codebase, and this command does not add
 * one.** It is written to be run on a schedule an operator owns — cron, a
 * systemd timer, whatever the deployment already has — and it is safe to run
 * as often as that schedule likes, because it writes nothing at all.
 *
 * **There is no `--apply`, and its absence is the design.** Every outbound
 * command in this theme is dry-run by default with `--apply` to act; this one
 * has no second behaviour for a flag to select. An unmatched provider order
 * cannot be turned into a local order row — the search projection carries no
 * analytics session, no lines, no buyer and no order total — so the only thing
 * `--apply` could do is fabricate a record of a charge, which is what `[20.8]`
 * exists to forbid. Under the user ruling of 2026-08-25 the recovery path is a
 * person reading these references in the provider's own dashboard. A flag that
 * did nothing would be worse than no flag: it would promise a recovery this
 * provider cannot give. `provider:ping` is the precedent for a read-only
 * command shipping without one.
 *
 * **The exit code answers "did this job measure its window", not "is
 * everything reconciled".** A lost charge needs a person, and re-running the
 * command changes nothing about it, so exiting non-zero on one would alert on
 * every run until that person acted. What does fail the run is a sweep whose
 * headline figure is not a total, because the scheduler is the only thing in a
 * position to notice that and the figure is the only reason the job exists.
 *
 * `SUCCESS` (0) means, and only means, **this run compared every order in its
 * window against a local row**. It is printed for a clean window and for a
 * window full of orphans alike.
 *
 * `FAILURE` (1) is returned for all four shapes of "the number above is a
 * floor, not a total":
 *
 * - **the provider was not answered** — an outage and an empty window both
 *   yield zero orders and only one of them means nothing was lost;
 * - **a local row could not be read** — the same blindness one layer down,
 *   and the one that is easy to miss. Each unreadable row is an unanswered
 *   "was this charge recorded?", so a count taken beside one is a lower
 *   bound; a locked `orders` table otherwise produces an authoritative-looking
 *   `0` from a run that checked nothing. {@see ReconcileCommand} fails on the
 *   same outage over the same table, and this sweep has to agree with it;
 * - **the window held more orders than the limit could read** — see the
 *   truncation note in {@see self::execute()} for why the unread half is the
 *   half that matters;
 * - **the window itself was unusable or unreadable** — a `--hours` that is not
 *   a number, or one shorter than the sweep's own grace period. Neither is
 *   quietly replaced by the configured default, because a run reporting a
 *   window the operator did not ask for is the same untrustworthy figure in a
 *   different shape.
 *
 * **Any of those fails the whole run rather than being weighed against how
 * much did get measured, and that is deliberate.** A partly blind sweep prints
 * a number that looks exactly like a measured one, and no exit code can carry
 * "mostly". The cost of the choice is a page for a transient database lock;
 * the next scheduled run clears it with nobody doing anything, because the
 * default lookback is two days and covers the run that failed. The cost of the
 * other choice is a monitored zero that means nothing, which is what this
 * command was shipped with.
 *
 * A provider with no order search at all is neither: that is a deployment
 * fact, not an outage, and it exits `SUCCESS` having said so.
 */
#[AsCommand(name: 'provider:reconcile', description: 'Report orders the payment provider took that are not recorded locally')]
final class ReconcileOrdersCommand extends Command
{
    public function __construct(private readonly ReverseReconciliation $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'hours',
            null,
            InputOption::VALUE_REQUIRED,
            'How far back to sweep, overriding the configured window (use a shorter one when a run reports truncation)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = $input->getOption('hours');

        if ($hours !== null && !is_numeric($hours)) {
            // Refused rather than ignored. Falling back to the configured
            // window would sweep a span the operator did not ask for and then
            // report its figure as though they had: the count would be honest
            // about a window nobody believes was measured, which is the same
            // untrustworthy zero this command's exit codes exist to prevent.
            $output->writeln('<error>--hours must be a number of hours; nothing was swept.</error>');

            return Command::FAILURE;
        }

        $lookback = is_numeric($hours) ? (int) round(((float) $hours) * 3600) : null;

        try {
            $report = $this->reconciliation->run(lookbackSeconds: $lookback);
        } catch (\Throwable $e) {
            // The class and the SQLSTATE, never the message. This runs from
            // cron, so stdout is a mail spool, a CI artefact and a shell
            // history at once -- the one sink neither of
            // {@see \AsterMD\Storefront\Support\OperatorLog}'s defences
            // reaches, since redaction is key-based and cannot see inside a
            // string and {@see \AsterMD\Storefront\Support\CardScrubber}
            // masks by Luhn check. A driver refusing a row is under no
            // obligation to describe the refusal without quoting the row: the
            // `orders` table this sweep reads carries buyer references, and
            // MySQL's 1366 names the column and the value bytes. Same policy
            // {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}
            // states for the EMR's free text (`[20.6]`); the operator log has
            // the rest.
            $output->writeln('<error>Reverse reconciliation failed: ' . FailureDigest::describe($e) . '</error>');

            if ($e instanceof \InvalidArgumentException) {
                // The one outcome {@see ReverseReconciliation::run()} declares
                // it throws, and the class name alone does not say which knob
                // produced it. Our own sentence about our own settings, so
                // there is no foreign text in it.
                $output->writeln(
                    '<error>The sweep window is unusable: the lookback (--hours, or the configured default) must '
                    . 'outlast the sweep\'s grace period.</error>',
                );
            }

            return Command::FAILURE;
        }

        if (!$report->supported) {
            $output->writeln('The configured payment provider does not support an order search; there is nothing to sweep.');

            return Command::SUCCESS;
        }

        if ($report->searchFailed) {
            $output->writeln('<error>The provider could not be asked what orders it holds (' . $report->failureReason . ').</error>');
            $output->writeln('<error>This run measured nothing; it is not a report that nothing was lost.</error>');

            return Command::FAILURE;
        }

        $output->writeln('Orders placed at the provider but not recorded locally: ' . $report->lostCharges);
        $output->writeln('  examined this run: ' . $report->examined . ' of ' . $report->reportedTotal
            . ', matched: ' . $report->matched);
        $output->writeln('  unmatched but never charged: ' . $report->unmatchedUncharged);
        $output->writeln('  unmatched sandbox orders: ' . $report->unmatchedTest);

        /** @var list<array{string, string}> $blind what went unmeasured, and what an operator does about it */
        $blind = [];

        if ($report->unreadable > 0) {
            // Not orphans and not matches: the local row could not be read, so
            // these orders were never checked either way.
            $output->writeln('<error>  local rows that could not be checked: ' . $report->unreadable . '</error>');
            $blind[] = [
                $report->unreadable . ' of the ' . $report->examined
                . ' orders read from the provider could not be checked against a local row.',
                'Check the database this job reads; the `reconcile.local_read_failed` log lines name each order.',
            ];
        }

        if ($report->truncated) {
            // Which half goes unread is not arbitrary. Nothing in the request
            // asks for a sort order -- see `VrioAdapter::searchOrders()` --
            // so the ordering is the provider's own, and on every recorded
            // window it is newest first. The limit therefore drops the OLDEST
            // orders: the ones old enough to have genuinely failed to record,
            // and the only ones that age out of the lookback entirely before
            // another run at these settings can reach them.
            $blind[] = [
                'The window held ' . $report->reportedTotal . ' orders and this run read ' . $report->examined
                . '. On every recorded window the provider answers newest first, so the unread orders are the '
                . 'oldest in the window — the ones most likely to be genuinely lost, and the ones that age out '
                . 'of the lookback before another run at these settings reaches them.',
                'Shorten the window with --hours AND run that often, so consecutive runs still meet end to end.',
            ];
        }

        if ($report->lostChargeReferences !== []) {
            // Named one per line rather than counted, because the action these
            // call for is looking each one up in the provider's dashboard.
            // Nothing on this side can say more about them than the reference:
            // the identifier this storefront submits at placement does not
            // survive the provider's round trip, which is why `[21.9b]` is
            // recorded as a gap rather than implemented.
            $output->writeln('');
            $output->writeln('<comment>These provider orders have no local row. Each one is a charge nothing here knows about:</comment>');

            foreach ($report->lostChargeReferences as $reference) {
                $output->writeln('  ' . $reference);
            }
        }

        if ($blind === []) {
            return Command::SUCCESS;
        }

        // Last, so it is the last thing in the cron mail, and non-zero, so a
        // scheduler can tell this run from one that read its whole window.
        $output->writeln('');

        foreach ($blind as [$what, $remedy]) {
            $output->writeln('<error>' . $what . '</error>');
            $output->writeln('<comment>' . $remedy . '</comment>');
        }

        $output->writeln('<error>The count above is a floor and not a total: this run did not measure its whole window.</error>');

        return Command::FAILURE;
    }

}
