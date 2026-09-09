<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\PhpArrayExporter;
use PHPUnit\Framework\TestCase;

final class PhpArrayExporterTest extends TestCase
{
    private string $tmpFile;

    protected function tearDown(): void
    {
        if (isset($this->tmpFile) && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testExportProducesExpectedFileShape(): void
    {
        $out = PhpArrayExporter::export(['a' => 1, 'b' => 'two'], 'Generated fixture');

        self::assertSame(
            "<?php\n\n/**\n * Generated fixture\n */\n\nreturn [\n    'a' => 1,\n    'b' => 'two',\n];\n",
            $out,
        );
    }

    public function testExportRendersMultiLineHeader(): void
    {
        $out = PhpArrayExporter::export(['x' => 1], "Line one\nLine two\n\nLine four");

        self::assertSame(
            "<?php\n\n/**\n * Line one\n * Line two\n *\n * Line four\n */\n\nreturn [\n    'x' => 1,\n];\n",
            $out,
        );
    }

    public function testExportRendersEmptyArray(): void
    {
        $out = PhpArrayExporter::export([], 'Empty');

        self::assertSame("<?php\n\n/**\n * Empty\n */\n\nreturn [];\n", $out);
    }

    public function testExportRendersListWithoutExplicitKeys(): void
    {
        $out = PhpArrayExporter::export(['items' => ['a', 'b', 'c']], 'List');

        self::assertStringContainsString(
            "'items' => [\n        'a',\n        'b',\n        'c',\n    ],",
            $out,
        );
    }

    public function testExportRendersNestedMapWithExplicitIntKeys(): void
    {
        // Non-sequential integer keys (0 and 2, no 1) must keep explicit keys.
        $out = PhpArrayExporter::export([0 => 'a', 2 => 'b'], 'Sparse');

        self::assertStringContainsString("0 => 'a',", $out);
        self::assertStringContainsString("2 => 'b',", $out);
    }

    public function testExportEscapesStringsWithQuotesAndBackslashes(): void
    {
        $out = PhpArrayExporter::export(['s' => "it's a \\backslash\\"], 'Escaping');

        self::assertStringContainsString("'s' => 'it\\'s a \\\\backslash\\\\'", $out);
    }

    public function testExportRendersNativeLiteralsForScalarTypes(): void
    {
        $out = PhpArrayExporter::export([
            'int' => 42,
            'float' => 1.5,
            'wholeFloat' => 2.0,
            'true' => true,
            'false' => false,
            'null' => null,
        ], 'Scalars');

        self::assertStringContainsString("'int' => 42,", $out);
        self::assertStringContainsString("'float' => 1.5,", $out);
        self::assertStringContainsString("'wholeFloat' => 2.0,", $out);
        self::assertStringContainsString("'true' => true,", $out);
        self::assertStringContainsString("'false' => false,", $out);
        self::assertStringContainsString("'null' => null,", $out);
    }

    public function testExportRoundTripsNestedStructureThroughRequire(): void
    {
        $data = [
            'channel' => [
                'id' => '507f',
                'name' => "Bob's Channel",
                'currency' => 'USD',
            ],
            'count' => 3,
            'ratio' => 0.5,
            'active' => true,
            'archived' => false,
            'notes' => null,
            'tags' => ['rx', 'otc', 'lab'],
            'products' => [
                'p-one' => [
                    'slug' => 'p-one',
                    'price_cents' => 1999,
                    'variants' => [
                        ['id' => 'v1', 'price_cents' => 1999],
                        ['id' => 'v2', 'price_cents' => 2999],
                    ],
                ],
            ],
            0 => 'sparse-zero',
            2 => 'sparse-two',
        ];

        $php = PhpArrayExporter::export($data, "Round trip fixture\nSecond line");

        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pae-') . '.php';
        file_put_contents($this->tmpFile, $php);

        $roundTripped = require $this->tmpFile;

        self::assertSame($data, $roundTripped);
    }

    /**
     * A header containing a literal comment-close sequence closes the
     * docblock early; anything after it (up to the next occurrence, or EOF)
     * becomes live PHP rather than an inert comment. `export()` must
     * neutralize every such sequence in the header before emitting it, so a
     * header can never break out of its own docblock — verified two ways: no
     * raw comment-close sequence survives except the docblock's own
     * terminator, and the file still round-trips via `require` to exactly
     * the array that was passed in (i.e. the injected `echo 1;` never
     * actually executes as code).
     */
    public function testExportNeutralizesCommentTerminatorInHeaderToPreventInjection(): void
    {
        $data = ['a' => 1];
        $maliciousHeader = 'evil */ ?><?php echo 1; /*';

        $out = PhpArrayExporter::export($data, $maliciousHeader);

        // Exactly one raw '*/' survives: the docblock's own terminator line.
        self::assertSame(1, substr_count($out, '*/'));
        self::assertStringContainsString('evil *\\/ ?><?php echo 1; /*', $out);

        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pae-') . '.php';
        file_put_contents($this->tmpFile, $out);

        ob_start();
        $roundTripped = require $this->tmpFile;
        $echoedOutput = ob_get_clean();

        self::assertSame('', $echoedOutput, 'the injected echo must never execute');
        self::assertSame($data, $roundTripped);
    }

    public function testUnifiedDiffReturnsEmptyStringWhenIdentical(): void
    {
        $diff = PhpArrayExporter::unifiedDiff("a\nb\nc\n", "a\nb\nc\n", 'catalog.php');

        self::assertSame('', $diff);
    }

    public function testUnifiedDiffContainsExpectedHunksAndLabels(): void
    {
        $diff = PhpArrayExporter::unifiedDiff("a\nb\nc\n", "a\nx\nc\n", 'catalog.php');

        self::assertStringContainsString('--- a/catalog.php', $diff);
        self::assertStringContainsString('+++ b/catalog.php', $diff);
        self::assertStringContainsString('@@', $diff);
        self::assertStringContainsString('-b', $diff);
        self::assertStringContainsString('+x', $diff);
    }
}
