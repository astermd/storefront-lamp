<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

/**
 * A second connection to a healthy SQLite file that refuses exactly one
 * statement: the write that completes a checkout attempt.
 *
 * That statement is the only database write the submit path makes *after* the
 * payment provider has been charged, so refusing it is the narrowest possible
 * simulation of "the provider took the money and the database then went away".
 * Taking the whole connection down instead would be a different and much
 * weaker case: the idempotency claim would fail first and checkout would
 * refuse before the card was ever presented, which is the behaviour that
 * already has its own coverage.
 *
 * A real connection rather than a stub, because what is under test is the
 * repository's own SQL and a stub of it would prove nothing about what a live
 * driver does when it raises mid-request.
 */
final class DeadAfterChargePdo
{
    /** A connection to the same database as $healthy, with the completing write refused. */
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
                // `outcome` is written by nothing else in the schema, so this
                // cannot catch the claim or the sent marker -- both of which
                // happen before the charge and have to succeed for this case
                // to mean anything.
                if (str_contains($query, 'outcome = ?')) {
                    throw new \PDOException('SQLSTATE[HY000]: General error: the database went away after the charge');
                }

                return parent::prepare($query, $options);
            }
        };
    }
}
