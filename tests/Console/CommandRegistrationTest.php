<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Console;

use PHPUnit\Framework\TestCase;

/**
 * Every command that exists is reachable from the console.
 *
 * A command class with tests and no registration passes every test it has and
 * cannot be run by anybody. That is not hypothetical: four of these — the two
 * reconciliation sweeps, the abandonment feed and the pruner — were built,
 * tested and left unregistered, so the reconciliation job `[21.8]` calls for
 * existed and an operator had no way to invoke it.
 *
 * Asserted against `bin/console`'s source rather than by booting the
 * application, because the failure being guarded is precisely that the file
 * does not mention the class. Booting would only prove the commands it already
 * knows about work.
 */
final class CommandRegistrationTest extends TestCase
{
    private const string CONSOLE = __DIR__ . '/../../bin/console';

    private const string COMMAND_DIR = __DIR__ . '/../../core/Console';

    /** @return list<string> */
    private static function commandClasses(): array
    {
        $classes = [];

        foreach ((array) glob(self::COMMAND_DIR . '/*Command.php') as $file) {
            $classes[] = basename((string) $file, '.php');
        }

        sort($classes);

        return $classes;
    }

    public function testEveryCommandClassIsRegisteredInTheConsoleEntryPoint(): void
    {
        $console = (string) file_get_contents(self::CONSOLE);

        $unregistered = array_values(array_filter(
            self::commandClasses(),
            static fn (string $class): bool => !str_contains($console, 'new ' . $class . '('),
        ));

        self::assertSame(
            [],
            $unregistered,
            'these command classes exist but nothing in bin/console constructs them: ' . implode(', ', $unregistered),
        );
    }

    /**
     * The absence that keeps the test above meaningful.
     *
     * A glob that matched nothing would make it pass on an empty repository,
     * and the count is asserted loosely rather than exactly so that adding a
     * command does not require editing this file — only failing to register
     * one does.
     */
    public function testTheCommandDirectoryIsActuallyBeingRead(): void
    {
        self::assertGreaterThanOrEqual(13, count(self::commandClasses()));
    }

    /**
     * Each registered command answers to a distinct name.
     *
     * Two commands sharing a name is silent: Symfony keeps the last one
     * registered and the other becomes unreachable, which is the same failure
     * this file exists for wearing a different hat. The names come from the
     * `#[AsCommand]` attributes rather than from a list here, so a rename
     * cannot drift away from what is asserted.
     */
    public function testNoTwoCommandsClaimTheSameName(): void
    {
        $names = [];

        foreach (self::commandClasses() as $class) {
            $source = (string) file_get_contents(self::COMMAND_DIR . '/' . $class . '.php');

            if (preg_match("/name:\s*'([^']+)'/", $source, $match) === 1) {
                $names[] = $match[1];
            }
        }

        self::assertSame(array_unique($names), $names, 'two commands declare the same name');
    }
}
