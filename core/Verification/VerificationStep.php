<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Verification;

use AsterMD\Storefront\Checkout\Prefill;
use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Forms\AnswerSet;
use AsterMD\Storefront\Forms\TeleformMetadata;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Funnel\FunnelRules;
use AsterMD\Storefront\Journey\JourneyState;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\OperatorLog;

/**
 * What the `/verify/` step does: assemble an identity out of what the journey
 * already knows, run the configured checks over it, and write the outcome down
 * (`[22.20]`).
 *
 * It sits between {@see \AsterMD\Storefront\Http\Controller\VerifyController}
 * and {@see IdentityVerificationSequence} for one reason: **assembling the
 * identity is the part with a rule in it**, and that rule is `[13.5b]` —
 * "checkout is assembled dynamically from intake data, resolved through the
 * same field mapping used to build the EMR record, so there is one mapping,
 * not two". A controller reaching into answers by name would be the second
 * mapping.
 *
 * So the identity is read out of {@see Prefill}, which already reads the
 * questionnaire's own `db_fields` map backwards and already answers the
 * precedence question (stored answers first, working state second). What this
 * class adds is the translation from the checkout page's field names to the
 * names the identity provider uses, and the one record path checkout has no
 * box for: a date of birth. Both are the *same* map read for a different
 * consumer, which is what `[13.5b]` permits; neither invents an
 * answer-name-to-field rule of its own.
 *
 * **Data minimisation is enforced here, not in the template**, in both of its
 * halves. *Whether* to ask for a Social Security fragment is
 * {@see self::collectsSsn()}: only when the gateway is live *and* a configured
 * check actually names `ssn`, which in the shipped configuration means the most
 * sensitive datum in the funnel is never requested at all. *How much* of one to
 * send on is {@see self::lastFourOf()}, and it is enforced here rather than by
 * the template's `maxlength` because an attribute in a form is a hint to one
 * browser and not a bound on what arrives (`[22.21]`, §30).
 *
 * **Nothing identifying is logged.** The one line this class writes carries the
 * verdict's own {@see IdentityVerdict::logContext()} and the step name, which
 * between them name no person.
 */
final class VerificationStep
{
    /**
     * The checkout field names {@see Prefill} produces, mapped to the names the
     * identity provider recognises.
     *
     * Recorded against the live integration on 2026-08-25: the provider takes
     * `firstName`, `lastName`, `email`, `phone`, `dob`, `ssn`, `ipAddress` and
     * a nested `address`. The camel case is theirs, not ours.
     *
     * @var array<string, string>
     */
    private const array IDENTITY_FIELD_FOR = [
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'email' => 'email',
        'phone' => 'phone',
    ];

    /**
     * The same translation for the address block, which the provider takes
     * nested rather than flat.
     *
     * `country` is deliberately absent. `[13.36]` is a declared gap — this
     * storefront collects no country anywhere — and an assumed `US` would be
     * an invented answer sent to a compliance check. The recorded probes sent
     * one and scored exactly `0` regardless, so nothing is lost by being
     * honest about it.
     *
     * @var array<string, string>
     */
    private const array ADDRESS_FIELD_FOR = [
        'address_line' => 'streetAddress',
        'city' => 'city',
        'territory' => 'state',
        'postal_code' => 'postalCode',
    ];

    /**
     * The record path a date of birth is written to, per the verified
     * `db_fields` mapping (`date_of_birth → opportunity.dob`).
     *
     * Read from the questionnaire's own map rather than from an answer called
     * `date_of_birth`, so a form author who renames the question keeps this
     * working — the same property that makes {@see Prefill} survive a rename.
     */
    private const string DOB_PATH = 'opportunity.dob';

    /**
     * The only field this step can ask for that no earlier step collects.
     *
     * A list of one, and written as a list because the deciding rule —
     * "collect it only if a configured check names it" — is the general one and
     * a second self-declared field would otherwise arrive as a special case.
     *
     * @var list<string>
     */
    private const array SELF_DECLARED = ['ssn'];

    private readonly FunnelRules $rules;

    private readonly IdentityVerificationSequence $sequence;

    /** @var list<string> the self-declared fields some configured check actually names */
    private readonly array $wanted;

    public function __construct(
        private readonly IdentityGateway $gateway,
        private readonly TeleformSource $forms,
        ProductCatalog $catalog,
        private readonly OperatorLog $log,
        Config $config,
    ) {
        /** @var list<mixed> $checks */
        $checks = (array) $config->get('verification.checks', []);

        $this->rules = new FunnelRules($catalog);
        $this->sequence = new IdentityVerificationSequence($gateway, $checks);
        $this->wanted = self::wantedFields($checks);
    }

    /**
     * Whether the page should put the Social Security field on screen.
     *
     * Both halves are load-bearing. A deployment that has dropped the
     * `ssn_verify` rung must not be asked for an SSN it will never send, and a
     * deployment with the integration switched off must not be asked for one at
     * all — the null gateway answers inconclusive to everything, so the answer
     * would be collected, transmitted nowhere, and discarded. Collecting
     * identity data with no use for it is the plainest reading of what
     * `[22.21]` prohibits.
     */
    public function collectsSsn(): bool
    {
        return $this->gateway->isEnabled() && in_array('ssn', $this->wanted, true);
    }

    /**
     * Runs the configured checks and records what they said (`[22.20]`).
     *
     * The outcome is written to the journey whatever it is, including
     * `inconclusive`: a step that only records the answers it likes cannot be
     * a compliance artefact. Whether the outcome stops the visitor is not
     * decided here — `[22.16]` makes blocking a separate setting, and
     * {@see \AsterMD\Storefront\Funnel\StepPreconditions} is the single place
     * that reads it.
     *
     * @param array<string, mixed> $submitted this page's own POST body
     * @param ?string              $ipAddress the visitor's address, or null when the request carries none
     */
    public function run(Cart $cart, JourneyState $state, array $submitted, ?string $ipAddress): IdentityVerdict
    {
        $verdict = $this->sequence->run($this->identity($cart, $state, $submitted, $ipAddress));

        $state->recordVerification($verdict->status, $verdict->basis, $verdict->check);

        // Deliberately the verdict's own context and nothing else: it carries
        // the outcome, the check, the basis and the coded reasons, and not one
        // field of the identity that produced them (`[22.21]`).
        $this->log->info('verify.recorded', $verdict->logContext());

        return $verdict;
    }

    /**
     * The identity to check, in the provider's own vocabulary.
     *
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    private function identity(Cart $cart, JourneyState $state, array $submitted, ?string $ipAddress): array
    {
        [$metadata, $answers] = $this->intake($cart, $state);
        $prefilled = Prefill::from($metadata, $answers, $state->buyer());

        $identity = [];

        foreach (self::IDENTITY_FIELD_FOR as $field => $name) {
            $value = trim((string) ($prefilled[$field] ?? ''));
            if ($value !== '') {
                $identity[$name] = $value;
            }
        }

        $address = [];
        foreach (self::ADDRESS_FIELD_FOR as $field => $name) {
            $value = trim((string) ($prefilled[$field] ?? ''));
            if ($value !== '') {
                $address[$name] = $value;
            }
        }

        if ($address !== []) {
            $identity['address'] = $address;
        }

        $dob = self::dob($metadata, $answers);
        if ($dob !== null) {
            $identity['dob'] = $dob;
        }

        if ($ipAddress !== null) {
            $identity['ipAddress'] = $ipAddress;
        }

        if ($this->collectsSsn()) {
            $ssn = self::lastFourOf((string) ($submitted['ssn'] ?? ''));
            if ($ssn !== '') {
                $identity['ssn'] = $ssn;
            }
        }

        return $identity;
    }

    /**
     * The last four digits of whatever was typed into the Social Security box,
     * and never more than four.
     *
     * **This is the enforcement the class docblock claims and did not have.**
     * The page asks for the last four by design, but the only thing that
     * bounded the answer was `maxlength="4"` in the template — a browser hint,
     * absent from a scripted post, a curl call or a browser with the attribute
     * removed. A visitor pasting `078-05-1120` therefore sent nine digits to a
     * provider this branch's own recording shows retains what it is sent, and
     * `[22.21]` and §30 permit exactly the fragment the check needs.
     *
     * Truncating rather than refusing, because the two are the same answer:
     * recorded 2026-08-25, the provider accepts a four-digit `ssn` and returns
     * a completed check against it, so the last four of a full number is the
     * number the box asked for. A shorter answer is passed through as typed —
     * dropping it would turn a buyer's typo into an `inconclusive` verdict
     * with nothing on the page to explain it, and the check itself is the
     * right judge of a fragment that is too short.
     *
     * Digits only for the same reason it always was: the provider normalises
     * before it caches, so `078-05-1120` and `078051120` are one key, and a
     * stray dash must not be the difference between a cache hit and a metered
     * call.
     */
    private static function lastFourOf(string $submitted): string
    {
        $digits = (string) preg_replace('/\D+/', '', $submitted);

        return substr($digits, -4);
    }

    /**
     * The questionnaire this cart collects and the answers held against it.
     *
     * Resolved exactly as {@see \AsterMD\Storefront\Checkout\CheckoutService}
     * resolves it, so prefill and identity see the same form: the first intake
     * questionnaire the cart names ({@see FunnelRules}, which is also what the
     * step guard and the routing decision act on). A metadata lookup can reach
     * the EMR, so it is wrapped — a definition that cannot be resolved costs a
     * narrowing field, never the page (`[20.1]`).
     *
     * @return array{0: ?TeleformMetadata, 1: ?AnswerSet}
     */
    private function intake(Cart $cart, JourneyState $state): array
    {
        $teleformId = $this->rules->intakeForms($cart)[0] ?? null;
        if ($teleformId === null) {
            return [null, null];
        }

        try {
            $metadata = $this->forms->metadataFor($teleformId);
        } catch (\Throwable $e) {
            $this->log->warning('verify.intake_unavailable', [
                'teleform' => $teleformId,
                'exception' => $e::class,
            ]);

            return [null, null];
        }

        if ($metadata === null) {
            return [null, null];
        }

        return [$metadata, AnswerSet::fromArray($state->answersFor($teleformId))];
    }

    /**
     * The buyer's date of birth as `YYYY-MM-DD`, or null when the journey has
     * none in a shape the provider will accept.
     *
     * Two formats and no more. A questionnaire authored in the United States
     * writes either an ISO date or `m/d/Y`, and anything else is dropped rather
     * than guessed at: a mis-parsed date is a *wrong* date of birth sent to an
     * identity check, which earns `dob_mismatch` — a `failed` verdict about a
     * buyer whose date of birth was in fact correct. `[20.1]` makes that the
     * one mistake this method must not make.
     */
    private static function dob(?TeleformMetadata $metadata, ?AnswerSet $answers): ?string
    {
        if ($metadata === null || $answers === null) {
            return null;
        }

        $raw = '';
        foreach ($metadata->dbFields as $answerName => $recordPath) {
            if ((string) $recordPath !== self::DOB_PATH) {
                continue;
            }

            $value = $answers->value((string) $answerName);
            $raw = is_scalar($value) ? trim((string) $value) : '';

            break;
        }

        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d', 'm/d/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw);

            if ($parsed !== false && $parsed->format($format) === $raw) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Which of the fields this page could ask for are actually named by a
     * configured check, in `required` or in `narrowing`.
     *
     * @param list<mixed> $checks
     *
     * @return list<string>
     */
    private static function wantedFields(array $checks): array
    {
        $named = [];

        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }

            foreach ([...(array) ($check['required'] ?? []), ...(array) ($check['narrowing'] ?? [])] as $field) {
                if (in_array($field, self::SELF_DECLARED, true) && !in_array($field, $named, true)) {
                    $named[] = $field;
                }
            }
        }

        return $named;
    }
}
