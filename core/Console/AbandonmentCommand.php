<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Console;

use AsterMD\Storefront\Support\FailureDigest;

use AsterMD\Storefront\Abandonment\AbandonmentSignal;
use AsterMD\Storefront\Abandonment\AbandonmentSweep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `abandonment:signals` — the current set of `[21.10]` abandonment signals, as
 * one JSON document on stdout.
 *
 * **This is `[21.11a]`'s "exposed for polling" half.** The rule requires the
 * signal to be retrievable by an external system rather than merely logged,
 * and allows either a push on transition or an exposure to poll. Polling is
 * what this deployment can honestly offer: there is no outbound push
 * infrastructure and no inbound authentication to put an endpoint behind, and
 * inventing either would be a larger and less reviewable surface than a
 * command an operator schedules. The consuming channel is email automation, so
 * the expected shape is a cron entry piping this into the platform's importer.
 *
 * **Read-only, `[20.1]`.** It classifies and prints; it writes nothing, to the
 * database or anywhere else. Running it cannot disturb a storefront serving
 * traffic, and running it twice produces the same document — see
 * {@see AbandonmentSignal::signalKey()} for how a consumer deduplicates
 * across polls without this command having to remember anything.
 *
 * **What the document is.** `{count, generated_at, signals: [...]}` —
 * `count` and `generated_at` so a consumer can tell "no abandonments" from "the
 * poll never ran", which an unwrapped array cannot express. A failed read
 * exits non-zero and prints no document at all, for the same reason: a
 * consumer must never read a database outage as "nobody abandoned anything".
 *
 * So, for a scheduler: `SUCCESS` (0) means **a document was produced and it is
 * the whole current signal set**, including the empty one — `count: 0` is a
 * measurement and is printed on stdout as usual. `FAILURE` (1) means the sweep
 * could not read `sessions`; stdout is then empty, so a pipeline that only
 * consumes stdout imports nothing rather than importing an all-clear. The
 * failure line goes to stderr and carries the exception class, never the
 * driver's message — see {@see self::execute()}. There is no partial state:
 * the sweep is one query, so it either read its window or it did not.
 *
 * **Handle the output as sensitive.** Every signal carries a resume link, and
 * until `[21.4]` is closed that link is a long-lived bearer credential for a
 * journey's intake answers. So it must not be redirected into a durable log,
 * a shell history, a CI artefact, or anything else that outlives the journey.
 * The document deliberately carries no clinical answer and no buyer contact
 * detail; the link is the part that needs care.
 */
#[AsCommand(
    name: 'abandonment:signals',
    description: 'Emit the current set of abandoned-journey signals as JSON, for an external system to poll',
)]
final class AbandonmentCommand extends Command
{
    public function __construct(private readonly AbandonmentSweep $sweep)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $signals = $this->sweep->signals();
        } catch (\Throwable $error) {
            // Deliberately stderr, where the rest of this repository's
            // commands write their failures to stdout: this one's stdout is a
            // machine-readable document being piped somewhere, and an error
            // line mixed into it would make a JSON parser fail on the
            // *reporting* rather than on the outage.
            //
            // The class and the SQLSTATE, never the message. That the failing
            // query binds only two timestamps and a row limit says what this
            // code sends, not what the driver sends back: the rows it reads
            // are `sessions.journey_state`, which holds clinical answers and
            // buyer contact details, and MySQL's 1366 quotes the value it
            // could not handle. Neither of
            // {@see \AsterMD\Storefront\Support\OperatorLog}'s defences
            // reaches a cron job's stderr in any case -- redaction is
            // key-based and cannot see inside a string, and
            // {@see \AsterMD\Storefront\Support\CardScrubber} masks by Luhn
            // check -- and this stream ends up in a mail spool, a CI artefact
            // or a shell history. Same policy
            // {@see \AsterMD\Storefront\Checkout\EmrCheckoutEventReporter}
            // states for foreign free text (`[20.6]`).
            $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stream->writeln('<error>Abandonment sweep failed: ' . FailureDigest::describe($error) . '</error>');

            return Command::FAILURE;
        }

        $output->writeln((string) json_encode([
            'count' => count($signals),
            'generated_at' => gmdate('c'),
            'signals' => array_map(
                static fn (AbandonmentSignal $signal): array => $signal->toArray(),
                $signals,
            ),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

}
