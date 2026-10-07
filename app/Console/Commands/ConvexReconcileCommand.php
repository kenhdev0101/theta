<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RobinhoodTradeJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/** Reconcile only convex option journals when broker truth is still needed. */
final class ConvexReconcileCommand extends Command
{
    protected $signature = 'theta:convex-reconcile';

    protected $description = 'Refresh pending convex option fills and realized P&L from broker truth';

    public function handle(): int
    {
        $pending = RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')->where('strategy_label', 'convex_thesis_probe')
            ->where(function ($query): void {
                $query->whereIn('status', ['submitted', 'open', 'partially_filled'])
                    ->orWhere(function ($close): void {
                        $close->where('position_effect', 'close')->where('status', 'filled')->whereNull('realized_pnl');
                    });
            })->exists();
        if (! $pending) {
            return self::SUCCESS;
        }

        $result = Artisan::call('theta:option-reconcile', ['--strategy' => 'convex_thesis_probe', '--no-interaction' => true]);
        $this->output->write(Artisan::output());

        return $result;
    }
}
