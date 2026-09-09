<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

/**
 * The consent set a deployment shows at checkout (§26).
 *
 * Consent is an explicit affirmative action (`[26.2]`): nothing here has a
 * default of "granted", and the template renders every control unchecked. A
 * consent absent from the submitted body is a decline, recorded as `false`
 * rather than omitted -- `[26.10]` removes the hardcoded always-true flags,
 * and a record listing only the ticked boxes cannot tell a decline from a
 * question that was never asked.
 *
 * The version is a hash of the copy itself (`[26.7]`). A number someone has to
 * remember to bump is a number that will not get bumped; a hash makes editing
 * a comma a new version automatically.
 */
final class Consents
{
    /** @param list<ConsentDefinition> $definitions */
    private function __construct(
        private readonly array $definitions,
        private readonly string $version,
        private readonly \Closure $clock,
    ) {
    }

    /**
     * @param array<string, mixed>        $config `config/consent.php`
     * @param (\Closure(): string)|null   $clock  the current ISO-8601 timestamp; injected so tests do not depend on the wall clock
     */
    public static function fromConfig(array $config, ?\Closure $clock = null): self
    {
        $definitions = [];
        $rows = is_array($config['consents'] ?? null) ? $config['consents'] : [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $links = [];
            foreach (is_array($row['links'] ?? null) ? $row['links'] : [] as $link) {
                if (is_string($link) && $link !== '') {
                    $links[] = $link;
                }
            }

            $definitions[] = new ConsentDefinition(
                key: $key,
                label: (string) ($row['label'] ?? $key),
                html: (string) ($row['html'] ?? ''),
                blocking: ($row['blocking'] ?? false) === true,
                links: $links,
            );
        }

        return new self(
            $definitions,
            self::hash($definitions),
            $clock ?? static fn (): string => gmdate('c'),
        );
    }

    /** @return list<ConsentDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * The same set minus consents an intake form already collected
     * (`[26.11]`).
     *
     * @param list<string> $keys
     */
    public function except(array $keys): self
    {
        $remaining = array_values(array_filter(
            $this->definitions,
            static fn (ConsentDefinition $d): bool => !in_array($d->key, $keys, true),
        ));

        // The version follows the copy actually shown, so a page that hides a
        // consent records a different version than one that shows it.
        return new self($remaining, self::hash($remaining), $this->clock);
    }

    /**
     * Turns a submitted body into one record per configured consent, and names
     * the blocking ones that were not granted.
     *
     * @param  array<string, mixed> $checked the submitted consent field values
     * @return array{records: list<ConsentRecord>, missing: list<string>}
     */
    public function record(array $checked): array
    {
        $at = ($this->clock)();
        $records = [];
        $missing = [];

        foreach ($this->definitions as $definition) {
            $granted = array_key_exists($definition->key, $checked)
                && !in_array((string) $checked[$definition->key], ['', '0', 'false', 'off'], true);

            if ($definition->blocking && !$granted) {
                $missing[] = $definition->key;
            }

            $records[] = new ConsentRecord(
                key: $definition->key,
                granted: $granted,
                copyVersion: $this->version,
                copyShown: $definition->html,
                at: $at,
            );
        }

        return ['records' => $records, 'missing' => $missing];
    }

    /** @param list<ConsentDefinition> $definitions */
    private static function hash(array $definitions): string
    {
        $material = '';
        foreach ($definitions as $definition) {
            $material .= $definition->key . "\0" . $definition->html . "\0" . ($definition->blocking ? '1' : '0') . "\n";
        }

        return substr(hash('sha256', $material), 0, 16);
    }
}
