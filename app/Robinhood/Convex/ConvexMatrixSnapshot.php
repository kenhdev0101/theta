<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Latest fresh, complete observations from the append-only matrix. */
final class ConvexMatrixSnapshot
{
    public function __construct(private readonly string $path) {}

    /** @return array<string,array{row:array<string,string>,hash:string,snapshot:Carbon}> */
    public function latest(int $maxAgeSeconds = 900): array
    {
        $latest = [];
        foreach ($this->recent($maxAgeSeconds) as $item) {
            $key = self::key((string) $item['row']['ticker'], (int) $item['row']['dte']);
            if (isset($latest[$key]) && $latest[$key]['snapshot']->greaterThan($item['snapshot'])) {
                continue;
            }
            $latest[$key] = $item;
        }

        return $latest;
    }

    public function timestampForHash(string $hash, int $maxAgeSeconds = 900): ?Carbon
    {
        foreach ($this->recent($maxAgeSeconds) as $item) {
            if ($item['hash'] === $hash) {
                return $item['snapshot'];
            }
        }

        return null;
    }

    /** @return list<array{row:array<string,string>,hash:string,snapshot:Carbon}> */
    public function recent(int $maxAgeSeconds = 900): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new RuntimeException('convex matrix is unreadable');
        }
        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('convex matrix read lock unavailable');
            }
            $columns = fgetcsv($handle);
            if (! is_array($columns) || array_diff(['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta'], $columns) !== []) {
                throw new RuntimeException('convex matrix header is invalid');
            }
            $recent = [];
            $now = Carbon::now();
            while (($cells = fgetcsv($handle)) !== false) {
                if (count($cells) !== count($columns)) {
                    continue;
                }
                $row = array_combine($columns, $cells);
                if (! is_array($row) || ! preg_match('/^[A-Z0-9.]{1,12}$/', (string) ($row['ticker'] ?? ''))
                    || ! ctype_digit((string) ($row['dte'] ?? ''))) {
                    continue;
                }
                $stamp = (string) ($row['snapshot_ts'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $stamp) !== 1) {
                    continue;
                }
                try {
                    $parsed = new DateTimeImmutable($stamp);
                    $errors = DateTimeImmutable::getLastErrors();
                    if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                        continue;
                    }
                    $snapshot = Carbon::instance($parsed);
                } catch (\Throwable) {
                    continue;
                }
                $age = $snapshot->diffInSeconds($now, false);
                if ($age < 0 || $age > $maxAgeSeconds) {
                    continue;
                }
                $recent[] = [
                    'row' => $row,
                    'hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'snapshot' => $snapshot,
                ];
            }

            return $recent;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function key(string $ticker, int $dte): string
    {
        return strtoupper($ticker).':'.$dte;
    }

    /** @param array<string,string> $row
     * @return list<string>
     */
    public static function structuralFailures(array $row): array
    {
        if (($row['verdict'] ?? '') !== 'REJECT' || ($row['pending_signals'] ?? '') !== '') {
            return [];
        }
        $failures = array_filter(explode('|', (string) ($row['failed_signals'] ?? '')));

        return array_values(array_intersect($failures, ['conventional_smirk', 'fringe_not_rich', 'quiet_surface_proxy']));
    }
}
