<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cockpit\CockpitData;
use App\Cockpit\MonitorEvaluator;
use App\Cockpit\MonitorNotifier;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

/**
 * On-box behaviour monitor. Builds the cockpit payload (read-only, from the
 * cached broker snapshot), evaluates it for anomalies, records a digest to the
 * cockpit_monitor stream (the cockpit surfaces the latest one), and pushes an
 * alert to the operator channel.
 *
 * Push policy: always push when something needs attention; push a nominal
 * heartbeat only with --heartbeat (used by the 30-minute market-open schedule
 * so the operator sees behaviour each cycle; the daily grant-safety run omits
 * it so quiet days don't spam).
 */
final class CockpitMonitorCommand extends Command
{
    protected $signature = 'theta:cockpit-monitor {--heartbeat : Push a digest even when all-nominal}';

    protected $description = 'Evaluate the cockpit for anomalies, record a digest, and push an alert to the operator channel.';

    public function handle(): int
    {
        $cfg = (array) config('harness');
        $monitorCfg = (array) ($cfg['monitor'] ?? []);
        $recorder = JsonlRecorder::fromConfig($cfg);

        $payload = CockpitData::build($cfg)->payload();
        $report = MonitorEvaluator::evaluate($payload, $monitorCfg);

        $recorder->record('cockpit_monitor', [
            'event' => 'monitor',
            'severity' => $report['severity'],
            'ok' => $report['ok'],
            'summary' => $report['summary'],
            'alerts' => $report['alerts'],
            'lines' => $report['lines'],
        ]);

        $this->line('['.$report['severity'].'] '.$report['summary']);
        foreach ($report['alerts'] as $a) {
            $this->warn('  - '.$a['message']);
        }
        foreach ($report['lines'] as $line) {
            $this->line('  '.$line);
        }

        $shouldPush = (bool) ($monitorCfg['enabled'] ?? false)
            && (string) ($monitorCfg['webhook_url'] ?? '') !== ''
            && (! $report['ok'] || $this->option('heartbeat'));

        if ($shouldPush) {
            $result = (new MonitorNotifier)->send($report, $monitorCfg);
            $recorder->record('cockpit_monitor', [
                'event' => $result['sent'] ? 'push_sent' : 'push_skipped',
                'severity' => $report['severity'],
                'reason' => $result['reason'],
            ]);
            $this->line($result['sent'] ? 'push: sent' : 'push: not sent ('.$result['reason'].')');
        }

        return self::SUCCESS;
    }
}
