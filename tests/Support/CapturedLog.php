<?php

declare(strict_types=1);

namespace AsterMD\Storefront\Tests\Support;

use AsterMD\Storefront\Support\OperatorLog;

/**
 * A real {@see OperatorLog} writing to a temporary file, with the lines read
 * back for assertions.
 *
 * A recording double was the obvious alternative and is the worse one on two
 * counts. `OperatorLog` is `final`, so a double would have to duplicate its
 * interface rather than extend it — and duplicating it means the redaction
 * pass never runs, which is precisely what the most important assertions in
 * this suite are about. Every "no card number reached the log" test is only
 * meaningful against the logger that actually ships.
 */
final class CapturedLog
{
    public readonly OperatorLog $log;

    private readonly string $file;

    public function __construct()
    {
        $this->file = sys_get_temp_dir() . '/captured-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->log = new OperatorLog($this->file);
    }

    public function __destruct()
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /**
     * Every line written so far, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $lines = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->file))) as $line) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    /** The raw file contents, for asserting that something never reached disk. */
    public function contents(): string
    {
        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }

    /** @return array<string, mixed>|null */
    public function lastInfo(): ?array
    {
        return $this->last('info');
    }

    /** @return array<string, mixed>|null */
    public function lastWarning(): ?array
    {
        return $this->last('warning');
    }

    /** @return array<string, mixed>|null */
    public function lastError(): ?array
    {
        return $this->last('error');
    }

    /** @return list<array<string, mixed>> */
    public function eventsNamed(string $event): array
    {
        return array_values(array_filter(
            $this->lines(),
            static fn (array $line): bool => ($line['event'] ?? null) === $event,
        ));
    }

    /** @return array<string, mixed>|null */
    private function last(string $level): ?array
    {
        $matching = array_values(array_filter(
            $this->lines(),
            static fn (array $line): bool => ($line['level'] ?? null) === $level,
        ));

        return $matching === [] ? null : $matching[count($matching) - 1];
    }
}
