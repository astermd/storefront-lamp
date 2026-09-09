<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Payment\PaymentAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `provider:ping` — smoke-tests the configured payment provider the way
 * `emr:ping` smoke-tests the EMR.
 *
 * It resolves whichever adapter the channel's `provider_category` names, so a
 * deployment with no payment processor synced prints the null adapter's own
 * refusal rather than a stack trace. The adapter arrives as a closure because
 * building one reads credentials and constructs an HTTP client, and a command
 * that is not this one must not pay for that.
 *
 * The PCI posture is printed alongside the connectivity line because `[15.15]`
 * asks that an operator know their scope *before* going live, and the moment
 * they are proving the provider is reachable is the moment they are about to.
 * It is a property of the adapter, not of the deployment, so it is true even
 * when the ping itself fails.
 */
#[AsCommand(name: 'provider:ping', description: 'Resolve the configured payment adapter and print a connectivity summary')]
final class ProviderPingCommand extends Command
{
    /** @param \Closure(): PaymentAdapter $adapter resolved on execute, not on registration */
    public function __construct(private readonly \Closure $adapter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $adapter = ($this->adapter)();
            $capabilities = $adapter->capabilities();
            $result = $adapter->ping();
        } catch (\Throwable $e) {
            $output->writeln('<error>Provider ping failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Provider: %s', $capabilities->providerCategory));
        $output->writeln($result['detail']);
        $output->writeln(sprintf('PCI posture: %s', $capabilities->pciPosture));

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}
