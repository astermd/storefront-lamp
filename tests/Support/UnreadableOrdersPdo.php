<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

/**
 * A second connection to a healthy SQLite file that refuses exactly one read:
 * looking an order up by its provider reference.
 *
 * The narrowest simulation of "the row is there and we cannot see it", which is
 * a different fact from "the row is not there" and has to be told apart from it.
 * An absent row means a post-charge write was lost — the journey is known, the
 * receipt is thin, and completion should finish. An unreadable table means
 * nothing about the journey is known, and reporting it anyway told the EMR that
 * a charged order had declined.
 *
 * Taking the whole connection down instead would be a weaker case: the journey
 * itself would fail to load and completion would never reach the read.
 *
 * A real connection rather than a stub, for the reason
 * {@see DeadAfterChargePdo} gives — {@see \AsterMD\Storefront\Repository\OrderRepository}
 * is `final`, so the seam has to be the driver, and a stubbed repository would
 * prove nothing about what a live one does when its own SQL raises.
 */
final class UnreadableOrdersPdo
{
    /** A connection to the same database as $healthy, with the by-reference order read refused. */
    public static function alongside(\PDO $healthy): \PDO
    {
        $row = $healthy->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['file'] ?? null) || $row['file'] === '') {
            throw new \RuntimeException('Only a file-backed SQLite connection can be shadowed.');
        }

        return new class ('sqlite:' . $row['file'], null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]) extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                // Narrow on purpose: the lookup by provider reference and
                // nothing else, so a test using this still writes orders,
                // sessions and events normally.
                if (str_contains($query, 'FROM orders') && str_contains($query, 'provider_reference = ?')) {
                    throw new \PDOException('SQLSTATE[HY000]: general error: database is locked');
                }

                return parent::prepare($query, $options);
            }
        };
    }
}
