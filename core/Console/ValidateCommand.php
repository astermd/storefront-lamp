<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Catalog\CatalogValidator;
use AsterMD\Sdk\Enum\IdentityCheck;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Payment\AdapterCapabilities;
use AsterMD\Storefront\Payment\NullPaymentAdapter;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Seo\MetaResolver;
use AsterMD\Storefront\Seo\RobotsPolicy;
use AsterMD\Storefront\Seo\StructuredData\StructuredData;
use AsterMD\Storefront\Support\Config;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `config:validate` — runs {@see CatalogValidator}'s structural pass over the
 * merged catalog (via {@see CatalogProvider}, so overrides are already
 * applied) and prints the result. Override ids the merge itself couldn't
 * apply ({@see CatalogProvider::lastIgnoredOverrideIds()}) are reported
 * alongside the validator's own warnings, since both are "the operator should
 * look at this" signals of the same kind.
 *
 * `--live` additionally resolves every `rx`/`otc` product's
 * `emr_product_id` against the live EMR via `products()->view()` — `lab` and
 * `free-addon` products are exempt since they aren't sold as standalone EMR
 * products. A non-2xx response or transport failure for any one product is
 * reported as an error naming the slug and id; it does not stop the rest of
 * the sweep.
 *
 * `config/channel.generated.php` (written by `theme:sync`, gitignored) is
 * also inspected: a missing file is a warning (a fresh install just hasn't
 * synced yet, not a data-integrity problem), but a present-and-malformed one,
 * or one missing `channel.id` / `payment_processor.provider_category`, is an
 * error — a business rule can't resolve a payment processor without those.
 * `payment_processor.config` missing is only a warning, since a currently
 * empty processor config block (e.g. no live credentials yet in a dev
 * environment) doesn't necessarily indicate corruption.
 *
 * On top of that shallow shape check sits the payment pass ([2.21]), which
 * runs whenever the command is given a `Config` and an adapter resolver. It
 * asks the four questions that decide whether this deployment can take money
 * at all, and every one of them is an error rather than a warning because
 * each fails *every* checkout rather than degrading one:
 *
 *  - a real adapter resolved, not {@see NullPaymentAdapter};
 *  - every key the adapter declares in `requiredConfigKeys` present in the
 *    channel's `payment_processor.config`;
 *
 * — the first two only once `channel.generated.php` exists, since a fresh
 * install that has not synced yet is already reported as the warning above
 * and would otherwise fail its own first `config:validate` —
 *
 *  - `payment.shipping_profile_id` set, since the configured provider refuses
 *    any order carrying a payment action without one;
 *  - every configured order bump resolving to a catalog product that has a
 *    provider mapping ([27.9]) — an unmappable bump is an offer that cannot
 *    be charged for;
 *  - every configured post-purchase upsell resolving the same way, plus every
 *    slug in its `offer_after` list ([16.17]). An upsell is charged *after*
 *    the buyer has already paid, so discovering it cannot be charged for is
 *    later there than anywhere else in the funnel.
 *
 * The catalog's own unmapped variants are a *warning* with a count, not an
 * error: the recorded live channel legitimately has one product with no
 * variants and therefore no mappings, and an unorderable catalog entry is a
 * merchandising decision rather than a broken deployment.
 *
 * Two configuration files are read for the same kind of fault the consent
 * check looks for — a setting whose mistake is silent in the place it matters.
 * `config/verification.php` describes a gate, and an unrecognised or
 * unimplemented `placement` makes the step vanish from the funnel while the
 * gate it was meant to guard reports itself satisfied: a deployment that asked
 * for a blocking identity check gets an open door, indistinguishable from a
 * passed one from the inside. `config/abandonment.php` describes a sweep, and
 * a zero interval or a consent key naming nothing produces a run that exits
 * cleanly and reports the wrong journeys. Both are errors, on `[26.9]`'s
 * precedent, and both are skipped entirely when the file is absent — an absent
 * verification file already means "no step" to every reader of it.
 *
 * `[24.11]` asks for one more check of the same kind: the structured data this
 * deployment would publish is assembled for the site root and for every listed
 * product page and inspected before it can reach a crawler, because its
 * inputs — the synced catalog and a hand-edited `app.seo` — both change
 * without a code change, and a malformed node renders as well-formed HTML that
 * a consumer discards in silence.
 *
 * Alongside errors and warnings there is a third, quieter channel: **notes**,
 * printed unconditionally and affecting nothing. [15.15] asks this command to
 * report the deployment's PCI posture, and the run that most needs to state it
 * is the clean one — so it can be neither an error nor a warning, both of
 * which mean "act on this" and the latter of which would suppress the
 * "Catalog valid." line on every healthy deployment.
 *
 * Exit codes: any error (structural, channel-file, payment, or live) -> 2;
 * warnings only -> 0 (warnings are printed either way); nothing to report ->
 * 0 with "Catalog valid.".
 */
#[AsCommand(name: 'config:validate', description: 'Validate the merged catalog structurally, optionally against the live EMR')]
final class ValidateCommand extends Command
{
    /**
     * The four placements `[22.14]` declares, and the three of them this
     * application implements.
     *
     * Named here rather than read off
     * {@see \AsterMD\Storefront\Verification\VerificationPlacement} because
     * that class knows only which placement is pre-payment: to it the other
     * three and a typo are the same answer, which is exactly the fault this
     * command exists to catch.
     *
     * `async` is declared and not built. `[22.19]` wants the same durable,
     * authenticated, session-independent inbound handling a clinical review
     * outcome needs, and this deployment has no inbound receiver — so it is a
     * known name that must still be refused, and it is listed separately
     * rather than left out so the operator who chose it is told which of the
     * two mistakes they made.
     *
     * @var list<string>
     */
    private const array PLACEMENTS = ['intake', 'post_checkout', 'receipt', 'async'];

    /** @var list<string> */
    private const array IMPLEMENTED_PLACEMENTS = ['intake', 'post_checkout', 'receipt'];

    /**
     * `$config` and `$adapter` are optional so a caller that only wants the
     * structural pass — every test written before there was a payment provider
     * to check — keeps working unchanged. The adapter arrives as a closure
     * because resolving one reads credentials and builds an HTTP client, and
     * a run that never reaches the payment pass must not pay for that.
     *
     * @param (\Closure(): PaymentAdapter)|null $adapter null skips the payment pass entirely
     */
    public function __construct(
        private readonly CatalogProvider $catalogProvider,
        private readonly ClientFactory $clientFactory,
        private readonly string $configDir,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?Config $config = null,
        private readonly ?\Closure $adapter = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('live', null, InputOption::VALUE_NONE, 'Also resolve every rx/otc product against the live EMR');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->catalogProvider->catalog();

        $result = (new CatalogValidator())->validate($catalog);
        $errors = $result['errors'];
        $warnings = $result['warnings'];

        /** @var list<string> $notes facts the operator should read on every run, actionable on none */
        $notes = [];

        // Checked here rather than beside the other payment checks, and the
        // placement is the point. `validatePayment()` returns early when there
        // is no configuration or no adapter, and throws outright when the
        // channel file names an unusable endpoint — so a deployment with a
        // broken provider block would have been told about the provider and
        // never about this, while still writing card numbers to disk.
        //
        // This switch has no dependency on the provider, so it has no business
        // sitting behind one. An error rather than a warning for the same
        // reason: while it is on, `storage/logs/` accumulates primary account
        // numbers, security codes and live bearer tokens in clear text —
        // cardholder data at rest, which `[15.8]` forbids this application's
        // own storage from holding. A warning is something an operator learns
        // to scroll past.
        //
        // Identity documents are named here as well, and they are the reason
        // this message was widened. The SDK does mark the identity-verify path
        // as PHI and drop its body — but only through a redactor this
        // application deliberately does not install, because a redacted
        // transcript cannot be replayed by hand and replaying the exact bytes
        // is the whole point of the switch. The SDK's flag is all or nothing,
        // so there is no arrangement that keeps verbatim tokens and still
        // suppresses the PHI path. While this is on, a Social Security Number
        // and a date of birth reach the same file the card does, which
        // `[22.21]` forbids outright rather than merely discourages.
        if ($this->config?->get('app.debug.wire_log') === true) {
            $errors[] = 'debug: app.debug.wire_log is ON — every provider and EMR call is being written to storage/logs/ unredacted: card numbers, bearer tokens, and the identity-check payloads carrying Social Security Numbers and dates of birth. Unset WIRE_LOG before this deployment takes a real payment or runs a real identity check.';
        }

        foreach ($this->catalogProvider->lastIgnoredOverrideIds() as $id) {
            $warnings[] = 'override ignored: ' . $id;
        }

        $this->validateChannelGeneratedFile($errors, $warnings);
        $this->validatePayment($catalog, $errors, $warnings, $notes);
        $this->validateConsentLinks($errors);
        $this->validateVerification($errors);
        $this->validateAbandonment($errors);
        $this->validateStructuredData($errors);
        $this->validateSeoUrls($errors);

        if ((bool) $input->getOption('live')) {
            $errors = array_merge($errors, $this->runLiveChecks($catalog));
        }

        foreach ($errors as $error) {
            $output->writeln('ERROR: ' . $error);
        }

        foreach ($warnings as $warning) {
            $output->writeln('warning: ' . $warning);
        }

        // Printed before the verdict and regardless of it: a note is context
        // for whatever the verdict turns out to be, not part of it.
        foreach ($notes as $note) {
            $output->writeln('note: ' . $note);
        }

        if ($errors !== []) {
            return 2;
        }

        if ($warnings === []) {
            $output->writeln('Catalog valid.');
        }

        return Command::SUCCESS;
    }

    /**
     * `[24.11]` — the structured data this deployment would publish, checked
     * here rather than in a search console weeks later.
     *
     * Deploy time is the only place the check can live. The inputs are the
     * catalog, which a `theme:sync` replaces wholesale without anyone editing
     * this repository, and `app.seo`, which a client edits by hand — so a
     * node can become malformed with no code change and no test to notice. And
     * the failure is silent by construction: a `Product` whose offer names no
     * currency, or whose URLs are relative because `app.url` was never set,
     * renders as a perfectly well-formed `<script>` block that a crawler
     * discards without telling anybody.
     *
     * An error rather than a warning, on the same reasoning `[24.9]` gives for
     * the offer itself: published structured pricing that disagrees with the
     * checkout is a trust and compliance problem, not an inconvenience, and a
     * warning is something an operator learns to scroll past.
     *
     * Skipped entirely when the command was built without configuration — the
     * structural-pass callers have no `app.seo` to be judged against, and
     * inventing one would report a deployment that does not exist.
     *
     * @param list<string> $errors
     */
    /**
     * The URL and indexing configuration a deployment would publish.
     *
     * Errors rather than warnings, on the same reasoning `[26.9]` gives: every
     * fault here is silent in exactly the place it matters. An unset `app.url`
     * publishes root-relative canonical links, sitemap locations and a
     * `Sitemap:` line, all of which are required to name a host and none of
     * which look wrong on a rendered page. A rule the policy cannot read is
     * skipped, so the path it was written to fence off stays crawlable — and
     * `robots.txt`, `sitemap.xml` and the meta tag all agree it is crawlable,
     * which is what makes it invisible.
     *
     * Unlike the structured-data pass this is never conditional on an emitter
     * being enabled: `app.url` is wrong for `robots.txt` and `sitemap.xml`
     * whether or not any emitter is on.
     */
    private function validateSeoUrls(array &$errors): void
    {
        if ($this->config === null) {
            return;
        }

        foreach ((new MetaResolver($this->config))->problems() as $problem) {
            $errors[] = 'seo: ' . $problem;
        }

        foreach (RobotsPolicy::fromConfig($this->config)->problems() as $problem) {
            $errors[] = 'seo: ' . $problem;
        }
    }
    private function validateStructuredData(array &$errors): void
    {
        if ($this->config === null) {
            return;
        }

        foreach (StructuredData::fromConfig($this->config, $this->catalogProvider)->problems() as $problem) {
            $errors[] = 'structured data: ' . $problem;
        }
    }

    /**
     * `config/verification.php` describes a gate, so a mistake in it opens one
     * (`[22.13]`-`[22.16]`).
     *
     * Errors rather than warnings throughout, on `[26.9]`'s precedent: the
     * failure mode of every check below is silent in the place it matters. An
     * unknown `placement` is the plainest of them. `intake` is the only
     * placement that runs before payment, so anything else — `async`, which is
     * declared and unimplemented, or a typo for one that is — makes
     * {@see \AsterMD\Storefront\Verification\VerificationPlacement} answer
     * "there is no pre-payment step": no step appears in the funnel,
     * `blocks()` is false, and `satisfied()` is therefore **true**. A
     * deployment that asked for a blocking identity check gets an open door,
     * and nothing at runtime is positioned to notice, because from the inside
     * an open door and a passed check look identical.
     *
     * `config/verification.php` already says selecting `async` is "a
     * configuration error rather than a silent downgrade". This is where that
     * sentence becomes true.
     *
     * A deployment with no verification file at all is not reported: an absent
     * configuration already means "no step" to every reader of it, and the
     * structural-pass callers that ship no such file have no fault to be told
     * about.
     *
     * @param list<string> $errors
     */
    private function validateVerification(array &$errors): void
    {
        $verification = $this->config?->get('verification');
        if (!is_array($verification)) {
            return;
        }

        $placement = $verification['placement'] ?? null;
        $named = is_scalar($placement) ? (string) $placement : get_debug_type($placement);

        if (!is_string($placement) || !in_array($placement, self::PLACEMENTS, true)) {
            $errors[] = sprintf(
                'verification: placement "%s" is not one of %s — an unrecognised placement removes the step from the funnel and reports its own gate satisfied, so a blocking check silently becomes an open door',
                $named,
                implode(', ', self::PLACEMENTS),
            );
        } elseif (!in_array($placement, self::IMPLEMENTED_PLACEMENTS, true)) {
            $errors[] = sprintf(
                'verification: placement "%s" is declared but not implemented — `[22.19]` needs an inbound receiver this deployment does not have, and an unimplemented placement fails exactly like a misspelt one: no step, and a gate that reports itself satisfied. Choose one of %s',
                $named,
                implode(', ', self::IMPLEMENTED_PLACEMENTS),
            );
        }

        // Both are read with `=== true`, so a truthy string is off and a
        // falsy-looking one is off as well — the two disagree with a reader
        // rather than with each other, which is why a non-boolean is refused
        // instead of coerced.
        foreach (['enabled', 'blocking'] as $flag) {
            if (array_key_exists($flag, $verification) && !is_bool($verification[$flag])) {
                $errors[] = sprintf(
                    'verification: %s must be a boolean, not %s — it is read with a strict comparison, so anything else is off however it reads',
                    $flag,
                    get_debug_type($verification[$flag]),
                );
            }
        }

        $this->validateVerificationChecks($verification['checks'] ?? null, $errors);
    }

    /**
     * The check sequence: a non-empty list, each rung naming a check the
     * provider has and the two field lists it is sent.
     *
     * The slug is checked against {@see IdentityCheck} rather than a list kept
     * here, because the sequence drops a rung whose slug it cannot map — so a
     * typo is not a broken check, it is a check that silently never runs and
     * an escalation that is quietly one rung shorter than the file says.
     *
     * `narrowing` is required to be present, and that is recorded rather than
     * fastidious: called with its required fields alone, `crosscheck` came back
     * `address_invalid` and `phone_invalid` on 2026-08-25 — it penalises fields
     * that were never sent. A rung with no narrowing block is a rung
     * configured to fail. A missing `required` is worse still: the provider
     * answers a 400 rather than a verdict.
     *
     * @param list<string> $errors
     */
    private function validateVerificationChecks(mixed $checks, array &$errors): void
    {
        if (!is_array($checks) || $checks === []) {
            $errors[] = 'verification: checks is empty — the step would run no checks and record an inconclusive verdict for everybody';

            return;
        }

        $known = array_map(static fn (IdentityCheck $case): string => $case->value, IdentityCheck::cases());

        foreach (array_values($checks) as $index => $check) {
            if (!is_array($check)) {
                $errors[] = sprintf('verification: checks[%d] is not a check definition', $index);

                continue;
            }

            $slug = $check['slug'] ?? null;
            if (!is_string($slug) || !in_array($slug, $known, true)) {
                $errors[] = sprintf(
                    'verification: checks[%d] names an unknown check "%s" — the provider has %s, and a rung it cannot map is dropped rather than reported',
                    $index,
                    is_scalar($slug) ? (string) $slug : get_debug_type($slug),
                    implode(', ', $known),
                );
            }

            $required = $check['required'] ?? null;
            if (!self::isStringList($required) || $required === []) {
                $errors[] = sprintf(
                    'verification: checks[%d] required must be a non-empty list of field names — the provider answers a 400 rather than a verdict when one is missing',
                    $index,
                );
            }

            $narrowing = $check['narrowing'] ?? null;
            if (!self::isStringList($narrowing)) {
                $errors[] = sprintf(
                    'verification: checks[%d] narrowing must be a list of field names — sent alongside the required ones, because a check called with its minimum penalises the fields it was not given',
                    $index,
                );
            }
        }
    }

    /**
     * `config/abandonment.php` — the three intervals and the consent key
     * (`[21.11]`, `[21.11b]`, `[21.12]`).
     *
     * Errors for the same reason the rest of this command's are: each failure
     * produces a sweep that runs, exits zero, and is wrong. Zero is the value
     * an unset environment variable casts to, which makes it the
     * misconfiguration that arrives by accident rather than by decision — a
     * zero `idle_seconds` reports every live journey as abandoned, and a zero
     * `lookback_seconds` or `limit` reports none at all. Neither says anything
     * about itself in the output.
     *
     * The consent key is the quietest of the four. `[21.11b]` requires a
     * journey that declined marketing to be marked so the platform can
     * suppress it; a key naming no consent reports `not_asked` for every
     * journey, including the ones that declined, so the suppression signal
     * stops existing without ever failing.
     *
     * @param list<string> $errors
     */
    private function validateAbandonment(array &$errors): void
    {
        $abandonment = $this->config?->get('abandonment');
        if (!is_array($abandonment)) {
            return;
        }

        foreach (['idle_seconds', 'lookback_seconds', 'limit'] as $key) {
            $value = $abandonment[$key] ?? null;
            if (!is_int($value) || $value <= 0) {
                $errors[] = sprintf(
                    'abandonment: %s must be a positive integer, not %s — a sweep configured with this runs, exits zero, and reports the wrong journeys',
                    $key,
                    is_scalar($value) ? var_export($value, true) : get_debug_type($value),
                );
            }
        }

        $marketingKey = $abandonment['marketing_consent_key'] ?? null;
        if (!is_string($marketingKey) || $marketingKey === '') {
            $errors[] = 'abandonment: marketing_consent_key must name a consent key';

            return;
        }

        $consents = $this->config?->get('consent.consents');
        if (!is_array($consents)) {
            return;
        }

        $keys = [];
        foreach ($consents as $consent) {
            if (is_array($consent) && is_string($consent['key'] ?? null)) {
                $keys[] = $consent['key'];
            }
        }

        if (!in_array($marketingKey, $keys, true)) {
            $errors[] = sprintf(
                'abandonment: marketing_consent_key "%s" names no consent in config/consent.php (%s) — every signal would report not_asked, including the journeys that declined',
                $marketingKey,
                $keys === [] ? 'which declares none' : implode(', ', $keys),
            );
        }
    }

    /** Whether $value is a list of non-empty strings, which is what both field lists are. */
    private static function isStringList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Every configured consent must link only to paths this application
     * actually serves (`[26.9]`).
     *
     * An error rather than a warning, because the failure is silent in the
     * place it matters: the consent control still renders, still ticks, and
     * still records agreement, while the document the buyer would have read
     * answers 404. A consent whose wording cannot be reached is not evidence
     * of anything (`[26.6]`), and nothing at runtime is positioned to notice —
     * the template renders whatever href the config holds.
     *
     * The served paths are read by replaying the route file against a
     * recorder rather than by keeping a second list here, so a route that is
     * renamed or removed is caught by this check instead of silently
     * disagreeing with it.
     *
     * @param list<string> $errors
     */
    private function validateConsentLinks(array &$errors): void
    {
        $consents = $this->config?->get('consent.consents');
        if (!is_array($consents) || $consents === []) {
            return;
        }

        $served = $this->servedPaths();
        if ($served === null) {
            return;
        }

        foreach ($consents as $consent) {
            if (!is_array($consent)) {
                continue;
            }

            $key = (string) ($consent['key'] ?? '(unnamed)');
            foreach (is_array($consent['links'] ?? null) ? $consent['links'] : [] as $link) {
                if (!is_string($link) || $link === '') {
                    continue;
                }

                // Only local paths are ours to serve; an off-site policy URL is
                // the deployment's to keep working.
                if (!str_starts_with($link, '/')) {
                    continue;
                }

                if (!in_array($link, $served, true)) {
                    $errors[] = sprintf(
                        'consent: %s links to %s, which this application does not serve',
                        $key,
                        $link,
                    );
                }
            }
        }
    }

    /**
     * The GET paths declared in the route file, or null when it cannot be
     * read — an unreadable route table is the shape check's problem to report,
     * not this one's, and guessing here would turn one fault into several.
     *
     * @return list<string>|null
     */
    private function servedPaths(): ?array
    {
        $file = $this->configDir . '/routes.php';
        if (!is_file($file)) {
            return null;
        }

        $declare = require $file;
        if (!$declare instanceof \Closure) {
            return null;
        }

        $paths = [];
        $recorder = new class ($paths) {
            /** @param list<string> $paths */
            public function __construct(private array &$paths)
            {
            }

            public function get(string $pattern, mixed $handler): self
            {
                $this->paths[] = $pattern;

                return $this;
            }

            public function post(string $pattern, mixed $handler): self
            {
                return $this;
            }

            public function setArgument(string $name, string $value): self
            {
                return $this;
            }

            public function setName(string $name): self
            {
                return $this;
            }
        };

        try {
            $declare($recorder);
        } catch (\Throwable) {
            return null;
        }

        return $paths;
    }

    /**
     * Inspects `<configDir>/channel.generated.php` (written by `theme:sync`).
     * Missing entirely -> warning (a fresh install hasn't synced yet).
     * Present but not an array, or missing `channel.id` /
     * `payment_processor.provider_category` -> error (a sync ran but the
     * result is unusable). `payment_processor.config` missing -> warning
     * only (may legitimately be empty pre-credentials).
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateChannelGeneratedFile(array &$errors, array &$warnings): void
    {
        $file = $this->configDir . '/channel.generated.php';

        if (!is_file($file)) {
            $warnings[] = 'channel.generated.php not found — run bin/console theme:sync --apply';

            return;
        }

        /** @psalm-suppress UnresolvableInclude */
        $payload = require $file;

        if (!is_array($payload)) {
            $errors[] = 'channel.generated.php is malformed';

            return;
        }

        $channelId = $payload['channel']['id'] ?? null;
        if (!is_string($channelId) || $channelId === '') {
            $errors[] = 'channel.generated.php: channel.id is missing or blank';
        }

        $providerCategory = $payload['payment_processor']['provider_category'] ?? null;
        if (!is_string($providerCategory) || $providerCategory === '') {
            $errors[] = 'channel.generated.php: payment_processor.provider_category is missing or blank';
        }

        if (!is_array($payload['payment_processor']['config'] ?? null)) {
            $warnings[] = 'channel.generated.php: payment_processor.config is missing';
        }
    }

    /**
     * The payment pass ([2.21]) — see the class docblock for why each finding
     * has the severity it does.
     *
     * Skipped in full when the command was built without a `Config` and an
     * adapter resolver, because every question below is unanswerable without
     * both and reporting "unknown" as an error would fail every caller that
     * only wanted the structural pass.
     *
     * @param array<string, mixed> $catalog
     * @param list<string>         $errors
     * @param list<string>         $warnings
     * @param list<string>         $notes
     */
    private function validatePayment(array $catalog, array &$errors, array &$warnings, array &$notes): void
    {
        if ($this->config === null || $this->adapter === null) {
            return;
        }

        $adapter = ($this->adapter)();
        $capabilities = $adapter->capabilities();

        // `[15.15]`: the deployment's PCI scope is a function of the
        // configured provider, and an operator has to be able to read it
        // before going live rather than after. `provider:ping` prints it too,
        // but that command reaches the network and a validator must not.
        $notes[] = sprintf('payment: PCI posture — %s', $capabilities->pciPosture);

        // A raw-carry-forward adapter cannot serve post-purchase upsells in
        // this application, and saying so is better than the alternative. The
        // card is collected in one request and is not held past it: journey
        // state has no field that survives one (`[15.8]`). So an accepted
        // upsell under such an adapter has nothing to charge and is skipped.
        // Holding the card instead would put the deployment in full scope for
        // the sake of an optional add-on.
        if ($capabilities->credentialStrategy === AdapterCapabilities::STRATEGY_RAW_CARRY_FORWARD
            && $this->config->get('upsells.upsells', []) !== []) {
            $warnings[] = 'payment: the configured provider declares raw-card carry-forward, which cannot serve post-purchase upsells here — every accepted upsell will be skipped';
        }

        // The two adapter questions are asked only of a deployment that has
        // synced. A missing `channel.generated.php` is already reported above
        // as the warning it is — a fresh install that has not run `theme:sync`
        // yet, rather than a broken one — and turning that one fact into two
        // further errors would contradict that reading of it.
        if (is_file($this->configDir . '/channel.generated.php')) {
            if ($adapter instanceof NullPaymentAdapter) {
                $errors[] = 'payment: no adapter resolved — the channel names no provider category, or none is registered for it';
            }

            $processorConfig = (array) $this->config->get('channel.generated.payment_processor.config', []);
            foreach ($capabilities->requiredConfigKeys as $key) {
                $value = $processorConfig[$key] ?? null;
                if ($value === null || $value === '') {
                    $errors[] = sprintf(
                        'payment: channel.generated.php payment_processor.config is missing %s, which the %s adapter requires',
                        $key,
                        $capabilities->providerCategory,
                    );
                }
            }
        }

        $shippingProfileId = $this->config->get('payment.shipping_profile_id');
        if (!is_int($shippingProfileId) || $shippingProfileId <= 0) {
            $errors[] = 'payment: payment.shipping_profile_id is not set — the provider refuses every order without one';
        }

        $this->validateBumps($catalog, $errors);
        $this->validateUpsells($catalog, $errors);

        $unmapped = self::countUnmappedVariants($catalog);
        if ($unmapped > 0) {
            $warnings[] = sprintf('payment: %d catalog variants have no provider mapping and cannot be ordered', $unmapped);
        }
    }

    /**
     * `[27.9]`: a bump the provider has never heard of is an offer that cannot
     * be charged for, so it is an error here rather than something discovered
     * when a buyer accepts one.
     *
     * @param array<string, mixed> $catalog
     * @param list<string>         $errors
     */
    private function validateBumps(array $catalog, array &$errors): void
    {
        $products = (array) ($catalog['products'] ?? []);

        foreach ((array) $this->config?->get('cross-sells.bumps', []) as $trigger => $offers) {
            foreach ((array) $offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }

                $slug = trim((string) ($offer['slug'] ?? ''));
                $product = $products[$slug] ?? null;

                if ($slug === '' || !is_array($product)) {
                    $errors[] = sprintf('payment: order bump on %s names %s, which is not in the catalog', (string) $trigger, $slug === '' ? '(nothing)' : $slug);

                    continue;
                }

                if (!self::hasProviderMapping($product, $offer['variant_id'] ?? null)) {
                    $errors[] = sprintf('payment: order bump %s has no provider mapping and could never be charged for', $slug);
                }
            }
        }
    }

    /**
     * `[16.17]`: post-purchase upsells are validated with the catalog's own
     * rigour, and every finding is an error for a reason the bumps above only
     * half share. An unmappable bump is caught when the buyer accepts it,
     * before any money moves. An upsell is offered *after* a successful
     * charge, so its faults surface at the one moment the storefront has
     * nothing left to offer the buyer instead — and
     * {@see \AsterMD\Storefront\Upsell\Upsells::resolve()} deliberately
     * degrades to silence there (`[16.4]`), which makes this pass the only
     * place a misconfiguration is ever loud.
     *
     * `offer_after` is checked too, and an empty one counts: an upsell nothing
     * can earn is dead configuration that reads, at runtime, as an ordinary
     * empty queue.
     *
     * @param array<string, mixed> $catalog
     * @param list<string>         $errors
     */
    private function validateUpsells(array $catalog, array &$errors): void
    {
        $products = (array) ($catalog['products'] ?? []);

        foreach ((array) $this->config?->get('upsells.upsells', []) as $key => $upsell) {
            if (!is_array($upsell)) {
                continue;
            }

            $name = (string) $key;
            $slug = trim((string) ($upsell['slug'] ?? ''));
            $product = $products[$slug] ?? null;

            if ($slug === '' || !is_array($product)) {
                $errors[] = sprintf(
                    'payment: upsell %s names %s, which is not in the catalog',
                    $name,
                    $slug === '' ? '(nothing)' : $slug,
                );
            } elseif (!self::hasProviderMapping($product, $upsell['variant_id'] ?? null)) {
                $errors[] = sprintf('payment: upsell %s has no provider mapping and could never be charged for', $name);
            }

            $triggers = is_array($upsell['offer_after'] ?? null) ? $upsell['offer_after'] : [];

            if ($triggers === []) {
                $errors[] = sprintf('payment: upsell %s names nothing in offer_after, so no purchase could ever earn it', $name);
            }

            foreach ($triggers as $trigger) {
                $trigger = trim((string) $trigger);

                if ($trigger === '' || !is_array($products[$trigger] ?? null)) {
                    $errors[] = sprintf(
                        'payment: upsell %s is offered after %s, which is not in the catalog',
                        $name,
                        $trigger === '' ? '(nothing)' : $trigger,
                    );
                }
            }
        }
    }

    /**
     * Whether the variant an offer names — or any variant, when it names none
     * — carries the offer and product ids the provider is addressed by.
     *
     * Shared by the bump and upsell passes because the question is the same
     * one: a second copy of it could answer differently, and then one of the
     * two offer kinds would be validated to a standard the other was not.
     *
     * @param array<string, mixed> $product
     */
    private static function hasProviderMapping(array $product, mixed $variantId): bool
    {
        foreach ((array) ($product['variants'] ?? []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            if (is_string($variantId) && $variantId !== '' && ($variant['id'] ?? null) !== $variantId) {
                continue;
            }

            $provider = $variant['provider'] ?? null;
            if (is_array($provider) && ($provider['offer_id'] ?? null) !== null && ($provider['product_id'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counted rather than named: the recorded live channel has a whole product
     * with no variants at all, and listing every unmappable variant would bury
     * the errors above it.
     *
     * @param array<string, mixed> $catalog
     */
    private static function countUnmappedVariants(array $catalog): int
    {
        $count = 0;

        foreach ((array) ($catalog['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }

            foreach ((array) ($product['variants'] ?? []) as $variant) {
                if (is_array($variant) && ($variant['provider'] ?? null) === null) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return list<string>
     */
    private function runLiveChecks(array $catalog): array
    {
        try {
            $client = $this->clientFactory->create($this->httpClient);
        } catch (\Throwable $e) {
            return ['EMR client unavailable: ' . $e->getMessage()];
        }

        $errors = [];

        foreach ((array) ($catalog['products'] ?? []) as $key => $product) {
            if (!is_array($product)) {
                continue;
            }

            $kind = $product['kind'] ?? null;
            if ($kind !== 'rx' && $kind !== 'otc') {
                continue;
            }

            $id = $product['emr_product_id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }

            $slug = is_string($product['slug'] ?? null) && $product['slug'] !== '' ? $product['slug'] : (string) $key;

            try {
                $client->products()->view($id);
            } catch (\Throwable) {
                $errors[] = sprintf('EMR cannot resolve product %s (%s)', $slug, $id);
            }
        }

        return $errors;
    }
}
