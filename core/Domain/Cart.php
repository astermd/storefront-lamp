<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Domain;

/**
 * The visitor's cart: an ordered set of lines, at most one per product, plus
 * the shipping territory the geo gate is evaluated against once checkout
 * knows it (`[13.3]`).
 *
 * The rules live in {@see CartRules} rather than here on purpose. This class
 * owns only what cannot be got wrong — uniqueness by slug, and the parent
 * link's one structural consequence, that removing a parent takes its
 * children with it in a single operation (`[7.9]`) — so no caller can leave
 * an orphaned bundle line behind by removing the wrong thing first.
 */
final class Cart
{
    /** @var list<CartLine> */
    private array $lines = [];

    public ?string $shippingTerritory = null;

    /** @return list<CartLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function line(string $slug): ?CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->slug === $slug) {
                return $line;
            }
        }

        return null;
    }

    public function has(string $slug): bool
    {
        return $this->line($slug) !== null;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** Appends the line, or replaces the existing one for that slug in place. */
    public function put(CartLine $line): void
    {
        foreach ($this->lines as $index => $existing) {
            if ($existing->slug === $line->slug) {
                $this->lines[$index] = $line;

                return;
            }
        }

        $this->lines[] = $line;
    }

    /**
     * Removes the line and everything that declares it as a parent, however
     * deep — a bundle may itself pull in bundles (`[7.5]`), so the removal has
     * to follow the same tree the add built.
     */
    public function forget(string $slug): void
    {
        $doomed = [$slug => true];

        do {
            $added = false;
            foreach ($this->lines as $line) {
                if ($line->parentSlug !== null && isset($doomed[$line->parentSlug]) && !isset($doomed[$line->slug])) {
                    $doomed[$line->slug] = true;
                    $added = true;
                }
            }
        } while ($added);

        $this->lines = array_values(array_filter(
            $this->lines,
            static fn (CartLine $line): bool => !isset($doomed[$line->slug]),
        ));
    }

    /** @return list<CartLine> */
    public function childrenOf(string $slug): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (CartLine $line): bool => $line->parentSlug === $slug,
        ));
    }

    public function hasRx(): bool
    {
        return $this->rxLine() !== null;
    }

    /** At most one exists at a time — a second prescription replaces the first (`[8.0f]`). */
    public function rxLine(): ?CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->kind === 'rx') {
                return $line;
            }
        }

        return null;
    }

    /**
     * What the navbar badge shows: top-level quantities only, so the syringe
     * that came along with an injectable does not read to the buyer as a
     * second thing they chose.
     */
    public function itemCount(): int
    {
        $count = 0;
        foreach ($this->lines as $line) {
            if (!$line->isChild()) {
                $count += $line->quantity;
            }
        }

        return $count;
    }

    /** Every line, children included — a mandatory bundle is a real charge (`[7.14b]`). */
    public function subtotalCents(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->lineTotalCents();
        }

        return $total;
    }

    /** @return array{lines: list<array<string, mixed>>, territory: ?string} */
    public function toArray(): array
    {
        return [
            'lines' => array_map(static fn (CartLine $line): array => $line->toArray(), $this->lines),
            'territory' => $this->shippingTerritory,
        ];
    }

    /** @param array<string, mixed> $data as produced by {@see self::toArray()} */
    public static function fromArray(array $data): self
    {
        $cart = new self();
        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (is_array($line)) {
                $cart->put(CartLine::fromArray($line));
            }
        }
        $territory = $data['territory'] ?? null;
        $cart->shippingTerritory = is_string($territory) && $territory !== '' ? $territory : null;

        return $cart;
    }
}
