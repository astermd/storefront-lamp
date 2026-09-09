<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Funnel;

use AsterMD\Storefront\Domain\Cart;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Journey\JourneyState;

/**
 * What a cart and a journey settle between them: whether every prescription
 * can be priced, and which questionnaires are still outstanding.
 *
 * It exists because three collaborators have to answer those questions and
 * must never answer them differently. {@see \AsterMD\Storefront\Domain\FunnelRouter}
 * decides where a visitor belongs; {@see StepPreconditions} decides whether
 * they may enter the step they asked for; and
 * {@see \AsterMD\Storefront\Http\Controller\IntakeController} decides which
 * questionnaire the step it serves actually collects. When any two of them
 * disagree the funnel fails in one of two ways, and both have been observed:
 * either the visitor is *stranded* — every redirect lands on a step that
 * redirects them back, at a different URL each hop so no browser ever detects
 * the loop — or a step is left *unguarded*, which is how an eligibility
 * questionnaire gets silently skipped. A guard waiting on a form the page will
 * not serve is the same defect seen from the third side: the gate is right, the
 * page is right about a different form, and checkout is unreachable. Each class
 * used to carry its own copy of these rules, one of them annotated as mirroring
 * another, and the copies had drifted anyway. A docblock cannot hold three
 * implementations in step; having only one can.
 *
 * Pre-qualification is opt-in **twice**, and both declarations must come from
 * the *same* cart line (`[8.2]`): a product asks for the dedicated step with
 * `requires_prequalification` and names the form that step collects with
 * `prequalification_teleform_id`. Reading the two keys off different lines is
 * what produced the drift, and it manufactures a gate no product ever
 * declared. `[8.0f]` allows only one prescription per order, but free
 * attachments, OTC lines and accepted order bumps all sit in the same cart and
 * can all carry these keys — so "there is only ever one line to look at" is
 * not a rule this class may lean on.
 *
 * The catalog is reached through the {@see ProductCatalog} port rather than the
 * concrete provider, the same way every other rule in this codebase reaches it.
 */
final class FunnelRules
{
    public function __construct(private readonly ProductCatalog $catalog)
    {
    }

    /** `[12.7]`: an Rx line with no chosen variant cannot be priced or ordered, so no step past the cart can accept it. */
    public static function everyRxLineHasAPlan(Cart $cart): bool
    {
        foreach ($cart->lines() as $line) {
            if ($line->kind === 'rx' && ($line->variantId === null || $line->variantId === '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The dedicated eligibility questionnaires this cart calls for (`[8.2]`),
     * in cart order.
     *
     * Empty when no line opts in, and equally empty when a line opts in
     * without naming a form: wanting the step with nothing to ask leaves the
     * funnel nowhere to send anyone, so the step is skipped rather than
     * becoming a gate no visitor can pass.
     *
     * @return list<string>
     */
    public function prequalificationForms(Cart $cart): array
    {
        return $this->forms($cart, 'prequalification_teleform_id', requiresOptIn: true);
    }

    /**
     * The medical intake questionnaires this cart calls for (`[8.3]`), in cart
     * order. Naming a form is the whole of the opt-in here — there is no
     * separate flag, because a product that names an intake form is asking for
     * it by doing so.
     *
     * @return list<string>
     */
    public function intakeForms(Cart $cart): array
    {
        return $this->forms($cart, 'teleform_id', requiresOptIn: false);
    }

    /** The first eligibility questionnaire this journey has not finished, or null when none is outstanding. */
    public function outstandingPrequalificationForm(Cart $cart, ?JourneyState $state): ?string
    {
        return self::firstOutstanding($this->prequalificationForms($cart), $state);
    }

    /** The first intake questionnaire this journey has not finished, or null when none is outstanding. */
    public function outstandingIntakeForm(Cart $cart, ?JourneyState $state): ?string
    {
        return self::firstOutstanding($this->intakeForms($cart), $state);
    }

    /**
     * The questionnaire the dedicated eligibility step must put on screen, or
     * null when this cart and journey leave it nothing to ask (`[8.2]`).
     */
    public function prequalificationFormToCollect(Cart $cart, ?JourneyState $state): ?string
    {
        return self::formToCollect($this->prequalificationForms($cart), $state);
    }

    /**
     * The questionnaire the medical intake step must put on screen, or null
     * when this cart and journey leave it nothing to ask (`[8.3]`).
     */
    public function intakeFormToCollect(Cart $cart, ?JourneyState $state): ?string
    {
        return self::formToCollect($this->intakeForms($cart), $state);
    }

    /**
     * Whether $teleformId is one the dedicated eligibility step collects for
     * this cart, rather than the medical intake step.
     *
     * Asked by whoever holds a form id and needs the step it came from — the
     * terminal page, which owes a disqualified visitor the way back to the
     * questionnaire that stopped them. Answered from
     * {@see self::prequalificationForms()} so that a form named without the
     * opt-in is *not* attributed to a step no product asked for; routing "start
     * over" there names a step the routing decision will never send anyone to.
     */
    public function collectedAtPrequalification(string $teleformId, Cart $cart): bool
    {
        return in_array($teleformId, $this->prequalificationForms($cart), true);
    }

    /**
     * Every distinct form the cart's products declare under $field.
     *
     * A product the catalog does not know cannot declare anything, so an
     * unknown slug contributes nothing rather than blocking the funnel on a
     * form that does not exist. Duplicates are collapsed because two lines
     * naming the same questionnaire ask for it once.
     *
     * @return list<string>
     */
    private function forms(Cart $cart, string $field, bool $requiresOptIn): array
    {
        $forms = [];
        foreach ($cart->lines() as $line) {
            $product = $this->catalog->product($line->slug);
            if ($product === null) {
                continue;
            }

            if ($requiresOptIn && ($product['requires_prequalification'] ?? false) !== true) {
                continue;
            }

            $id = $product[$field] ?? null;
            if (is_string($id) && $id !== '' && !in_array($id, $forms, true)) {
                $forms[] = $id;
            }
        }

        return $forms;
    }

    /**
     * Which of $forms a step has to render.
     *
     * "What is still owed" and "what to draw" are the same answer in every
     * case but one, and that one is why this is a separate question rather
     * than a second copy of {@see self::firstOutstanding()}, which it defers
     * to: a standing hard stop outranks completion. `[10.43]` makes a
     * termination correctable — the terminal page invites the visitor back to
     * the questionnaire that stopped them, and a form that was already marked
     * complete when a later re-submission tripped a rule owes nothing, so
     * resolving purely by completion would leave that step with nothing to
     * show and forward the visitor straight back to the page telling them they
     * are stopped, with no way to change the answer.
     *
     * The gates are deliberately unmoved by this: both refuse a disqualified
     * journey outright, so re-opening the form does not re-open checkout.
     *
     * @param list<string> $forms
     */
    private static function formToCollect(array $forms, ?JourneyState $state): ?string
    {
        $stopped = $state !== null && $state->isDisqualified() ? $state->disqualifiedTeleform : null;
        if ($stopped !== null && in_array($stopped, $forms, true)) {
            return $stopped;
        }

        return self::firstOutstanding($forms, $state);
    }

    /**
     * The first form in $forms this journey has not completed.
     *
     * With no journey to ask, every named form counts as outstanding. That is
     * the honest answer rather than a cautious one: nothing here can tell a
     * form that was never started from one that was finished on a journey this
     * request could not load, and it is {@see StepPreconditions} — which knows
     * which gate is being asked about — that decides what to do with the
     * uncertainty.
     *
     * @param list<string> $forms
     */
    private static function firstOutstanding(array $forms, ?JourneyState $state): ?string
    {
        foreach ($forms as $id) {
            if ($state === null || !$state->formCompleted($id)) {
                return $id;
            }
        }

        return null;
    }
}
