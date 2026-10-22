<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use Illuminate\Support\Carbon;

/** Optional observations only: no broker calls, database writes, or trading decisions. */
final class ConvexEvidence
{
    private const FIELDS = [
        'probe_id', 'thesis_id', 'leg_id', 'matrix_row_hash', 'snapshot_ts', 'ticker', 'dte',
        'verdict', 'option_id', 'option_type', 'expiration_date', 'strike_price', 'target_delta',
        'bid', 'ask', 'delta', 'updated_at', 'age_seconds', 'stale', 'entry_limit', 'limit_price',
        'entry_cost_bps', 'exit_cost_bps', 'round_trip_cost_bps', 'round_trip_budget_bps',
        'premium_ceiling_usd', 'settled_cash', 'buying_power', 'options_spent_today_debit',
        'per_play_max_debit', 'per_day_max_debit', 'per_underlying_max_debit',
        'status', 'side', 'quantity', 'client_ref_id', 'broker_order_id', 'entry_order_id', 'exit_order_id',
        'entry_filled_qty', 'exit_filled_qty', 'entry_average_price', 'exit_average_price',
        'entry_limit_price', 'exit_limit_price', 'opened_at', 'closed_at', 'exit_reason',
        'entry_cancel_reason', 'gross_pnl_usd', 'gross_return_bps', 'fees_usd', 'net_pnl_usd',
        'net_return_bps', 'fee_status', 'phase', 'processed_quantity', 'average_price',
        'created_at', 'last_transaction_at', 'outcome', 'quote', 'contract', 'samples', 'caps', 'blockers',
    ];

    public function __construct(private readonly array $cfg) {}

    public static function stamp(array $cfg, string $event, array $data): bool
    {
        // Even malformed optional telemetry must not change execution/reconciliation.
        try {
            return (new self($cfg))->record($event, $data);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function quote(array $quote): array
    {
        return self::clean([
            ...$quote,
            'bid' => $quote['bid'] ?? $quote['bid_price'] ?? null,
            'ask' => $quote['ask'] ?? $quote['ask_price'] ?? null,
        ]);
    }

    /** Only internal reason codes; never persist arbitrary exception messages. */
    public static function blockers(?string $reason): array
    {
        $codes = [];
        foreach (explode(';', $reason ?? '') as $part) {
            $code = trim(explode(':', $part, 2)[0]);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,95}$/D', $code)) {
                $codes[] = $code;
            }
        }

        return array_slice($codes, 0, 32);
    }

    public function directory(): string
    {
        return (string) (data_get($this->cfg, 'convex.evidence.dir')
            ?: rtrim((string) data_get($this->cfg, 'telemetry.dir', storage_path('harness')), '/').'/convex-evidence');
    }

    public function retentionDays(): int
    {
        return max(1, min(90, (int) data_get($this->cfg, 'convex.evidence.retention_days', 14)));
    }

    public function dailyLimit(): int
    {
        return max(1024, min(32 * 1024 * 1024, (int) data_get($this->cfg, 'convex.evidence.max_daily_bytes', 32 * 1024 * 1024)));
    }

    public function record(string $event, array $data): bool
    {
        $handle = null;
        try {
            if (! data_get($this->cfg, 'convex.evidence.enabled', true)) {
                return false;
            }
            $now = Carbon::now('UTC');
            $line = json_encode([
                'schema_version' => 1, 'ts' => $now->toIso8601ZuluString('microsecond'),
                'event' => preg_match('/^[a-z_]{1,64}$/D', $event) ? $event : 'invalid_event',
                ...self::clean($data),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            if (strlen($line) > 32 * 1024) {
                return $this->drop('record_limit');
            }
            $dir = $this->directory();
            if (is_link($dir) || (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir))) {
                return false;
            }
            $free = @disk_free_space($dir);
            if ($free === false || $free < 256 * 1024 * 1024) {
                return $this->drop('disk_floor');
            }
            $path = $dir.'/convex-evidence-'.$now->toDateString().'.jsonl';
            if (is_link($path)) {
                return $this->drop('unsafe_path');
            }
            $handle = @fopen($path, 'c+b');
            if ($handle === false) {
                return $this->drop('open_failed');
            }
            if (! @flock($handle, LOCK_EX | LOCK_NB)) {
                return $this->drop('lock_busy');
            }
            $size = (int) (fstat($handle)['size'] ?? 0);
            if ($size + strlen($line) > $this->dailyLimit()) {
                return $this->drop('daily_limit');
            }
            if (fseek($handle, 0, SEEK_END) !== 0 || @fwrite($handle, $line) !== strlen($line) || ! @fflush($handle)) {
                // Discard a partial record so the next append remains valid JSONL.
                @ftruncate($handle, $size);

                return $this->drop('write_failed');
            }

            return true;
        } catch (\Throwable) {
            return $this->drop('write_failed');
        } finally {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }

    /** Metadata-only maintenance; never touches the matrix, journals or other streams. */
    public function maintain(bool $apply = false): array
    {
        if (is_link($this->directory())) {
            throw new \RuntimeException('evidence directory must not be a symlink');
        }
        $cutoff = Carbon::now('UTC')->startOfDay()->subDays($this->retentionDays() - 1)->toDateString();
        $files = [];
        $expiredBytes = 0;
        $removedBytes = 0;
        foreach (glob($this->directory().'/convex-evidence-*.jsonl') ?: [] as $path) {
            if (is_link($path) || ! is_file($path)
                || ! preg_match('/^convex-evidence-(\d{4}-\d{2}-\d{2})\.jsonl$/D', basename($path), $match)) {
                continue;
            }
            [$year, $month, $day] = array_map('intval', explode('-', $match[1]));
            if (! checkdate($month, $day, $year)) {
                continue;
            }
            $bytes = (int) filesize($path);
            $expired = $match[1] < $cutoff;
            $removed = false;
            if ($expired) {
                $expiredBytes += $bytes;
                if ($apply) {
                    $handle = @fopen($path, 'r');
                    if ($handle !== false) {
                        try {
                            if (flock($handle, LOCK_EX | LOCK_NB)) {
                                $removed = @unlink($path);
                            }
                        } finally {
                            fclose($handle);
                        }
                    }
                }
            }
            $removedBytes += $removed ? $bytes : 0;
            $files[] = ['date' => $match[1], 'bytes' => $bytes, 'expired' => $expired, 'removed' => $removed];
        }
        $dropPath = $this->directory().'/last-drop.json';
        $lastDrop = is_file($dropPath) && ! is_link($dropPath) ? json_decode((string) @file_get_contents($dropPath), true) : null;

        return ['retention_days' => $this->retentionDays(), 'keep_from_utc' => $cutoff,
            'max_daily_bytes' => $this->dailyLimit(), 'files' => $files,
            'total_bytes' => array_sum(array_column($files, 'bytes')) - $removedBytes,
            'expired_bytes' => $expiredBytes, 'removed_bytes' => $removedBytes, 'last_drop' => $lastDrop];
    }

    private function drop(string $reason): bool
    {
        // A single tiny marker, at most once/minute; no error text or log storm.
        try {
            $dir = $this->directory();
            $path = $dir.'/last-drop.json';
            clearstatcache(true, $path);
            if (is_dir($dir) && ! is_link($dir) && ! is_link($path)
                && (! is_file($path) || time() - (int) @filemtime($path) >= 60)) {
                $handle = @fopen($path, 'c+b');
                if ($handle !== false) {
                    try {
                        if (@flock($handle, LOCK_EX | LOCK_NB)) {
                            @ftruncate($handle, 0);
                            @fwrite($handle, json_encode(['ts' => Carbon::now('UTC')->toIso8601ZuluString(), 'reason' => $reason], JSON_THROW_ON_ERROR));
                        }
                    } finally {
                        @fclose($handle);
                    }
                }
            }
        } catch (\Throwable) {
            // Disk failure cannot be made fatal by the failure marker itself.
        }

        return false;
    }

    private static function clean(array $data, int $depth = 0): array
    {
        $clean = [];
        foreach (array_intersect_key($data, array_flip(self::FIELDS)) as $key => $value) {
            if (is_array($value)) {
                if ($depth >= 3) {
                    continue;
                }
                $clean[$key] = array_is_list($value)
                    ? array_map(static fn ($row) => is_array($row) ? self::clean($row, $depth + 1) : self::scalar($row), array_slice($value, 0, 32))
                    : self::clean($value, $depth + 1);
            } else {
                $clean[$key] = self::scalar($value);
            }
        }

        return $clean;
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_float($value) && ! is_finite($value)) {
            return null;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 160);
        }

        return is_scalar($value) || $value === null ? $value : null;
    }
}
