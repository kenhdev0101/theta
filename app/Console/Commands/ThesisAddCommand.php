<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\ThesisIntent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Author a standing thesis intent — the operator's precommitment step.
 *
 * Creates a draft by default; --activate runs the playbook-discipline gate
 * (thesis text + all three machine kill triggers + coherent band/sizing) and
 * arms the row only when every blocker clears. Strikes are never entered
 * here — they derive at pre-open assembly against live spot.
 */
class ThesisAddCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:thesis-add
        {--symbol= : Underlying symbol, e.g. C}
        {--direction= : bullish|bearish}
        {--dte-min= : Minimum days to expiry for contract selection}
        {--dte-max= : Maximum days to expiry for contract selection}
        {--max-debit= : Per-entry debit cap in USD for this thesis}
        {--thesis= : The why — thesis text (required to activate)}
        {--catalyst= : Catalyst / window description}
        {--price-stop= : Underlying price level that kills the thesis}
        {--time-stop= : Date (Y-m-d) after which the thesis expires unentered/exited}
        {--profit-target-bps= : Profit target on the option debit, in bps (e.g. 6000 = +60%)}
        {--entry-limit-mode=mid : Entry limit derivation rule}
        {--entry-offset-bps=0 : Offset applied to the entry limit rule, in bps}
        {--activate : Arm the thesis now if every activation blocker clears}';

    protected $description = 'Author a standing thesis intent for the thesis-position lane';

    public function handle(): int
    {
        $symbol = strtoupper(trim((string) $this->option('symbol')));
        $direction = strtolower(trim((string) $this->option('direction')));

        if ($symbol === '') {
            $this->error('blocker: --symbol is required');

            return self::FAILURE;
        }

        if (! in_array($direction, [ThesisIntent::DIRECTION_BULLISH, ThesisIntent::DIRECTION_BEARISH], true)) {
            $this->error('blocker: --direction must be bullish or bearish');

            return self::FAILURE;
        }

        $timeStop = null;
        if (($raw = trim((string) $this->option('time-stop'))) !== '') {
            try {
                $timeStop = Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
            } catch (\Throwable) {
                $this->error("blocker: --time-stop '{$raw}' is not a Y-m-d date");

                return self::FAILURE;
            }
        }

        $intent = new ThesisIntent([
            'underlying_symbol' => $symbol,
            'direction' => $direction,
            'structure' => ThesisIntent::structureForDirection($direction),
            'dte_min' => (int) $this->option('dte-min'),
            'dte_max' => (int) $this->option('dte-max'),
            'max_debit' => (float) $this->option('max-debit'),
            'entry_limit_mode' => trim((string) $this->option('entry-limit-mode')),
            'entry_limit_offset_bps' => (float) $this->option('entry-offset-bps'),
            'thesis' => trim((string) $this->option('thesis')),
            'catalyst' => trim((string) $this->option('catalyst')) ?: null,
            'price_stop' => $this->option('price-stop') !== null ? (float) $this->option('price-stop') : null,
            'time_stop_date' => $timeStop,
            'profit_target_bps' => $this->option('profit-target-bps') !== null ? (float) $this->option('profit-target-bps') : null,
            'status' => ThesisIntent::STATUS_DRAFT,
        ]);

        $laneCfg = (array) (($this->assembleConfig()['thesis'] ?? []));
        $blockers = $intent->activationBlockers($laneCfg);

        $perPlayCap = (float) ($laneCfg['per_play_max_debit'] ?? 0.0);
        if ($perPlayCap > 0.0 && (float) $intent->max_debit > $perPlayCap) {
            $blockers[] = 'max_debit_exceeds_lane_per_play_cap';
        }

        if ($this->option('activate')) {
            if ($blockers !== []) {
                $intent->save();
                $this->warn("thesis {$intent->id} saved as draft; activation blocked:");
                foreach ($blockers as $blocker) {
                    $this->line("  - {$blocker}");
                }

                return self::FAILURE;
            }

            $intent->status = ThesisIntent::STATUS_ACTIVE;
            $intent->activated_at = now();
        }

        $intent->save();

        $this->info("thesis {$intent->id} {$intent->status}: {$symbol} {$direction} {$intent->structure} dte {$intent->dte_min}-{$intent->dte_max} max_debit {$intent->max_debit}");
        if ($intent->status === ThesisIntent::STATUS_DRAFT && $blockers !== []) {
            $this->line('activation blockers: '.implode(', ', $blockers));
        }

        return self::SUCCESS;
    }
}
