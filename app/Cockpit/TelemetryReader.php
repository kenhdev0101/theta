<?php

declare(strict_types=1);

namespace App\Cockpit;

use App\Models\RobinhoodTradeJournal;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the harness's decision record — the trade journal (system of record
 * for what the producer and autopilot decided) plus the JSONL telemetry
 * streams (mechanical health: placements, broker calls, reconciles, data
 * plane). Returns raw structured data; the cockpit's Lexicon decoding happens
 * in CockpitData. No broker calls here — this is all local DB + disk.
 */
final class TelemetryReader
{
    /** Block-bearing journal statuses (producer/autopilot record reasons in notes). */
    private const BLOCKED_STATUSES = ['risk_aborted', 'scan_suppressed'];

    public function __construct(private readonly string $dir) {}

    public static function fromConfig(?array $cfg = null): self
    {
        $cfg = $cfg ?? (array) config('harness');

        return new self((string) ($cfg['telemetry']['dir'] ?? storage_path('harness')));
    }

    /**
     * The decision timeline: journal rows touched today, newest first. Each
     * row carries its decoded-ready fields plus the block reason pulled from
     * notes.{autopilot_block|producer_block}.reason.
     *
     * @return list<array<string,mixed>>
     */
    public function decisionsToday(int $limit = 60): array
    {
        return $this->journalRows(
            RobinhoodTradeJournal::query()
                ->where('updated_at', '>=', now()->startOfDay())
                ->orderByDesc('updated_at')
                ->limit($limit)
        );
    }

    /**
     * Recent journal rows regardless of day, for the inspection table.
     *
     * @return list<array<string,mixed>>
     */
    public function recentJournal(int $limit = 25): array
    {
        return $this->journalRows(
            RobinhoodTradeJournal::query()
                ->orderByDesc('updated_at')
                ->limit($limit)
        );
    }

    /**
     * Every raw block reason recorded today (producer + autopilot). Returned
     * flat so CockpitData can group and decode them into the why-not-trading
     * rollup.
     *
     * @return list<string>
     */
    public function blockReasonsToday(): array
    {
        $reasons = [];

        $rows = RobinhoodTradeJournal::query()
            ->whereIn('status', self::BLOCKED_STATUSES)
            ->where('updated_at', '>=', now()->startOfDay())
            ->get(['notes']);

        foreach ($rows as $row) {
            $reason = data_get($row->notes, 'autopilot_block.reason')
                ?? data_get($row->notes, 'producer_block.reason');
            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        // Executor-level rail blocks carry the most granular reasons in JSONL.
        foreach ($this->readStream('options_execution') as $event) {
            if (($event['event'] ?? null) === 'rail_block') {
                foreach ((array) ($event['block_reasons'] ?? []) as $reason) {
                    if (is_string($reason) && $reason !== '') {
                        $reasons[] = $reason;
                    }
                }
            }
        }

        return $reasons;
    }

    /**
     * Today's counts by lifecycle bucket — the headline scorecard.
     *
     * @return array<string,int>
     */
    public function scorecardToday(): array
    {
        $rows = RobinhoodTradeJournal::query()
            ->where('updated_at', '>=', now()->startOfDay())
            ->get(['status', 'intent']);

        $score = [
            'candidates' => 0,
            'reviewed' => 0,
            'placed' => 0,
            'filled' => 0,
            'blocked' => 0,
            'rejected' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) $row->status;
            match (true) {
                in_array($status, ['submitted', 'open', 'partially_filled'], true) => $score['placed']++,
                $status === 'filled' => $score['filled']++,
                $status === 'reviewed' => $score['reviewed']++,
                $status === 'rejected' => $score['rejected']++,
                in_array($status, self::BLOCKED_STATUSES, true) => $score['blocked']++,
                in_array($status, ['candidate', 'watchlisted'], true) => $score['candidates']++,
                default => null,
            };
        }

        return $score;
    }

    /** Realized P&L booked today (sum of realized_pnl over rows touched today). */
    public function realizedPnlToday(): float
    {
        return (float) RobinhoodTradeJournal::query()
            ->where('updated_at', '>=', now()->startOfDay())
            ->whereNotNull('realized_pnl')
            ->sum('realized_pnl');
    }

    /**
     * Recent mechanical events from the JSONL streams (placements, reconciles,
     * data-plane checks, snapshots), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function systemEvents(int $limit = 30): array
    {
        $streams = ['options_execution', 'execution', 'options_review_probe', 'options_reconciliation', 'reconciliation', 'data_plane', 'cockpit_snapshot'];
        $events = [];

        foreach ($streams as $stream) {
            foreach ($this->readStream($stream) as $event) {
                $event['stream'] = $event['stream'] ?? $stream;
                $events[] = $event;
            }
        }

        usort($events, fn ($a, $b) => strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? '')));

        return array_slice($events, 0, $limit);
    }

    /**
     * Broker MCP call health for today (from the mcp_calls stream).
     *
     * @return array<string,mixed>
     */
    public function mcpHealth(): array
    {
        $calls = $this->readStream('mcp_calls');
        $total = count($calls);
        $errors = 0;
        $lastError = null;
        $lastCallTs = null;

        foreach ($calls as $call) {
            if (($call['ok'] ?? true) === false) {
                $errors++;
                $lastError = [
                    'tool' => $call['tool'] ?? null,
                    'error' => $call['error'] ?? null,
                    'ts' => $call['ts'] ?? null,
                ];
            }
            $lastCallTs = $call['ts'] ?? $lastCallTs;
        }

        return [
            'total' => $total,
            'errors' => $errors,
            'last_error' => $lastError,
            'last_call_ts' => $lastCallTs,
        ];
    }

    /**
     * Latest market-data-plane freshness signal (from the data_plane stream).
     *
     * @return array<string,mixed>|null
     */
    public function dataPlane(): ?array
    {
        $events = $this->readStream('data_plane');
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            if (in_array($event['event'] ?? null, ['smoke_test', 'smoke_test_failed'], true)) {
                return [
                    'ok' => ($event['event'] ?? null) === 'smoke_test',
                    'ts' => $event['ts'] ?? null,
                    'symbol' => $event['symbol'] ?? null,
                    'spread_bps' => $event['spread_bps'] ?? null,
                    'error' => $event['error'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<RobinhoodTradeJournal>  $query
     * @return list<array<string,mixed>>
     */
    private function journalRows($query): array
    {
        return $query->get()->map(function (RobinhoodTradeJournal $row): array {
            $reason = data_get($row->notes, 'autopilot_block.reason')
                ?? data_get($row->notes, 'producer_block.reason');

            return [
                'id' => $row->id,
                'asset_class' => $row->asset_class,
                'symbol' => $row->symbol,
                'underlying_symbol' => $row->underlying_symbol,
                'status' => $row->status,
                'intent' => $row->intent,
                'side' => $row->side,
                'position_effect' => $row->position_effect,
                'option_type' => $row->option_type,
                'strike_price' => $row->strike_price !== null ? (float) $row->strike_price : null,
                'expiration_date' => optional($row->expiration_date)->toDateString(),
                'quantity' => $row->quantity !== null ? (float) $row->quantity : null,
                'limit_price' => $row->limit_price !== null ? (float) $row->limit_price : null,
                'average_fill_price' => $row->average_fill_price !== null ? (float) $row->average_fill_price : null,
                'review_estimated_cost' => $row->review_estimated_cost !== null ? (float) $row->review_estimated_cost : null,
                'realized_pnl' => $row->realized_pnl !== null ? (float) $row->realized_pnl : null,
                'unrealized_pnl' => $row->unrealized_pnl !== null ? (float) $row->unrealized_pnl : null,
                'structure_class' => $row->structure_class,
                'relative_strength_state' => $row->relative_strength_state,
                'gap_classification' => $row->gap_classification,
                'trend_geometry_state' => $row->trend_geometry_state,
                'liquidity_tier' => $row->liquidity_tier,
                'thesis' => $row->thesis,
                'block_reason' => is_string($reason) ? $reason : null,
                'broker_order_id' => $row->broker_order_id,
                'updated_at' => optional($row->updated_at)->toIso8601ZuluString(),
            ];
        })->all();
    }

    /**
     * Read and JSON-decode today's lines for a stream, tail-capped. Missing
     * files are not an error — they just mean nothing happened on that stream
     * today.
     *
     * @return list<array<string,mixed>>
     */
    private function readStream(string $stream, int $maxLines = 4000): array
    {
        $path = sprintf('%s/%s-%s.jsonl', rtrim($this->dir, '/'), $stream, now()->toDateString());
        if (! is_file($path)) {
            return [];
        }

        try {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } catch (Throwable) {
            return [];
        }

        if ($lines === false || $lines === []) {
            return [];
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        $events = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }
}
