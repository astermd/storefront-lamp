<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Support;

/**
 * Renders a PHP array into a complete, `require`-able PHP source file body
 * (`<?php` + a header docblock + `return [...];`), and produces a unified
 * diff between two such source strings — the two primitives a generated
 * catalog file needs: write it out deterministically, and show what changed
 * on the next sync.
 *
 * `export()` is a hand-written recursive renderer rather than a
 * `var_export()`-and-fix-up pass: it walks the array once, decides
 * list-vs-map per level via {@see array_is_list()}, and emits short-array
 * syntax with 4-space indentation directly, preserving insertion order
 * throughout (no key sorting anywhere). The one place it defers to
 * `var_export()` is float formatting — reproducing PHP's own
 * `serialize_precision`-aware float-to-string rules would just re-implement
 * what the engine already exposes, so leaf floats are rendered via
 * `var_export($float, true)` and everything else (ints, bools, null,
 * strings, array structure) is emitted directly.
 *
 * `unifiedDiff()` shells out to the system `diff -u` binary via `proc_open`
 * (present on macOS and any Linux deploy target) rather than reimplementing
 * an LCS/Myers diff in PHP — it is the simplest correct option and the
 * output format is exactly what a human reviewing a catalog change expects.
 * A missing/failing `diff` binary degrades to a clear placeholder marker
 * instead of throwing, since a diff is a human-facing convenience, not a
 * correctness-critical path.
 */
final class PhpArrayExporter
{
    private const string INDENT = '    ';

    /**
     * @param array<array-key, mixed> $data
     */
    public static function export(array $data, string $header): string
    {
        // Neutralize '*/' in header content before it lands inside the
        // docblock: an unescaped '*/' would close the comment early, turning
        // whatever follows in $header (attacker-controlled channel/product
        // data, in the sync command's case) into live executable PHP rather
        // than an inert comment line.
        $header = str_replace('*/', '*\/', $header);

        $headerLines = array_map(
            static fn (string $line): string => $line === '' ? ' *' : " * {$line}",
            explode("\n", $header),
        );

        return sprintf(
            "<?php\n\n/**\n%s\n */\n\nreturn %s;\n",
            implode("\n", $headerLines),
            self::renderArray($data, 0),
        );
    }

    /**
     * Plain unified diff of two strings, with `--- a/<label>` / `+++
     * b/<label>` headers instead of temp-file paths. Empty string when
     * `$old === $new` (checked directly, without invoking `diff` at all).
     */
    public static function unifiedDiff(string $old, string $new, string $label): string
    {
        if ($old === $new) {
            return '';
        }

        $oldFile = tempnam(sys_get_temp_dir(), 'pae-old-');
        $newFile = tempnam(sys_get_temp_dir(), 'pae-new-');

        if ($oldFile === false || $newFile === false) {
            return '(diff unavailable — contents differ)';
        }

        try {
            file_put_contents($oldFile, $old);
            file_put_contents($newFile, $new);

            return self::runDiff($oldFile, $newFile, $label);
        } finally {
            @unlink($oldFile);
            @unlink($newFile);
        }
    }

    private static function runDiff(string $oldFile, string $newFile, string $label): string
    {
        $command = ['diff', '-u', '-L', "a/{$label}", '-L', "b/{$label}", $oldFile, $newFile];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return '(diff unavailable — contents differ)';
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // 0 = identical (unreachable here, already handled above), 1 =
        // differences found (the expected path), >1 = diff itself errored
        // (e.g. binary present but misbehaving) — degrade rather than throw.
        if ($exitCode !== 0 && $exitCode !== 1) {
            return '(diff unavailable — contents differ)';
        }

        return $stdout;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function renderArray(array $data, int $level): string
    {
        if ($data === []) {
            return '[]';
        }

        $indent = str_repeat(self::INDENT, $level);
        $childIndent = str_repeat(self::INDENT, $level + 1);
        $isList = array_is_list($data);

        $lines = [];
        foreach ($data as $key => $value) {
            $renderedValue = is_array($value) ? self::renderArray($value, $level + 1) : self::renderScalar($value);

            $lines[] = $isList
                ? "{$childIndent}{$renderedValue},"
                : "{$childIndent}" . self::renderKey($key) . " => {$renderedValue},";
        }

        return "[\n" . implode("\n", $lines) . "\n{$indent}]";
    }

    private static function renderKey(int|string $key): string
    {
        return is_int($key) ? (string) $key : self::renderString($key);
    }

    private static function renderScalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            is_string($value) => self::renderString($value),
            default => throw new \InvalidArgumentException(
                'PhpArrayExporter: unsupported value type ' . get_debug_type($value),
            ),
        };
    }

    private static function renderString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
