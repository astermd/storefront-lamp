<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

/**
 * A second connection to a healthy SQLite file whose read of one checkout
 * attempt replays a snapshot taken earlier, while every other statement — and
 * every write — goes to the real database.
 *
 * This is how two simultaneous submits actually interleave, and there is no
 * other way to reproduce it inside one process. The second request selects the
 * attempt row *before* the first request's writes land, so its snapshot still
 * says "claimed, and old enough to take over". Everything it does from that
 * point is decided by a view of the row that is already out of date, which is
 * precisely the condition every takeover has to survive.
 *
 * A real connection to the same file rather than a stub, so the conditional
 * update the takeover depends on is executed by a live driver and its
 * `rowCount()` is the driver's own answer.
 */
final class FrozenAttemptReadPdo
{
    /**
     * @param array<string, mixed> $snapshot the attempt row as this connection will keep reporting it
     */
    public static function alongside(\PDO $healthy, array $snapshot): \PDO
    {
        $row = $healthy->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['file'] ?? null) || $row['file'] === '') {
            throw new \RuntimeException('Only a file-backed SQLite connection can be shadowed.');
        }

        $pdo = new class ('sqlite:' . $row['file'], null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]) extends \PDO {
            /** @var array<string, mixed> */
            public array $snapshot = [];

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                // Only the attempt read. The claim, the takeover and the sent
                // marker are all writes and all reach the real database, or
                // this would prove nothing about what the guard does.
                if (str_contains($query, 'SELECT idempotency_key')) {
                    return new class ($this->snapshot) extends \PDOStatement {
                        /** @param array<string, mixed> $row */
                        public function __construct(private readonly array $row)
                        {
                        }

                        public function execute(?array $params = null): bool
                        {
                            return true;
                        }

                        public function fetch(
                            int $mode = \PDO::FETCH_DEFAULT,
                            int $cursor = \PDO::FETCH_ORI_NEXT,
                            int $offset = 0,
                        ): mixed {
                            return $this->row;
                        }
                    };
                }

                return parent::prepare($query, $options);
            }
        };

        $pdo->snapshot = $snapshot;

        return $pdo;
    }
}
