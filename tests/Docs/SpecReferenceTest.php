<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use SplFileInfo;

/**
 * The source cites behaviour rules by id -- `[22.16]` in a docblock, in a
 * template annotation, in a test name. `docs/SPEC-REFERENCE.md` is what those
 * ids resolve to, and it is the only thing in the repository that resolves
 * them.
 *
 * A citation nothing resolves is worse than no citation at all: it reads as a
 * pointer to a decision and delivers nothing. So the reference is checked
 * against the code rather than trusted, and it is the reference that is read
 * here -- never a source it may once have been derived from, which would move
 * the guarantee somewhere a reader of this repository cannot go.
 */
final class SpecReferenceTest extends TestCase
{
    /**
     * Paths whose contents may carry a citation. A directory is walked
     * recursively; a file is read on its own.
     *
     * The prose documents are scanned as named files rather than by listing
     * `docs` as a directory. A citation in a document is a pointer a reader
     * follows exactly as they would one in a docblock, and cross-references
     * between the reference's own entries are the easiest kind to leave
     * dangling -- they resolve while the contract document is to hand and
     * stop resolving the moment it is not. `deploy` is already a directory
     * here, so `deploy/README.md` comes along with it.
     */
    private const array SCANNED = [
        'core',
        'tests',
        'theme',
        'config',
        'bin',
        'database',
        'deploy',
        'public',
        'README.md',
        'CLAUDE.md',
        'docs/ARCHITECTURE.md',
        'docs/OPERATIONS.md',
        'docs/INTEGRATION-NOTES.md',
        'docs/SPEC-REFERENCE.md',
    ];

    /**
     * A bracketed rule id. A Tailwind arbitrary value has exactly the same
     * shape -- `leading-[1.15]`, `text-[1.25rem]` -- but always follows the
     * utility it modifies, so a word character or a dash immediately before
     * the bracket disqualifies the match. Without that guard this test fails
     * forever on stylesheet classes that cite nothing.
     */
    private const string CITATION = '/(?<![-\w])\[([0-9]+\.[0-9]+[a-z]*)\]/';

    /** One resolved entry in the reference. */
    private const string ENTRY = '/^- \*\*`\[([0-9]+\.[0-9]+[a-z]*)\]`\*\* — (\S.*)$/mu';

    public function testEveryCitedRuleIdResolvesToAnEntryInTheReference(): void
    {
        $resolved = $this->resolvedIds();
        $cited = $this->citations();

        self::assertNotEmpty($cited, 'No citations were found at all, which means the scan is broken.');

        $unresolved = array_diff_key($cited, $resolved);
        self::assertSame([], $unresolved, $this->explain($unresolved));
    }

    public function testEveryEntryInTheReferenceCarriesAStatement(): void
    {
        $body = $this->reference();
        preg_match_all(self::ENTRY, $body, $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches, 'The reference resolves nothing.');

        foreach ($matches as $entry) {
            self::assertGreaterThan(
                10,
                mb_strlen(trim($entry[2])),
                sprintf('[%s] resolves to a fragment rather than a statement.', $entry[1]),
            );
        }
    }

    public function testATailwindArbitraryValueIsNotReadAsACitation(): void
    {
        $sample = 'class="leading-[1.15] text-[1.25rem]" and a real one: `[7.8]` plus [10.6a].';

        preg_match_all(self::CITATION, $sample, $matches);

        self::assertSame(['7.8', '10.6a'], $matches[1]);
    }

    /**
     * The reference is the end of the trail, not a signpost further down it.
     * Every file it names has to be a file a reader can actually open.
     */
    public function testTheReferenceNamesNoFileOutsideThisRepository(): void
    {
        preg_match_all(
            '#(?<![\w/])(?:[\w.-]+/)+[\w.-]+\.[a-z]{2,5}(?![\w])#',
            $this->reference(),
            $paths,
        );

        foreach (array_unique($paths[0]) as $path) {
            self::assertFileExists(
                $this->root() . '/' . $path,
                sprintf('The reference sends the reader to "%s", which is not in this repository.', $path),
            );
        }
    }

    /** @return array<string, string> id => statement */
    private function resolvedIds(): array
    {
        preg_match_all(self::ENTRY, $this->reference(), $matches, PREG_SET_ORDER);

        $resolved = [];
        foreach ($matches as $entry) {
            $resolved[$entry[1]] = trim($entry[2]);
        }

        return $resolved;
    }

    /** @return array<string, list<string>> id => the files citing it */
    private function citations(): array
    {
        $cited = [];
        foreach (self::SCANNED as $dir) {
            $path = $this->root() . '/' . $dir;
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            $files = is_dir($path)
                ? new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                )
                : [new SplFileInfo($path)];

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $body = (string) file_get_contents($file->getPathname());
                if (!preg_match_all(self::CITATION, $body, $matches)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($this->root()) + 1);
                foreach (array_unique($matches[1]) as $id) {
                    $cited[$id][] = $relative;
                }
            }
        }

        return $cited;
    }

    /** @param array<string, list<string>> $unresolved */
    private function explain(array $unresolved): string
    {
        if ($unresolved === []) {
            return '';
        }

        $lines = ['These rule ids are cited but resolve to nothing in docs/SPEC-REFERENCE.md:'];
        foreach ($unresolved as $id => $files) {
            sort($files);
            $shown = array_slice($files, 0, 5);
            $lines[] = sprintf(
                '  [%s] cited in %s%s',
                $id,
                implode(', ', $shown),
                count($files) > count($shown) ? sprintf(' and %d more', count($files) - count($shown)) : '',
            );
        }
        $lines[] = 'Add an entry for each, or remove the citation.';

        return implode("\n", $lines);
    }

    private function reference(): string
    {
        $path = $this->root() . '/docs/SPEC-REFERENCE.md';
        self::assertFileExists($path, 'The reference every citation depends on is missing.');

        return (string) file_get_contents($path);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
