<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Repository;

use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The rule that keeps a catalog slug off the durable trail, asked of the sink
 * rather than of any one caller.
 *
 * A slug in this catalog is the drug and its strength —
 * `6a8a85-tirzepatide-10mg-ml` — and `events` is keyed by `session_uuid` and
 * outlives the request, so a slug written here is a durable link from an
 * identifiable journey to a prescription (`[20.14]`). Three unrelated event
 * families were writing one, which is why the rule lives where every payload
 * passes rather than at the three call sites that would each have to remember.
 *
 * The operator log deliberately does *not* share this rule; see
 * {@see EventRepository::productReferences()} for why the two sinks differ.
 */
final class EventProductReferenceTest extends TestCase
{
    use TempDatabase;

    private const string SESSION = 'sess-1234567890abcdef';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->tempPdo();
        (new SessionRepository(fn (): \PDO => $this->pdo))->insert(self::SESSION, [], null);
    }

    public function testASlugNeverReachesTheDurableTable(): void
    {
        $this->append(['slug' => '6a8a85-tirzepatide-10mg-ml']);

        self::assertStringNotContainsStringIgnoringCase('tirzepatide', $this->stored());
        self::assertStringNotContainsString('slug', $this->stored());
    }

    /**
     * The row still identifies a product, because one that identifies nothing
     * cannot answer the question it was written for. What replaces the slug is
     * a reference to it: opaque on its face, and resolvable only by joining
     * the catalog — the same standing the EMR's own product id has, which is
     * the identifier §18's payload settles on.
     */
    public function testTheSlugIsReplacedByAReferenceUnderAKeyThatSaysSo(): void
    {
        $this->append(['slug' => '6a8a85-tirzepatide-10mg-ml', 'qty' => 2]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->stored(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('product_ref', $payload);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $payload['product_ref']);
        self::assertSame(2, $payload['qty'], 'everything that is not a slug is left alone');
    }

    public function testTheSameProductAlwaysYieldsTheSameReference(): void
    {
        $this->append(['slug' => '6a8a87-nad-1000mg']);
        $first = $this->stored();

        $this->pdo->exec('DELETE FROM events');
        $this->append(['slug' => '6a8a87-nad-1000mg']);

        self::assertSame($first, $this->stored());
    }

    public function testDifferentProductsYieldDifferentReferences(): void
    {
        // Two strengths of one drug are two products, and telling them apart is
        // half of what an operator asks this trail.
        $this->append(['slug' => '6a8a85-tirzepatide-10mg-ml']);
        $first = $this->stored();

        $this->pdo->exec('DELETE FROM events');
        $this->append(['slug' => '6a8a54-tirzepatide-5mg-ml']);

        self::assertNotSame($first, $this->stored());
    }

    /**
     * The cart trail nests one entry per line, so a rule that only looked at
     * the top level would leave the whole of `cart.created` untouched.
     */
    public function testNestedSlugsAreReferencedToo(): void
    {
        $this->append(['lines' => [
            ['slug' => '6a8a85-tirzepatide-10mg-ml', 'qty' => 1],
            ['slug' => '6a8a88-syringe', 'qty' => 2],
        ]]);

        /** @var array{lines: list<array<string, mixed>>} $payload */
        $payload = json_decode($this->stored(), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsStringIgnoringCase('tirzepatide', $this->stored());
        self::assertCount(2, $payload['lines']);
        self::assertNotSame($payload['lines'][0]['product_ref'], $payload['lines'][1]['product_ref']);
    }

    /**
     * A value that is not a slug cannot be referenced as one, and casting it
     * would put a number where every other row holds a digest. The absence is
     * recorded literally instead (`[20.8]`), and the key it would have arrived
     * under still does not survive.
     */
    public function testAValueThatIsNotASlugIsRecordedAsAbsentRatherThanCast(): void
    {
        $this->append(['slug' => null, 'step' => 'checkout']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($this->stored(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('slug', $payload);
        self::assertNull($payload['product_ref']);
        self::assertSame('checkout', $payload['step']);
    }

    /**
     * The operator log is the other reader of the shared redaction list, and it
     * is not bound by this rule: `[20.6]`'s log is short-lived, is scoped to
     * the operator debugging an outage, and a slug is the one thing that makes
     * `cart.mirror_failed` actionable. A log that blanks what is useful teaches
     * the operator to stop reading it.
     */
    public function testTheOperatorLogStillKeepsSlugsReadable(): void
    {
        $logFile = sys_get_temp_dir() . '/event-product-ref-' . bin2hex(random_bytes(6)) . '.log';

        try {
            (new OperatorLog($logFile))->warning('cart.mirror_failed', ['slug' => '6a8a85-tirzepatide-10mg-ml']);

            self::assertStringContainsString('6a8a85-tirzepatide-10mg-ml', (string) file_get_contents($logFile));
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function append(array $payload): void
    {
        (new EventRepository(fn (): \PDO => $this->pdo))->append(self::SESSION, 'cart.created', $payload);
    }

    private function stored(): string
    {
        return (string) $this->pdo->query('SELECT payload FROM events ORDER BY id DESC LIMIT 1')->fetchColumn();
    }
}
