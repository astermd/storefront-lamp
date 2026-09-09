<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Emr\ClientFactory;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `emr:ping` — smoke-tests EMR connectivity by fetching the configured
 * channel's detail record and printing its name, product count, and
 * payment-provider category. Exists so operators can confirm credentials and
 * channel wiring without reaching for a full page render.
 */
#[AsCommand(name: 'emr:ping', description: 'Fetch the configured EMR channel and print a connectivity summary')]
final class PingCommand extends Command
{
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ?ClientInterface $httpClient = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $client = $this->clientFactory->create($this->httpClient);
            $channelId = $this->clientFactory->channelId();
            $data = $client->channels()->details($channelId)->data();

            $name = is_string($data['name'] ?? null) ? $data['name'] : 'unknown';
            $products = is_array($data['products'] ?? null) ? count($data['products']) : 0;
            $providerCategory = is_string($data['payment_processor']['provider_category'] ?? null)
                ? $data['payment_processor']['provider_category']
                : 'unknown';

            $output->writeln(sprintf('Channel: %s', $name));
            $output->writeln(sprintf('Products: %d', $products));
            $output->writeln(sprintf('Provider: %s', $providerCategory));
        } catch (\Throwable $e) {
            $output->writeln('<error>EMR ping failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
