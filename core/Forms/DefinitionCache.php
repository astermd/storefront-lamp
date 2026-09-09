<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The server-side definition cache `[3.11]` closes `[3.6]` with: a form
 * definition is otherwise fetched from a signed remote URL on every request
 * that renders a form, which puts a third-party round trip on the critical
 * path of the highest-abandonment step in the funnel.
 *
 * The key is the teleform's `form_json_identifier`, which carries the form's
 * version and publish timestamp. That makes republishing a form a cache miss
 * on its own, so there is no invalidation to get wrong `[3.12]` — the TTL is
 * only a backstop for a definition nobody republished but which should be
 * re-read anyway. The identifier is a path (`account/org/slug_v_epoch.json`),
 * so it is hashed rather than used as a filename: it contains separators, and
 * a value that reached the filesystem verbatim would be a traversal.
 */
final class DefinitionCache
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function get(string $identifier): ?array
    {
        $path = $this->pathFor($identifier);
        if (!is_file($path)) {
            return null;
        }

        $age = time() - (int) filemtime($path);
        if ($this->ttlSeconds > 0 && $age > $this->ttlSeconds) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $definition */
    public function put(string $identifier, array $definition): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
            return;
        }

        $encoded = json_encode($definition, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        // Written via a temp file in the same directory and renamed, so a
        // concurrent reader never sees a half-written definition -- two
        // PHP-FPM workers can both miss and both fetch.
        $path = $this->pathFor($identifier);
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, $encoded) === false) {
            return;
        }

        if (!rename($temp, $path)) {
            @unlink($temp);
        }
    }

    /** @return int how many cached definitions were removed, for the operator command's output */
    public function purge(): int
    {
        $removed = 0;
        // `*.tmp` included: a rename that failed mid-write leaves one behind,
        // and nothing else would ever collect it.
        foreach (array_merge(glob($this->directory . '/*.json') ?: [], glob($this->directory . '/*.tmp') ?: []) as $file) {
            if (unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * What the health endpoint reports about this cache (`[20.15]`).
     *
     * Answered here rather than by an inspector reading the directory,
     * because the layout is this class's own: the filenames are hashes and
     * `.tmp` files are a rename that did not finish. A probe that walked the
     * directory itself would be a second reader of a private convention, and
     * it would drift.
     *
     * **Writable is not the same as present.** A missing directory is the
     * ordinary state of a freshly deployed instance and is created on the
     * first write, so what is asked is whether that write could succeed — the
     * directory if it exists, and otherwise the nearest ancestor that does.
     *
     * @return array{writable: bool, entries: int}
     */
    public function state(): array
    {
        return [
            'writable' => is_writable(self::nearestExistingAncestor($this->directory)),
            'entries' => count(glob($this->directory . '/*.json') ?: []),
        ];
    }

    /**
     * The closest directory that exists at or above `$path`.
     *
     * `dirname()` is fixed-point at the filesystem root, so the loop
     * terminates on any input rather than relying on the path being
     * well-formed.
     */
    private static function nearestExistingAncestor(string $path): string
    {
        while (!is_dir($path)) {
            $parent = dirname($path);

            if ($parent === $path) {
                return $path;
            }

            $path = $parent;
        }

        return $path;
    }

    private function pathFor(string $identifier): string
    {
        return $this->directory . '/' . hash('sha256', $identifier) . '.json';
    }
}
