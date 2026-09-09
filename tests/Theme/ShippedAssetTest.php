<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Theme;

use PHPUnit\Framework\TestCase;

/**
 * Every script the build ships is one a visitor can actually reach.
 *
 * `build/build-assets.mjs` globs `theme/js` and hashes whatever it finds, so a
 * file nobody deleted is a file still being compiled, hashed, listed in the
 * manifest and served. Three of them were: the hardcoded questionnaires the
 * form engine replaced, still shipping months after the two templates that
 * loaded them stopped being routed to.
 *
 * Nothing catches that by grepping for the filenames, because the dead scripts
 * *were* referenced — by templates that were themselves dead. So this walks the
 * other way: from the template names the application itself names, through
 * every `extends`/`include`/`embed` from there, and asks which scripts that
 * reachable set asks for. A script no reachable template loads is one no
 * visitor can run, whatever references it on disk.
 */
final class ShippedAssetTest extends TestCase
{
    public function testEveryScriptTheBuildShipsIsLoadedBySomeReachableTemplate(): void
    {
        $unreferenced = array_values(array_diff(self::shippedScripts(), self::scriptsReachableTemplatesLoad()));

        self::assertSame(
            [],
            $unreferenced,
            'compiled and served, but no template a visitor can reach loads them: ' . implode(', ', $unreferenced),
        );
    }

    /** Every `.js` under `theme/js`, by basename — which is how the build names them in the manifest. */
    private static function shippedScripts(): array
    {
        $scripts = [];

        foreach (self::filesUnder(self::root() . '/theme/js', '.js') as $path) {
            $scripts[] = basename($path);
        }

        sort($scripts);

        return $scripts;
    }

    /** @return list<string> the `asset()` arguments naming a script, across every reachable template */
    private static function scriptsReachableTemplatesLoad(): array
    {
        $loaded = [];

        foreach (self::reachableTemplates() as $template) {
            preg_match_all('/asset\(\s*[\'"]([^\'"]+\.js)[\'"]/', self::templateSource($template), $matches);

            foreach ($matches[1] as $name) {
                $loaded[basename($name)] = true;
            }
        }

        return array_keys($loaded);
    }

    /**
     * The templates the application can render, walked from the names it holds.
     *
     * The roots are every `*.twig` literal in `core/` and `config/`, which is
     * where a controller or a route argument names a page. From there the walk
     * follows the static tag forms only. One include is computed —
     * `partials/intake/field.twig` dispatches on a field type — so the whole of
     * `partials/intake/` is seeded as reachable too, and the mapping that picks
     * one of them is covered by {@see \AsterMD\Storefront\Tests\Forms\FieldViewModelTest}.
     *
     * @return list<string> template paths relative to `theme/templates`
     */
    private static function reachableTemplates(): array
    {
        $queue = [...self::templateNamesInSource(), ...self::intakeFieldPartials()];
        $seen = [];

        while ($queue !== []) {
            $template = array_shift($queue);

            if (isset($seen[$template])) {
                continue;
            }

            $seen[$template] = true;
            // A name that resolves to nothing is left alone rather than
            // failing here: this walk is about which scripts ship, and a
            // template literal that names no file is a different defect with
            // its own tests.
            $source = self::templateSource($template);

            preg_match_all(
                '/(?:extends|include|embed|import|from)\s*\(?\s*[\'"]([A-Za-z0-9_\-\/]+\.twig)[\'"]/',
                $source,
                $matches,
            );

            foreach ($matches[1] as $referenced) {
                $queue[] = $referenced;
            }
        }

        return array_keys($seen);
    }

    /** @return list<string> */
    private static function templateNamesInSource(): array
    {
        $names = [];

        foreach ([self::root() . '/core', self::root() . '/config'] as $directory) {
            foreach (self::filesUnder($directory, '.php') as $path) {
                preg_match_all('/[\'"]([A-Za-z0-9_\-\/]+\.twig)[\'"]/', (string) file_get_contents($path), $matches);

                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /** @return list<string> */
    private static function intakeFieldPartials(): array
    {
        $partials = [];

        foreach (self::filesUnder(self::root() . '/theme/templates/partials/intake', '.twig') as $path) {
            $partials[] = 'partials/intake/' . basename($path);
        }

        return $partials;
    }

    private static function templateSource(string $template): string
    {
        $path = self::root() . '/theme/templates/' . $template;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** @return list<string> */
    private static function filesUnder(string $directory, string $suffix): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
