<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Theme;

use PHPUnit\Framework\TestCase;

/**
 * The committed build is one the site can actually serve.
 *
 * {@see \AsterMD\Storefront\Support\AssetManifest} tolerates a manifest it
 * cannot read by falling back to unhashed names, so a broken manifest does not
 * fail anything at runtime: every stylesheet and script 404s and the site
 * renders as bare HTML. Two branches that both rebuild produce two manifests,
 * and a merge that keeps the conflict markers in `manifest.json` is exactly
 * that broken manifest. Hence these checks run against the file on disk.
 *
 * The JavaScript hashes are recomputed here, since the build only copies those
 * files. The stylesheet's hash depends on Tailwind's output and cannot be
 * recomputed without Node; the CI workflow rebuilds and diffs to cover it.
 */
final class BuiltManifestTest extends TestCase
{
    public function testTheManifestIsAMapOfLogicalNamesToFiles(): void
    {
        $raw = (string) file_get_contents(self::buildDir() . '/manifest.json');

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail('public/assets/build/manifest.json is not valid JSON (' . $e->getMessage() . '); rerun `npm run build`');
        }

        self::assertIsArray($decoded);
        self::assertNotSame([], $decoded);
        foreach ($decoded as $logical => $hashed) {
            self::assertIsString($logical);
            self::assertIsString($hashed, $logical);
        }
    }

    public function testEveryFileTheManifestNamesIsCommittedBesideIt(): void
    {
        $missing = array_values(array_filter(
            self::manifest(),
            static fn (string $file): bool => !is_file(self::buildDir() . '/' . $file),
        ));

        self::assertSame([], $missing, 'named by the manifest but not built; rerun `npm run build`');
    }

    public function testNoBuiltFileIsLeftOverFromAnEarlierBuild(): void
    {
        $built = array_values(array_diff(
            scandir(self::buildDir()) ?: [],
            ['.', '..', '.gitkeep', 'manifest.json'],
        ));

        $stale = array_values(array_diff($built, array_values(self::manifest())));

        self::assertSame([], $stale, 'built files the manifest no longer names; rerun `npm run build`');
    }

    public function testEveryThemeScriptIsBuiltFromItsCurrentSource(): void
    {
        $manifest = self::manifest();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/theme/js', \FilesystemIterator::SKIP_DOTS),
        );

        $checked = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'js') {
                continue;
            }

            $name = $file->getBasename();
            $hash = substr(hash_file('sha256', $file->getPathname()) ?: '', 0, 8);

            self::assertSame(
                preg_replace('/\.js$/', '.' . $hash . '.js', $name),
                $manifest[$name] ?? null,
                "theme/js/{$name} changed since the last build; rerun `npm run build`",
            );
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'no theme scripts were found, so nothing was compared');
    }

    public function testEveryAssetATemplateNamesIsInTheManifest(): void
    {
        $manifest = self::manifest();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/theme/templates', \FilesystemIterator::SKIP_DOTS),
        );

        $named = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            // Literal names only; `asset('payment-' ~ provider ~ '.js')` is
            // resolved per provider and covered by ShippedAssetTest.
            preg_match_all('/asset\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $name) {
                $named[$name] = true;
            }
        }

        self::assertArrayHasKey('app.css', $named, 'the stylesheet is not loaded by any template, so this check would prove nothing');

        $unbuilt = array_values(array_diff(array_keys($named), array_keys($manifest)));
        sort($unbuilt);

        self::assertSame([], $unbuilt, 'templates load assets the build does not produce');
    }

    /** @return array<string, string> */
    private static function manifest(): array
    {
        $decoded = json_decode((string) file_get_contents(self::buildDir() . '/manifest.json'), true);
        self::assertIsArray($decoded, 'public/assets/build/manifest.json is not valid JSON; rerun `npm run build`');

        return $decoded;
    }

    private static function buildDir(): string
    {
        return self::root() . '/public/assets/build';
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
