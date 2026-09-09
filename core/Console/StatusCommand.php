<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Observability\DeclineBreakdown;
use AsterMD\Storefront\Observability\Measured;
use AsterMD\Storefront\Observability\OperationalCounts;
use AsterMD\Storefront\Reconciliation\ReverseReconciliationReport;
use AsterMD\Storefront\Repository\OrderRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ops:status` — `[20.12]`'s five monitored counts in one place.
 *
 * Each of them represents money or clinical risk, and until now they were
 * spread across two reconciliation commands with two more counted nowhere at
 * all. Spread out, they are five things an operator has to remember to run;
 * together they are a page somebody can look at.
 *
 * **What it reports, and where each figure comes from:**
 *
 * | count | source |
 * |---|---|
 * | orders placed at the provider but not recorded locally | the reverse sweep — **opt-in**, see below |
 * | orders recorded locally but never synced to the EMR (`[17.8]`) | the `orders` table, the same query the forward sweep counts with |
 * | reconciliation backlog and repeat failures (`[21.8]`) | as above |
 * | journeys reaching payment without an EMR session | `[18.1]`'s local trail |
 * | decline rate by reason | `[18.1]`'s local trail |
 *
 * **This command reads. It changes nothing.** There is no `--apply` and there
 * never will be: every figure it prints has its own command to act on, and a
 * status page that could also write would be one an operator hesitated to run.
 *
 * **The provider half is opt-in (`--provider`) because it is the only figure
 * that needs an outbound call.** Without the flag it reports `not checked`,
 * which is not zero — the distinction the whole of {@see Measured} exists for.
 * A previous sweep shipped reporting "0 lost charges" and exiting 0 from a run
 * whose local read had failed outright, and that is the shape being refused
 * here.
 *
 * **Exit codes.** `SUCCESS` (0) means every figure asked for was measured
 * completely. `FAILURE` (1) means at least one was not: a read that failed, or
 * a provider window truncated so that its count is a floor rather than a
 * total. A figure nobody asked for is neither, and does not fail the run.
 * There is deliberately no third code — a run cannot be partly trustworthy,
 * and no exit code can carry "mostly".
 *
 * **No provider free text reaches stdout.** A cron job's stdout is a mail
 * spool, a CI artefact and a shell history at once, and neither of
 * {@see \AsterMD\Storefront\Support\OperatorLog}'s defences reaches it. Decline
 * reasons are the one thing here composed by somebody else; they are scrubbed
 * again by {@see OperationalCounts} on the way out, and failures are named by
 * exception class rather than by message (`[20.6]`, `[20.14]`).
 */
#[AsCommand(name: 'ops:status', description: 'Report the monitored counts that represent money or clinical risk')]
final class StatusCommand extends Command
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): ReverseReconciliationReport)|null $providerSweep  resolved on execute, not on registration:
     *                                                                      building it reads credentials, and a run
     *                                                                      without `--provider` must not pay for that
     * @param int                    $graceSeconds              matches the forward sweep's, so "backlog" means the same thing in both
     * @param int                    $repeatFailureAfterSeconds likewise for `[21.8]` (c)
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OperationalCounts $counts,
        private readonly ?\Closure $providerSweep = null,
        private readonly int $graceSeconds = 900,
        private readonly int $repeatFailureAfterSeconds = 86400,
        ?\Closure $clock = null,
    ) {
        parent::__construct();

        $this->clock = $clock ?? static fn (): int => time();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_NONE,
                'Also ask the payment provider what orders it holds. Makes an outbound call; without it that figure reads "not checked"',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON document instead of the operator listing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Computed once, before anything is read, so every figure in this run
        // is measured against the same instant. Timestamps are `gmdate('c')`
        // throughout the schema -- fixed-width UTC, sorting lexicographically
        // in chronological order -- which is the only reason a string
        // comparison is a valid range query.
        $now = ($this->clock)();
        $graceCutoff = gmdate('c', $now - $this->graceSeconds);
        $escalationCutoff = gmdate('c', $now - $this->repeatFailureAfterSeconds);

        $figures = [
            'orders_placed_at_provider_not_recorded_locally' => $input->getOption('provider') === true
                ? $this->providerLostCharges()
                : Measured::notChecked(),
            'orders_recorded_locally_never_synced' => $this->count(fn (): int => $this->orders->countUnsynced()),
            'reconciliation_backlog' => $this->count(fn (): int => $this->orders->countUnsyncedPlacedBefore($graceCutoff)),
            'repeat_failures' => $this->count(fn (): int => $this->orders->countUnsyncedPlacedBefore($escalationCutoff)),
            'unreconcilable' => $this->count(fn (): int => $this->orders->countUnsyncedWithoutSession()),
            'checkouts_without_emr_session' => $this->counts->checkoutsWithoutEmrSession(),
            'checkouts_reached' => $this->counts->checkoutsReached(),
        ];

        $declines = $this->counts->declines();

        $complete = $declines->available();

        foreach ($figures as $figure) {
            // A figure nobody asked for is not a failed one: the provider half
            // is opt-in, and a run that did not opt in is a complete run.
            $complete = $complete && ($figure->complete() || $figure->state === Measured::NOT_CHECKED);
        }

        if ($input->getOption('json') === true) {
            $output->writeln((string) json_encode(
                array_map(static fn (Measured $m): array => $m->toArray(), $figures)
                + ['declines' => $declines->toArray(), 'complete' => $complete],
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));

            return $complete ? Command::SUCCESS : Command::FAILURE;
        }

        $this->render($output, $figures, $declines);

        return $complete ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The reverse sweep's `[20.12]` count, with every shape of blindness the
     * report distinguishes carried through rather than flattened.
     *
     * `searchFailed` and `unreadable` both mean the question was only partly
     * asked; `truncated` means it was asked over a prefix of the window. An
     * unsupported adapter is none of those — it is a deployment fact, and a
     * deployment whose provider cannot be asked what orders it holds has no
     * sweep to run rather than a failed one.
     */
    private function providerLostCharges(): Measured
    {
        if ($this->providerSweep === null) {
            return Measured::notChecked();
        }

        try {
            $report = ($this->providerSweep)();
        } catch (\Throwable $e) {
            // The class, never the message: provider transport errors name
            // accounts, hosts and occasionally credentials.
            return Measured::unavailable($e::class);
        }

        if (!$report->supported) {
            return Measured::notChecked();
        }

        if ($report->searchFailed) {
            return Measured::unavailable($report->failureReason ?? 'search_failed');
        }

        if ($report->truncated || $report->unreadable > 0) {
            return Measured::floor($report->lostCharges);
        }

        return Measured::of($report->lostCharges);
    }

    /** @param \Closure(): int $read */
    private function count(\Closure $read): Measured
    {
        try {
            return Measured::of($read());
        } catch (\Throwable $e) {
            return Measured::unavailable($e::class);
        }
    }

    /** @param array<string, Measured> $figures */
    private function render(OutputInterface $output, array $figures, DeclineBreakdown $declines): void
    {
        $output->writeln('Orders placed at the provider but not recorded locally: '
            . $figures['orders_placed_at_provider_not_recorded_locally']->render());
        $output->writeln('  (--provider asks the provider; it makes an outbound call)');
        $output->writeln('');

        $output->writeln('Orders recorded locally but never synced to the EMR: '
            . $figures['orders_recorded_locally_never_synced']->render());
        $output->writeln('  reconciliation backlog: ' . $figures['reconciliation_backlog']->render());
        $output->writeln('  repeatedly failing: ' . $figures['repeat_failures']->render());
        $output->writeln('  no session, so no retry can ever drain them: ' . $figures['unreconcilable']->render());
        $output->writeln('');

        $output->writeln('Journeys reaching payment without an EMR session: '
            . $figures['checkouts_without_emr_session']->render()
            . ' of ' . $figures['checkouts_reached']->render() . ' checkouts');
        $output->writeln('');

        $this->renderDeclines($output, $declines);
    }

    private function renderDeclines(OutputInterface $output, DeclineBreakdown $declines): void
    {
        if (!$declines->available()) {
            $output->writeln('<error>Declines by reason: unavailable (' . ($declines->failure ?? 'unknown') . ')</error>');
            $output->writeln('<error>This is not a report that nothing was declined.</error>');

            return;
        }

        $rate = $declines->rate();
        $output->writeln('Declines: ' . $declines->total . ' of ' . $declines->attempts . ' charge attempts'
            . ($rate === null ? '' : ' (' . $rate . '%)'));

        foreach ((array) $declines->byReason as $reason => $count) {
            $output->writeln('  ' . $count . '  ' . $reason);
        }
    }
}
