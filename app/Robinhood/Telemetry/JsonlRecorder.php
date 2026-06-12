<?php

declare(strict_types=1);

namespace App\Robinhood\Telemetry;

use RuntimeException;

/**
 * JSONL system-of-record writer. Append-only, one event per line, daily
 * files per stream. Broker truth still outranks these logs — JSONL is the
 * harness's durable diagnostic record, not the judge.
 */
final class JsonlRecorder
{
    public function __construct(
        private readonly string $dir,
        private readonly string $agentId = 'theta-harness',
    ) {}

    public static function fromConfig(array $cfg): self
    {
        $telemetry = $cfg['telemetry'] ?? [];

        return new self(
            dir: (string) ($telemetry['dir'] ?? storage_path('harness')),
            agentId: (string) ($telemetry['agent_id'] ?? 'theta-harness'),
        );
    }

    /**
     * Append one event to the stream and return the stamped record.
     */
    public function record(string $stream, array $event): array
    {
        $record = array_merge([
            'ts' => now()->toIso8601ZuluString('microsecond'),
            'stream' => $stream,
            'agent_id' => $this->agentId,
            'pid' => getmypid(),
        ], $event);

        if (! is_dir($this->dir) && ! mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new RuntimeException("telemetry dir not writable: {$this->dir}");
        }

        $path = sprintf('%s/%s-%s.jsonl', rtrim($this->dir, '/'), $stream, now()->toDateString());
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);

        if (file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("telemetry append failed: {$path}");
        }

        return $record;
    }
}
