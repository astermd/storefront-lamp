<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Http\Middleware;

use AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Journey\JourneyWriteFailed;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Tests\Support\TempDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * What a failed journey write is allowed to say.
 *
 * The middleware writes clinical answers, buyer contact details and the
 * provider's credential handle to one JSON column. When that write fails, the
 * database driver is under no obligation to describe the failure without
 * quoting what it refused — MySQL's 1366 does exactly that, naming the column
 * and the value bytes it could not store.
 *
 * That message then reaches {@see OperatorLog} under a key nothing redacts.
 * The sink's two defences are both blind to it: redaction is key-based and so
 * cannot see inside a string, and {@see \AsterMD\Storefront\Support\CardScrubber}
 * masks by Luhn check, which is a card number and nothing else. Measured
 * against the real scrubber, a diagnosis, an email address, a phone number and
 * both halves of the charge authority all survive it intact.
 *
 * So the containment has to be that the message is never logged at all
 * (`[20.6]`, `[22.21]`) — which is the policy
 * {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter} already states
 * for foreign free text: the exception class and its status answer every
 * question an outage actually raises, and cannot carry text somebody else
 * wrote about our data.
 */
final class JourneyStateMiddlewareTest extends TestCase
{
    use TempDatabase;

    /**
     * A driver message shaped like MySQL 1366, quoting the journey blob back.
     *
     * The needles are the four kinds of thing that column really holds, chosen
     * so that none of them can pass by luck: the diagnosis is a nonsense
     * string that appears nowhere else, and the card number is Luhn-valid so
     * that the one defence which does work is genuinely exercised rather than
     * skipped.
     */
    private const string DIAGNOSIS = 'Pheochromocytoma-ZQX';

    private const string EMAIL = 'dana.abernathy@example.test';

    private const string PHONE = '4155550142';

    private const string CUSTOMER_ID = '13957';

    private const string CARD_ID = '16764';

    private const string PAN = '4111111100084444';

    private function driverMessage(): string
    {
        return sprintf(
            'SQLSTATE[HY000]: General error: 1366 Incorrect string value for column `journey_state` at row 1: '
            . '{"form_answers":{"t1":{"diagnosis":"%s"}},"buyer":{"email":"%s","phone":"%s"},'
            . '"payment_handle":{"handle":{"customer_id":"%s","customer_card_id":"%s"}},"pan":"%s"}',
            self::DIAGNOSIS,
            self::EMAIL,
            self::PHONE,
            self::CUSTOMER_ID,
            self::CARD_ID,
            self::PAN,
        );
    }

    /**
     * A PDOException shaped the way the driver really raises one.
     *
     * `errorInfo` is set explicitly because PHP only populates it when PDO
     * itself raises the exception; constructing one by hand leaves it null,
     * and a test that skipped this would assert the fallback path while
     * claiming to assert the driver path.
     */
    private function driverFailure(): \PDOException
    {
        $failure = new \PDOException($this->driverMessage());
        $failure->errorInfo = ['HY000', 1366, $this->driverMessage()];

        return $failure;
    }

    private function logFile(): string
    {
        $file = sys_get_temp_dir() . '/journey-mw-' . bin2hex(random_bytes(6)) . '.log';

        register_shutdown_function(static function () use ($file): void {
            if (is_file($file)) {
                unlink($file);
            }
        });

        return $file;
    }

    /**
     * Runs the middleware with a store whose flush throws the given exception,
     * and returns everything written to the operator log.
     */
    private function logAfterFailedFlush(\Throwable $failure): string
    {
        $file = $this->logFile();
        $log = new OperatorLog($file);

        $middleware = new JourneyStateMiddleware(
            static function () use ($failure): JourneyStore {
                throw $failure;
            },
            $log,
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
            $handler,
        );

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * The assertion that matters: none of what the driver quoted survives.
     *
     * Written as one test over all six needles rather than six tests, because
     * the property being asserted is "nothing from the message", and a per-
     * needle split invites the next person to add a needle and not the test.
     */
    public function testADriverMessageQuotingTheJourneyBlobReachesTheLogInNoPart(): void
    {
        $written = $this->logAfterFailedFlush($this->driverFailure());

        self::assertStringNotContainsString(self::DIAGNOSIS, $written, 'a clinical answer reached the operator log');
        self::assertStringNotContainsString(self::EMAIL, $written, 'the buyer email reached the operator log');
        self::assertStringNotContainsString(self::PHONE, $written, 'the buyer phone reached the operator log');
        self::assertStringNotContainsString(self::CUSTOMER_ID, $written, 'the charge authority reached the operator log');
        self::assertStringNotContainsString(self::CARD_ID, $written, 'the charge authority reached the operator log');
        self::assertStringNotContainsString(self::PAN, $written, 'a card number reached the operator log');
    }

    /**
     * Containment that logs nothing would pass the test above and be useless.
     *
     * An operator still has to be able to tell a failed journey write from a
     * silent one, and to know which failure it was.
     */
    public function testTheFailureIsStillReportedByClassAndSqlState(): void
    {
        $written = $this->logAfterFailedFlush($this->driverFailure());

        self::assertStringContainsString('journey.save_failed', $written);
        self::assertStringContainsString('PDOException', $written);
        self::assertStringContainsString('HY000', $written, 'the SQLSTATE is what names the failure');
    }

    /**
     * The distinction the containment rests on, asserted in one place.
     *
     * A message this application composed is reported in full; a message the
     * driver composed is not reported at all. Both exceptions are thrown from
     * the same place, caught by the same block, and logged under the same
     * event -- so nothing but the type tells them apart, and if that type
     * check were ever removed both tests below would still pass individually
     * while the pair would not.
     */
    public function testOurOwnMessageIsReportedAndTheDriversIsNot(): void
    {
        $ours = $this->logAfterFailedFlush(
            new JourneyWriteFailed('Journey row for session sess-abc no longer exists.'),
        );
        $theirs = $this->logAfterFailedFlush($this->driverFailure());

        self::assertStringContainsString('no longer exists', $ours, 'a message we composed is safe and useful');
        self::assertStringNotContainsString('Incorrect string value', $theirs, 'a message the driver composed is not');
        self::assertStringNotContainsString('reason', $theirs, 'and it is absent rather than emptied');
    }

    /**
     * An exception carrying no SQLSTATE is still reported, and does not invent one.
     */
    public function testAFailureWithNoSqlStateIsReportedWithoutInventingOne(): void
    {
        $written = $this->logAfterFailedFlush(new \RuntimeException('the store could not be built'));

        self::assertStringContainsString('journey.save_failed', $written);
        self::assertStringContainsString('RuntimeException', $written);
        self::assertStringNotContainsString('the store could not be built', $written);
    }

    /**
     * The response is still returned, and the visitor never learns any of this.
     *
     * `[20.1]`: a lost analytics write must not become the visitor's problem.
     */
    public function testTheVisitorStillGetsTheirResponseWhenTheWriteFails(): void
    {
        $log = new OperatorLog($this->logFile());

        $middleware = new JourneyStateMiddleware(
            static function (): JourneyStore {
                throw new \PDOException('SQLSTATE[HY000]: General error: 1366');
            },
            $log,
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write('the page');

                return $response;
            }
        };

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('the page', (string) $response->getBody());
    }

    /**
     * The sibling middleware that reaches the same column, held to the same rule.
     *
     * Session resolution ends in an INSERT of the whole journey blob, so a
     * driver refusing it may quote back exactly what the save-back path may
     * not log. Fixing one and not the other would leave the same bytes
     * reachable through a different line, which is how this was found: the
     * rule was written for one middleware and the review asked who else
     * touches the column.
     */
    public function testTheAttributionMiddlewareIsHeldToTheSameRule(): void
    {
        $file = $this->logFile();
        $log = new OperatorLog($file);

        $middleware = new \AsterMD\Storefront\Http\Middleware\AttributionMiddleware(
            static function (): \AsterMD\Storefront\Journey\SessionResolver {
                throw new \PDOException(
                    'SQLSTATE[HY000]: 1366 Incorrect string value for column `journey_state`: '
                    . '{"diagnosis":"' . self::DIAGNOSIS . '","email":"' . self::EMAIL . '"}',
                );
            },
            new \AsterMD\Storefront\Journey\SessionOptions(),
            $log,
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
            $handler,
        );

        $written = is_file($file) ? (string) file_get_contents($file) : '';

        self::assertStringContainsString('session.resolve_failed', $written, 'the outage must still be reported');
        self::assertStringContainsString('PDOException', $written);
        self::assertStringNotContainsString(self::DIAGNOSIS, $written, 'a clinical answer reached the operator log');
        self::assertStringNotContainsString(self::EMAIL, $written, 'the buyer email reached the operator log');
    }

    /**
     * A successful flush logs nothing at all.
     *
     * The absence that keeps the line above meaningful: an operator who sees
     * `journey.save_failed` has to be able to trust that something failed.
     */
    public function testASuccessfulFlushWritesNoOperatorLine(): void
    {
        $file = $this->logFile();
        $pdo = $this->tempPdo();

        $middleware = new JourneyStateMiddleware(
            fn (): JourneyStore => new JourneyStore(new SessionRepository(static fn (): \PDO => $pdo)),
            new OperatorLog($file),
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/'),
            $handler,
        );

        self::assertStringNotContainsString('journey.save_failed', is_file($file) ? (string) file_get_contents($file) : '');
    }
}
