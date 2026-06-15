<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cockpit\CockpitSnapshot;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Warms the cockpit's broker-truth snapshot cache at one bounded cadence.
 *
 * The cockpit web surface reads only this cached snapshot, so all dashboard
 * tabs share a single broker read per cycle instead of each hitting the MCP.
 * This is a read-only path: portfolio/positions/orders/capability/market-hours
 * only — never review/place/cancel.
 */
final class CockpitSnapshotCommand extends Command
{
    protected $signature = 'theta:cockpit-snapshot';

    protected $description = 'Capture a read-only broker-truth snapshot for the cockpit cache (no order path).';

    public function handle(): int
    {
        // Pass the full harness config block (no cherry-picking) per the
        // config-propagation discipline.
        $cfg = (array) config('harness');
        $recorder = JsonlRecorder::fromConfig($cfg);
        $snapshot = CockpitSnapshot::fromConfig($cfg);

        try {
            $captured = $snapshot->capture();
            $snapshot->store($captured);
        } catch (Throwable $e) {
            $recorder->record('cockpit_snapshot', [
                'event' => 'snapshot_failed',
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
            $this->error("cockpit snapshot mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        $recorder->record('cockpit_snapshot', [
            'event' => $captured['ok'] ? 'cockpit_snapshot' : 'snapshot_failed',
            'ok' => $captured['ok'],
            'errors' => array_keys($captured['errors']),
            'cash' => $captured['cash'],
            'buying_power' => $captured['buying_power'],
            'open_option_positions' => $captured['derived']['open_option_positions'],
            'entered_today_debit' => $captured['derived']['entered_today_debit'],
        ]);

        $this->info(sprintf(
            'cockpit snapshot stored (ok=%s, failed_sections=%s)',
            $captured['ok'] ? 'true' : 'false',
            $captured['errors'] === [] ? 'none' : implode(',', array_keys($captured['errors'])),
        ));

        return self::SUCCESS;
    }
}
