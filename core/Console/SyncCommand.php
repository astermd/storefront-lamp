<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Sdk\Http\NativeCurlClient;
use AsterMD\Storefront\Catalog\CatalogBuilder;
use AsterMD\Storefront\Catalog\MediaLocalizer;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\PhpArrayExporter;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `theme:sync` — the centerpiece of the catalog pipeline. Fetches the
 * configured EMR channel's detail record, runs it through
 * {@see CatalogBuilder}, and (in `--apply` mode only) localizes remote product
 * images via {@see MediaLocalizer} before rendering two generated config
 * files via {@see PhpArrayExporter}:
 *
 * - `config/products.generated.php` — the full built catalog. Overwritten
 *   wholesale on every sync; hand customisations belong in
 *   `config/products.overrides.php` instead, which this command never
 *   touches. Gitignored, since it is one channel's catalog and not the
 *   theme's; `config/products.generated.example.php` is the committed shape
 *   reference a fresh checkout starts from.
 * - `config/channel.generated.php` — the channel id/name and payment
 *   processor block (gitignored: `payment_processor.config` carries live
 *   provider credentials). Written with merge-not-replace semantics: any
 *   hand-added key already in the file that the new payload doesn't mention
 *   survives the sync, recursively at every array level; keys present in
 *   both are overwritten by the fresh payload.
 *
 * Defaults to a dry run: both target files' unified diffs are printed (no
 * writes), along with a media-localisation count. `--apply` performs the
 * writes for real, runs media localisation, and then hands the (localized)
 * catalog to an injected structural validator — when `$validator` is null,
 * validation is skipped with a notice instead; `bin/console` wires the real
 * {@see \AsterMD\Storefront\Catalog\CatalogValidator} in production.
 * The count of variants still missing a payment-provider mapping ([2.18]) is
 * printed in BOTH modes — it's business-spec-mandated sync output, not a
 * dry-run-only preview — computed once on the built (pre-localization)
 * catalog, since localization never touches `variants`.
 */
#[AsCommand(name: 'theme:sync', description: 'Sync the generated catalog and channel config from the configured EMR channel')]
final class SyncCommand extends Command
{
    /** `callable` cannot be a promoted-property type in PHP, so it is normalised to `?\Closure` in the constructor body. */
    private readonly ?\Closure $validator;

    /**
     * @param callable(array<string, mixed>): array{errors: list<string>, warnings: list<string>}|null $validator
     *        structural validation hook, run only in `--apply` mode after both files are written. When
     *        null (the default), validation is skipped with a notice rather than failing closed —
     *        `bin/console` wires the real {@see \AsterMD\Storefront\Catalog\CatalogValidator} here.
     */
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly string $configDir,
        private readonly string $mediaDir,
        private readonly ?ClientInterface $httpClient = null,
        ?callable $validator = null,
    ) {
        parent::__construct();
        $this->validator = $validator === null ? null : \Closure::fromCallable($validator);
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the generated files (default is a dry run that only prints diffs)')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel ID to sync (overrides app.emr.channel_id)')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Currency code stamped onto the generated catalog', 'USD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channelId = $this->resolveChannelId($input);
        if ($channelId === null) {
            $output->writeln('<error>theme:sync: no channel id — pass --channel or set app.emr.channel_id (ASTERMD_CHANNEL_ID).</error>');

            return Command::FAILURE;
        }

        try {
            $http = $this->httpClient ?? new NativeCurlClient();
            $client = $this->clientFactory->create($http);
            $data = $client->channels()->details($channelId)->data();
        } catch (\Throwable $e) {
            $output->writeln('<error>theme:sync: failed to fetch channel details: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $currency = (string) $input->getOption('currency');
        $catalog = CatalogBuilder::build($data, $currency);
        // Computed on the built (pre-localization) catalog: media localization only
        // rewrites image/gallery fields, never variants, so this count is identical
        // whether taken before or after localize() — computing it here keeps dry-run
        // and apply reporting on the exact same basis.
        $missingProviders = self::countMissingProviders($catalog);

        $productsFile = $this->configDir . '/products.generated.php';
        $channelFile = $this->configDir . '/channel.generated.php';

        $productsHeader = sprintf(
            "GENERATED by bin/console theme:sync — do not hand-edit.\nOverrides belong in config/products.overrides.php (they survive every sync).\nChannel: %s (%s)",
            (string) ($data['_id'] ?? ''),
            (string) ($data['name'] ?? ''),
        );
        $channelHeader = "GENERATED by bin/console theme:sync — do not hand-edit structural keys.\n"
            . "Hand-added operational keys survive re-sync (merge semantics).\n"
            . 'GITIGNORED: contains provider credentials.';

        $channelPayload = self::channelPayload($data);
        $existingChannelPayload = self::readExistingArray($channelFile);
        $mergedChannelPayload = self::mergeKeepExtra($existingChannelPayload, $channelPayload);

        $apply = (bool) $input->getOption('apply');

        if (!$apply) {
            return $this->printDryRun($output, $catalog, $productsFile, $productsHeader, $channelFile, $mergedChannelPayload, $channelHeader, $missingProviders);
        }

        $localizer = new MediaLocalizer($http, $this->mediaDir, $client->assetUrl('ui/' . $this->clientFactory->assetEnvironment() . '/'));
        $localized = $localizer->localize($catalog);
        $catalog = $localized['catalog'];
        $this->printMediaReport($output, $localized['report']);
        self::reportMissingProviders($output, $missingProviders);

        file_put_contents($productsFile, PhpArrayExporter::export($catalog, $productsHeader));
        file_put_contents($channelFile, PhpArrayExporter::export($mergedChannelPayload, $channelHeader));

        return $this->runValidation($output, $catalog);
    }

    /** --channel option wins; otherwise fall back to the configured channel id; null when neither is set. */
    private function resolveChannelId(InputInterface $input): ?string
    {
        $option = $input->getOption('channel');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        try {
            return $this->clientFactory->channelId();
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $mergedChannelPayload
     */
    private function printDryRun(
        OutputInterface $output,
        array $catalog,
        string $productsFile,
        string $productsHeader,
        string $channelFile,
        array $mergedChannelPayload,
        string $channelHeader,
        int $missingProviders,
    ): int {
        $existingProductsText = is_file($productsFile) ? (string) file_get_contents($productsFile) : '';
        $newProductsText = PhpArrayExporter::export($catalog, $productsHeader);
        $output->writeln(PhpArrayExporter::unifiedDiff($existingProductsText, $newProductsText, 'products.generated.php'));

        $existingChannelText = is_file($channelFile) ? (string) file_get_contents($channelFile) : '';
        $newChannelText = PhpArrayExporter::export($mergedChannelPayload, $channelHeader);
        $output->writeln(PhpArrayExporter::unifiedDiff($existingChannelText, $newChannelText, 'channel.generated.php'));

        $output->writeln(sprintf('media: %d images would be localised', self::countRemoteImages($catalog)));
        self::reportMissingProviders($output, $missingProviders);

        return Command::SUCCESS;
    }

    /** @param array{downloaded: int, unchanged: int, failed: list<string>, orphaned: list<string>} $report */
    private function printMediaReport(OutputInterface $output, array $report): void
    {
        $output->writeln(sprintf(
            'media: %d downloaded, %d unchanged, %d failed, %d orphaned',
            $report['downloaded'],
            $report['unchanged'],
            count($report['failed']),
            count($report['orphaned']),
        ));

        if ($report['failed'] !== []) {
            $output->writeln('media: failed slugs — ' . implode(', ', $report['failed']));
        }
    }

    /** @param array<string, mixed> $catalog the (media-localized) catalog just written to products.generated.php */
    private function runValidation(OutputInterface $output, array $catalog): int
    {
        if ($this->validator === null) {
            $output->writeln('validation: skipped (validator not wired)');

            return Command::SUCCESS;
        }

        /** @var array{errors: list<string>, warnings: list<string>} $result */
        $result = ($this->validator)($catalog);

        foreach ($result['warnings'] as $warning) {
            $output->writeln('<comment>validation warning: ' . $warning . '</comment>');
        }

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                $output->writeln('<error>validation error: ' . $error . '</error>');
            }

            return 2;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $data the `data` subtree of a Channel Details response
     * @return array{channel: array{id: mixed, name: mixed}, payment_processor: array{provider_category: mixed, name: mixed, config: array<string, mixed>}}
     */
    private static function channelPayload(array $data): array
    {
        $paymentProcessor = is_array($data['payment_processor'] ?? null) ? $data['payment_processor'] : [];
        $config = is_array($paymentProcessor['config'] ?? null) ? $paymentProcessor['config'] : [];

        return [
            'channel' => [
                'id' => $data['_id'] ?? null,
                'name' => $data['name'] ?? null,
            ],
            'payment_processor' => [
                'provider_category' => $paymentProcessor['provider_category'] ?? null,
                'name' => $paymentProcessor['name'] ?? null,
                'config' => $config,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function readExistingArray(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        /** @psalm-suppress UnresolvableInclude */
        return (array) require $file;
    }

    /**
     * Recursive union of `$existing` and `$new`: keys present only in
     * `$existing` survive untouched; keys present in `$new` (at any depth)
     * win over `$existing`'s value for that key, recursing when both sides
     * hold an array so nested hand-added keys survive too.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private static function mergeKeepExtra(array $existing, array $new): array
    {
        $merged = $existing;

        foreach ($new as $key => $value) {
            $merged[$key] = is_array($value) && is_array($merged[$key] ?? null)
                ? self::mergeKeepExtra($merged[$key], $value)
                : $value;
        }

        return $merged;
    }

    /** @param array<string, mixed> $catalog */
    private static function countRemoteImages(array $catalog): int
    {
        $count = 0;
        foreach ($catalog['products'] ?? [] as $product) {
            if (is_array($product) && ($product['remote_image'] ?? null) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * `[2.18]`'s count, said as a warning when there is something to warn
     * about.
     *
     * A variant with no provider mapping is a catalog entry nobody can buy —
     * the checkout refuses a priced line the provider has never heard of — so
     * an operator who has just re-synced needs to see it as a finding rather
     * than as one more number in the report. Zero stays a plain line: a sync
     * that mapped everything is not a warning.
     */
    private static function reportMissingProviders(OutputInterface $output, int $missingProviders): void
    {
        $output->writeln(sprintf(
            '%s%d variants lack provider identifiers',
            $missingProviders > 0 ? 'warning: ' : '',
            $missingProviders,
        ));
    }

    /** @param array<string, mixed> $catalog */
    private static function countMissingProviders(array $catalog): int
    {
        $count = 0;
        foreach ($catalog['products'] ?? [] as $product) {
            if (!is_array($product)) {
                continue;
            }

            foreach ($product['variants'] ?? [] as $variant) {
                if (is_array($variant) && ($variant['provider'] ?? null) === null) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
