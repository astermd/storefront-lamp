<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Payment\CaptureOutcome;
use AsterMD\Storefront\Payment\CaptureRequest;
use AsterMD\Storefront\Payment\OrderLine;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\SettlementMode;
use AsterMD\Storefront\Repository\OrderRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `payment:capture <reference>` — take the money on an order that was
 * authorized rather than charged.
 *
 * **This is the seam, not the trigger.** What decides *when* an authorization
 * settles is a clinical or fulfilment event that happens outside this
 * storefront, and deliberately stays outside it: a prescriber approving a
 * treatment is not a checkout concern, and a storefront that scheduled its own
 * captures would be guessing at a decision it cannot see. So there is no
 * scheduler here and no sweep — whatever system owns that event runs this
 * command with the reference it is settling, the same way an operator would.
 *
 * **It takes the provider's reference, not a local id.** That is what
 * `orders.provider_reference` holds, what the receipt shows the buyer, and what
 * an operator reads off the provider's own dashboard, so it is the one
 * identifier every party to a support call already has.
 *
 * **The local row is consulted before the provider is**, and its only job is to
 * stop obvious mistakes cheaply: an order this storefront never recorded, or
 * one it recorded as captured, is refused without a network call. Both refusals
 * are advisory rather than authoritative — the provider is the authority on
 * what it holds, which is why a row that merely cannot be read does not block
 * the capture. A buyer's funds expiring because a local SELECT failed would be
 * the worse outcome by a wide margin.
 *
 * **Exit codes are the interface.** Success is 0 and every refusal is 1,
 * because the caller is a script: it needs to know whether to alert, not which
 * of six things went wrong. Which one it was is on stdout and in the operator
 * log.
 */
#[AsCommand(name: 'payment:capture', description: 'Capture an order that was authorized at checkout rather than charged')]
final class CaptureOrderCommand extends Command
{
    /**
     * @param \Closure(): PaymentAdapter $adapter resolved on execute, not on registration, so a `db:migrate` run
     *                                            never builds a provider client (`provider:ping` for the same reason)
     */
    public function __construct(
        private readonly \Closure $adapter,
        private readonly OrderRepository $orders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'reference',
            InputArgument::REQUIRED,
            "The payment provider's own order reference, as orders.provider_reference holds it",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reference = trim((string) $input->getArgument('reference'));

        if ($reference === '') {
            $output->writeln('<error>A provider order reference is required.</error>');

            return Command::FAILURE;
        }

        $row = $this->orderRow($reference, $output);
        if ($row === null) {
            return Command::FAILURE;
        }

        try {
            $adapter = ($this->adapter)();
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not build the payment adapter: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if (!$adapter->capabilities()->supportsAuthorizeCapture) {
            $output->writeln(sprintf(
                '<error>The configured provider (%s) does not support authorize-and-capture, so it holds nothing to settle.</error>',
                $adapter->capabilities()->providerCategory,
            ));

            return Command::FAILURE;
        }

        $outcome = $adapter->capture(new CaptureRequest($reference, self::linesFrom($row)));

        if ($outcome->isCaptured()) {
            $output->writeln(sprintf('Captured order %s.', $reference));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>Capture of order %s was not completed (%s).</error>',
            $reference,
            $outcome->state === CaptureOutcome::UNSUPPORTED ? 'unsupported' : (string) $outcome->reason,
        ));

        return Command::FAILURE;
    }

    /**
     * The local order, or null when there is a stated reason not to go on.
     *
     * @return array<string, mixed>|null
     */
    private function orderRow(string $reference, OutputInterface $output): ?array
    {
        try {
            $row = $this->orders->findByReference($reference);
        } catch (\Throwable) {
            $output->writeln(sprintf(
                '<error>The local record for %s could not be read, and it is the only place this order\'s lines still exist. Fix the database and retry; the authorization is untouched.</error>',
                $reference,
            ));

            return null;
        }

        if ($row === null) {
            $output->writeln(sprintf(
                '<error>No local order is recorded against reference %s. Check it against the provider before capturing by hand.</error>',
                $reference,
            ));

            return null;
        }

        if ($row['settlement'] !== SettlementMode::Authorize->value) {
            $output->writeln(sprintf(
                '<error>Order %s was recorded as %s, not as an authorization, so there is nothing held to capture.</error>',
                $reference,
                $row['settlement'],
            ));

            return null;
        }

        return $row;
    }

    /**
     * The order's lines as the neutral shape an adapter reads.
     *
     * Rebuilt from `order_lines` rather than from anything the provider holds,
     * because for at least one provider the provider holds nothing: a
     * pre-authorized order there has an empty item list until the settling call
     * fills it.
     *
     * @param  array<string, mixed> $row
     * @return list<OrderLine>
     */
    private static function linesFrom(array $row): array
    {
        $lines = [];

        foreach (is_array($row['lines'] ?? null) ? $row['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lines[] = new OrderLine(
                slug: (string) ($line['slug'] ?? ''),
                name: (string) ($line['name'] ?? ''),
                providerOffer: self::nullableText($line['provider_offer'] ?? null),
                providerItem: self::nullableText($line['provider_item'] ?? null),
                unitPriceCents: (int) ($line['unit_price_cents'] ?? 0),
                quantity: (int) ($line['quantity'] ?? 0),
                kind: (string) ($line['kind'] ?? 'otc'),
            );
        }

        return $lines;
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}