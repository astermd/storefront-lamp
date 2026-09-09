<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Support\FailureDigest;

use AsterMD\Storefront\Reconciliation\ForwardReconciliation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `emr:reconcile` — one run of `[21.8]`'s forward sweep, from a terminal.
 *
 * **There is no scheduler in this codebase, and this command does not add
 * one.** It is written to be run on a schedule an operator owns — cron, a
 * systemd timer, whatever the deployment already has — and it is safe to run
 * as often as that schedule likes: the grace window keeps it off orders the
 * checkout path has not finished with, and the EMR deduplicates a sync per
 * session, so a run that overlaps another cannot create a second clinical
 * record.
 *
 * **The default is a dry run**, as it is for every other outbound or
 * destructive command in this theme. `--apply` opts in to actually telling the
 * EMR about the backlog. `--dry-run` states the default explicitly *and beats
 * `--apply`*, which is the asymmetry that makes it useful: it is the brake an
 * operator can add to an existing scheduled invocation without editing the
 * flags already in it.
 *
 * **A sync that failed is not a failed run.** The exit code answers "did this
 * job work", not "is every order reconciled", because those need different
 * responses: an order the EMR keeps refusing needs a person, and re-running
 * the command changes nothing about it, so exiting non-zero would alert on
 * every run until that person acted. The one thing that does fail the run is a
 * sweep that could not read the orders at all — a run reporting "nothing
 * outstanding" because the database was unreachable is precisely the silent
 * failure this sweep exists to end.
 *
 * So, for a scheduler: `SUCCESS` (0) means **the backlog below was measured**,
 * whether it is empty, full, or full of orders the EMR refused this run.
 * `FAILURE` (1) means the `orders` table could not be read, in which case
 * nothing is printed at all.
 *
 * **There is no partly-measured state here, which is why this command needs no
 * third answer.** Every figure it prints comes from a query whose failure
 * propagates out of {@see ForwardReconciliation::run()} and lands in the catch
 * below. The one read that is swallowed is the per-order sync read-back, and
 * it fails toward more work outstanding rather than less: an order whose row
 * cannot be re-read is counted as *not* synced, so it stays in the monitored
 * figure instead of being retired from it on the strength of a database error.
 * {@see ReconcileOrdersCommand} does need the third answer, because there the
 * equivalent per-order read decides the monitored figure itself.
 *
 * **A failure prints the exception class and, for a driver, its SQLSTATE — and
 * never the message.** See {@see self::execute()} for why that boundary exists
 * on a job whose stdout is a cron mail spool.
 */
#[AsCommand(name: 'emr:reconcile', description: 'Report (and, with --apply, sync) orders the EMR was never told about')]
final class ReconcileCommand extends Command
{
    public function __construct(private readonly ForwardReconciliation $reconciliation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually sync the backlog (default is a dry run that only reports it)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only, overriding --apply');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply') && !(bool) $input->getOption('dry-run');

        try {
            $report = $this->reconciliation->run($apply);
        } catch (\Throwable $e) {
            // The class and the SQLSTATE, never the message. This runs from
            // cron, so stdout is a mail spool, a CI artefact and a shell
            // history at once -- the one sink neither of
            // {@see \AsterMD\Storefront\Support\OperatorLog}'s defences
            // reaches, since redaction is key-based and cannot see inside a
            // string and {@see \AsterMD\Storefront\Support\CardScrubber}
            // masks by Luhn check. A driver refusing a row is under no
            // obligation to describe the refusal without quoting it: MySQL's
            // 1366 names the column and the value bytes it could not store.
            // Same policy {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}
            // states for the EMR's free text (`[20.6]`); the operator log has
            // the rest.
            $output->writeln('<error>Reconciliation failed: ' . FailureDigest::describe($e) . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('Orders the EMR was never told about: ' . $report->unsynced);
        $output->writeln('  reconciliation backlog: ' . $report->backlog);
        $output->writeln('  unreconcilable (no analytics session): ' . $report->unreconcilable);
        $output->writeln('  examined this run: ' . $report->examined
            . ', synced: ' . $report->synced
            . ', failed: ' . $report->failed
            . ', skipped: ' . $report->skipped);

        if ($report->repeatFailureReferences !== []) {
            // `[21.8]` (c). Named one per line rather than counted, because
            // the action this figure calls for is looking each one up.
            $output->writeln('');
            $output->writeln('<comment>Repeat failures — these have not synced since they were placed:</comment>');

            foreach ($report->repeatFailureReferences as $reference) {
                $output->writeln('  ' . $reference);
            }
        }

        if ($report->dryRun) {
            $output->writeln('');
            $output->writeln('(dry run — use --apply to sync)');
        }

        return Command::SUCCESS;
    }

}
