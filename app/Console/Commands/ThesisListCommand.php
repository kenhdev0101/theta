<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\ThesisIntent;
use Illuminate\Console\Command;

/**
 * Read the standing thesis book — status, triggers, and what blocks each
 * draft from arming. Read-only.
 */
class ThesisListCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:thesis-list {--all : Include expired/closed rows}';

    protected $description = 'List thesis intents with activation blockers and lane occupancy';

    public function handle(): int
    {
        $laneCfg = (array) (($this->assembleConfig()['thesis'] ?? []));

        $query = ThesisIntent::query();
        if (! $this->option('all')) {
            $query->whereIn('status', ThesisIntent::STANDING_STATUSES);
        }

        $rows = $query->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            $this->info('no thesis intents');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'status', 'symbol', 'direction', 'dte', 'max_debit', 'price_stop', 'time_stop', 'target_bps', 'blockers'],
            $rows->map(fn (ThesisIntent $intent): array => [
                $intent->id,
                $intent->status,
                $intent->underlying_symbol,
                $intent->direction,
                "{$intent->dte_min}-{$intent->dte_max}",
                (string) $intent->max_debit,
                $intent->price_stop !== null ? (string) $intent->price_stop : '—',
                $intent->time_stop_date?->toDateString() ?? '—',
                $intent->profit_target_bps !== null ? (string) $intent->profit_target_bps : '—',
                $intent->status === ThesisIntent::STATUS_ACTIVE
                    ? ''
                    : implode(', ', $intent->activationBlockers($laneCfg)),
            ])->all(),
        );

        $active = $rows->where('status', ThesisIntent::STATUS_ACTIVE)->count();
        $maxOpen = (int) ($laneCfg['max_open_positions'] ?? 3);
        $this->line("active theses: {$active} (lane max open positions: {$maxOpen})");

        return self::SUCCESS;
    }
}
