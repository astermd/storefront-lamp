<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Codebase;

use PHPUnit\Framework\TestCase;

/**
 * No file carries an unresolved merge conflict.
 *
 * A conflict left in PHP is at least a parse error somewhere. Left in JSON,
 * Twig, CSS or Markdown it fails nothing: a conflicted
 * `public/assets/build/manifest.json` reads as an empty manifest and the site
 * ships unstyled, and a conflicted template renders the markers as text. So
 * every file that would be committed is read, whatever its type.
 *
 * Only the opening and closing markers are matched. Git always writes both,
 * and the `=======` separator alone is also a Markdown heading underline.
 */
final class ConflictMarkerTest extends TestCase
{
    /** Directories never read without git: dependencies, runtime state and editor files. */
    private const array SKIPPED = [
        '.git', 'vendor', 'node_modules', 'storage', '.phpunit.cache', '.idea', '.vscode',
    ];

    public function testNoFileCarriesAConflictMarker(): void
    {
        $files = self::files();

        self::assertContains('public/assets/build/manifest.json', $files, 'the file list is incomplete, so a clean result would prove nothing');
        self::assertContains('tests/Codebase/ConflictMarkerTest.php', $files, 'the file list is incomplete, so a clean result would prove nothing');

        $conflicted = [];
        foreach ($files as $relative) {
            $line = self::firstMarkerLine(self::root() . '/' . $relative);
            if ($line !== null) {
                $conflicted[] = "{$relative}:{$line}";
            }
        }

        self::assertSame([], $conflicted, 'unresolved merge conflicts');
    }

    public function testTheDetectorRecognisesAConflict(): void
    {
        // Built rather than written out, so this file never matches itself.
        $conflict = "{\n" . str_repeat('<', 7) . " HEAD\n  \"a\": 1\n" . str_repeat('=', 7)
            . "\n  \"a\": 2\n" . str_repeat('>', 7) . " 0123abc\n}\n";
        $path = tempnam(sys_get_temp_dir(), 'conflict-');
        file_put_contents($path, $conflict);

        try {
            self::assertSame(2, self::firstMarkerLine($path));
        } finally {
            unlink($path);
        }
    }

    /** @return int|null the 1-based line of the first marker, or null for none */
    private static function firstMarkerLine(string $path): ?int
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);
        if (str_contains(substr($contents, 0, 8000), "\0")) {
            return null;
        }

        if (preg_match('/^(?:<{7}|>{7})(?: |$)/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr_count($contents, "\n", 0, $match[0][1]) + 1;
    }

    /**
     * Every file git would commit: tracked, plus untracked but not ignored.
     * Without git (an unpacked release) the tree is walked instead.
     *
     * @return list<string> paths relative to the repository root
     */
    private static function files(): array
    {
        $root = self::root();

        if (is_dir($root . '/.git')) {
            exec('git -C ' . escapeshellarg($root) . ' ls-files -z --cached --others --exclude-standard 2>/dev/null', $out, $status);
            if ($status === 0 && $out !== []) {
                return array_values(array_filter(explode("\0", implode("\n", $out)), static fn (string $p): bool => $p !== ''));
            }
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), self::SKIPPED, true)),
            ),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
