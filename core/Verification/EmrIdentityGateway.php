<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Verification;

use AsterMD\Sdk\AsterMDClient;
use AsterMD\Sdk\Enum\IdentityCheck;
use AsterMD\Sdk\Exception\ApiException;
use AsterMD\Storefront\Emr\ClientFactory;
use AsterMD\Storefront\Support\OperatorLog;
use Psr\Http\Client\ClientInterface;

/**
 * {@see IdentityGateway} over the SDK's `verification()->verifyIdentity()`.
 *
 * **The verdict is only ever in the body.** Recorded live on 2026-08-25: every
 * identity call answers HTTP 200, for a plausible identity and for nonsense
 * alike, so any branch on the HTTP status to decide an outcome is wrong by
 * construction. What the status still tells us is whether the request was well
 * formed, and that is a different question with a different consequence.
 *
 * **Three channels, kept apart:**
 *
 * - HTTP 200 with `valid` set — the provider's verdict, passed straight to
 *   {@see IdentityVerdict::fromProviderData()}. The only channel that can
 *   produce a statement about the buyer.
 * - {@see ApiException} **400** — the request was missing a field the check
 *   requires (recorded: `dob_verify` without `phone` → `"Phone number is
 *   required."`). That is *our* bug, so it is inconclusive and logged at
 *   **warning**: nobody is coming to fix it unless the log says so.
 * - anything else — a 403 while the identity integration is inactive, a
 *   transport failure, a provider outage, any `\Throwable` at all. Also
 *   inconclusive, logged at info. `[20.1]`: a check that could not run must
 *   never present as a buyer failing, and must never take the journey down
 *   with it either.
 *
 * **Nothing about the identity reaches the operator log** (`[22.21]`). Not the
 * payload, which carries a date of birth and a Social Security Number; and not
 * the provider's own error message, which can quote its input straight back.
 * The log gets the check slug, the status, the basis and the coded reasons.
 *
 * The SDK's own debug transcript is a separate log and a separate promise, and
 * it is worth being precise about: `LogRedactor` treats
 * `/extensions/identity-verify` as a PHI path and drops its body — but only
 * when the client is built with `debugRedact` on, and this storefront builds
 * it with `debugRedact: false` so that a wire log can be replayed by hand. So
 * with `app.debug.wire_log` on, this request's body lands in
 * `storage/logs/emr-wire.log` in full. That is why `config:validate` refuses a
 * deployment with the switch left on, and why the switch stays off.
 *
 * **Retention lives at the provider, not here.** The recorded crosscheck
 * response echoes `raw.request.store: true` — the identity submitted is
 * retained by the provider, outside this storefront's control and outside its
 * `[22.21]`/§30 retention policy. Nothing on this side can shorten that; it is
 * recorded so a deployment turning identity checks on knows what it is
 * agreeing to.
 *
 * The client is built on first use rather than in the constructor, so a
 * deployment with the step enabled and credentials missing still renders the
 * page.
 */
final class EmrIdentityGateway implements IdentityGateway
{
    /**
     * The status that means the payload was wrong rather than the person.
     *
     * A 422 would mean the same thing and is not listed, because the SDK maps
     * it to `ValidationException` and the recorded behaviour of this endpoint
     * is a flat 400. Should one appear it lands in the catch-all, which is
     * inconclusive too — the same buyer-facing outcome, one shelf down in the
     * log.
     */
    private const int REQUEST_REJECTED = 400;

    private ?AsterMDClient $client = null;

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly OperatorLog $log,
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    public function verify(string $check, array $identity): IdentityVerdict
    {
        $slug = IdentityCheck::tryFrom($check);

        if ($slug === null) {
            // A configured check this SDK does not implement. Neither a pass
            // nor a failure is an honest reading of a typo in a config file,
            // and the provider is not asked.
            $this->log->warning('identity.check_unknown', ['check' => $check]);

            return IdentityVerdict::inconclusive($check, 'unknown_check');
        }

        try {
            $verdict = IdentityVerdict::fromProviderData(
                $check,
                $this->client()->verification()->verifyIdentity($slug, $identity)->data(),
            );
        } catch (ApiException $e) {
            return $e->statusCode() === self::REQUEST_REJECTED
                ? $this->requestRejected($check, $e)
                : $this->unavailable($check, $e, $e->statusCode());
        } catch (\Throwable $e) {
            return $this->unavailable($check, $e, null);
        }

        $this->log->info('identity.checked', $verdict->logContext());

        return $verdict;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * The check ran and refused the request, not the person.
     *
     * Warning rather than info, and the one place in this class that is: the
     * provider is telling us our own payload is incomplete, which is a defect
     * in what the journey collected or in how it was mapped. Silently
     * degrading it to inconclusive without saying so would leave a step that
     * never decides anything and never explains why.
     */
    private function requestRejected(string $check, ApiException $e): IdentityVerdict
    {
        $this->log->warning('identity.request_rejected', [
            'check' => $check,
            'exception' => $e::class,
            'status' => $e->statusCode(),
        ]);

        return IdentityVerdict::inconclusive($check, 'request_rejected');
    }

    /**
     * The check could not run at all.
     *
     * Info rather than warning because a refusal is the expected state until
     * the identity integration is granted — the sibling email and address
     * checks on the same resource answer 403 today — and an alert an operator
     * is told to expect is an alert they learn to ignore (`[20.10]`).
     *
     * The exception class and the status go in the line; the message never
     * does, because an API error can quote its own input and its own input is
     * an identity.
     */
    private function unavailable(string $check, \Throwable $e, ?int $status): IdentityVerdict
    {
        $this->log->info('identity.unavailable', [
            'check' => $check,
            'exception' => $e::class,
            'status' => $status,
        ]);

        return IdentityVerdict::inconclusive($check, 'unavailable');
    }

    private function client(): AsterMDClient
    {
        return $this->client ??= $this->clients->create($this->httpClient);
    }
}
