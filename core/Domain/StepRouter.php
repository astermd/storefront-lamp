<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

use AsterMD\Storefront\Journey\JourneyState;

/**
 * The single question the step guard asks the routing decision.
 *
 * Declared as a port so the guard's own branching can be tested against a
 * router that names any step, including ones the shipped table would never
 * produce -- which is the only way to cover the self-naming branch that must
 * fail closed.
 */
interface StepRouter
{
    public function nextStep(Cart $cart, ?JourneyState $state = null): string;
}
