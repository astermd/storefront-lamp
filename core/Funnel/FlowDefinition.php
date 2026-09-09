<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Funnel;

use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\Url;

/**
 * The funnel as data: which URL is which named step, and what that step
 * requires before a visitor may enter it (`[22.1]`, `[8.8]`), read once from
 * `config/funnel.php` via {@see self::fromConfig()} rather than re-derived by
 * whoever needs it.
 *
 * Every declared path is canonicalised at construction, and every path
 * handed to {@see self::stepForPath()} is canonicalised through the same
 * {@see Url::canonicalizePath()} the redirect middleware itself uses — so a
 * case-different or slash-less spelling of a guarded URL can never slip past
 * the guard by comparing unequal to what was declared.
 */
final class FlowDefinition
{
    /**
     * The step every failed routing decision falls back to.
     *
     * Named as a constant rather than hardcoded at the guard's call site so the
     * fallback is one declared fact: a flow that renames its entry step changes
     * this and nothing else.
     */
    public const string ENTRY_STEP = 'home';

    /** @var array<string, array{path: string, requires: list<string>}> */
    private readonly array $steps;

    /** @var array<string, string> canonical path => step name, built once so {@see self::stepForPath()} is a single lookup */
    private readonly array $stepsByPath;

    /** @param array<string, array{path: string, requires: list<string>}> $steps */
    public function __construct(array $steps)
    {
        $normalised = [];
        $byPath = [];
        foreach ($steps as $name => $definition) {
            $name = (string) $name;
            $path = Url::canonicalizePath((string) ($definition['path'] ?? ''));
            $requires = array_map('strval', (array) ($definition['requires'] ?? []));

            $normalised[$name] = ['path' => $path, 'requires' => $requires];
            $byPath[$path] = $name;
        }

        $this->steps = $normalised;
        $this->stepsByPath = $byPath;
    }

    public static function fromConfig(Config $config): self
    {
        return new self((array) $config->get('funnel.steps', []));
    }

    public function stepForPath(string $path): ?string
    {
        return $this->stepsByPath[Url::canonicalizePath($path)] ?? null;
    }

    /** @throws \InvalidArgumentException when $step names no configured step, because a redirect target that silently becomes '' is a redirect to nowhere */
    public function pathFor(string $step): string
    {
        if (!isset($this->steps[$step])) {
            throw new \InvalidArgumentException(sprintf('Unknown funnel step "%s".', $step));
        }

        return $this->steps[$step]['path'];
    }

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException when $step names no configured step
     */
    public function requirementsFor(string $step): array
    {
        if (!isset($this->steps[$step])) {
            throw new \InvalidArgumentException(sprintf('Unknown funnel step "%s".', $step));
        }

        return $this->steps[$step]['requires'];
    }

    /** @return array<string, array{path: string, requires: list<string>}> */
    public function steps(): array
    {
        return $this->steps;
    }
}
