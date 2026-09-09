<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * Thrown when a definition can be neither fetched nor served from cache.
 *
 * It exists so the controller can render an explicit "form unavailable" page
 * `[10.3]` rather than an empty or half-drawn form. Deliberately not a
 * swallowed failure: an intake step with no questions looks like a working
 * page and would collect nothing, which is worse than a stated outage.
 */
final class FormUnavailable extends \RuntimeException
{
}
