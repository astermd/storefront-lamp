<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The storefront's view of the EMR's teleform resource, split in two because
 * the two halves fail differently and are cached differently: the metadata is
 * a cheap authenticated read that yields the cache key, and the definition is
 * a large document behind a short-lived signed URL.
 *
 * An interface for the same reason {@see \AsterMD\Storefront\Emr\CartGateway}
 * is one — a deployment with no EMR credentials binds a null implementation
 * and every caller above stays identical.
 */
interface TeleformGateway
{
    public function metadata(string $teleformId): ?TeleformMetadata;

    /** @return array<string, mixed>|null the decoded definition, or null when it could not be fetched */
    public function definition(TeleformMetadata $metadata): ?array;
}
