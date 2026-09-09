<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Emr;

/**
 * The three outcomes of one mirror call, kept distinct because the caller
 * treats them differently: `NotFound` is the only one that is recoverable
 * on the spot (the remote cart is gone, so create it), while `Failed` is
 * logged and swallowed (`[7.12]`).
 */
enum CartMirrorResult
{
    case Ok;
    case NotFound;
    case Failed;
}
