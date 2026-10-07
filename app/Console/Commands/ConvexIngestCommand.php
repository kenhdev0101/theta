<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Convex\ConvexIngestService;
use App\Robinhood\Convex\FeatureMatrixWriter;
use App\Robinhood\Convex\GreeksRunClient;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

/**
 * Fringe-convexity near-0.20Δ data-plane ingestion (read-only).
 *
 * Reads live broker option truth for the configured index underlyings in the
 * configured DTE band, routes it through the sealed convex feature extractor,
 * validates each emitted row against the sealed schema, and appends every clean
 * observation to the feature matrix with reserved outcome columns null. No
 * orders, no money-moving numbers — the greeks service does the math.
 *
 * Default-off (harness.convex.enabled) is the SCHEDULER arm only: a manual
 * invocation always runs a sweep (pass --once / --loop / --dry-run), so the
 * infra pass can collect without arming cron.
 *
 * Owner: model-lane / claude  |  Agent ID: claude/theta-fringe
 * Spec: docs/research/fringe-convexity/green-engine-architecture.md
 */
class ConvexIngestCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:convex-ingest
        {--ticker=* : Limit the sweep to these underlyings (default: harness.convex.tickers)}
        {--once : Run one sweep even when the lane is disarmed}
        {--loop : Run repeatedly until interrupted}
        {--interval= : Seconds between loop iterations (default: harness.convex.loop_interval_seconds)}
        {--dry-run : Fetch, compute, validate and stamp telemetry, but do not append to the matrix}';

    protected $description = 'Ingest live index option data into the sealed convex feature matrix (read-only)';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        $enabled = (bool) data_get($cfg, 'convex.enabled', false);
        $loop = (bool) $this->option('loop');
        $dryRun = (bool) $this->option('dry-run');
        $once = (bool) $this->option('once');

        // Default-off gates the scheduled run only; any explicit manual flag runs.
        if (! $enabled && ! $loop && ! $dryRun && ! $once) {
            $this->info('convex ingest disarmed (harness.convex.enabled=false) — pass --once for a manual sweep, --loop to stream.');

            return self::SUCCESS;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent — run theta:rh-oauth-* before ingesting.');

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $writer = new FeatureMatrixWriter((string) data_get($cfg, 'convex.matrix_path', storage_path('harness/convex_feature_matrix.csv')));
        $service = new ConvexIngestService($client, GreeksRunClient::fromConfig($cfg), $writer, $recorder, $cfg);

        $tickers = array_values(array_filter(array_map('strval', (array) $this->option('ticker')))) ?: null;

        if ($dryRun) {
            $this->warn('DRY RUN — computing + validating, matrix is NOT written.');
        }
        $this->line('matrix: '.$writer->path());

        $run = function () use ($service, $tickers, $dryRun): void {
            $results = $service->sweep($tickers, append: ! $dryRun);
            $this->renderTable($results);
        };

        if (! $loop) {
            $run();

            return self::SUCCESS;
        }

        $interval = max(5, (int) ($this->option('interval') ?? data_get($cfg, 'convex.loop_interval_seconds', 300)));
        $this->info("loop mode — sweeping every {$interval}s (Ctrl-C to stop).");
        while (true) {
            $this->line('— sweep @ '.now()->toIso8601ZuluString().' —');
            $run();
            sleep($interval);
        }
    }

    /** @param list<array<string,mixed>> $results */
    private function renderTable(array $results): void
    {
        if ($results === []) {
            $this->warn('no results — no configured tickers or no in-band expirations.');

            return;
        }

        $rows = array_map(fn (array $r): array => [
            (string) ($r['ticker'] ?? '—'),
            $r['dte'] === null ? '—' : (string) $r['dte'],
            $r['spot'] === null ? '—' : (string) $r['spot'],
            (string) ($r['verdict'] ?? ($r['block'] ?? '—')),
            $r['far_delta'] === null ? '—' : (string) $r['far_delta'],
            $r['achievable_cost_bps'] === null ? '—' : (string) $r['achievable_cost_bps'],
            $r['fill_probability'] === null ? '—' : (string) $r['fill_probability'],
            (string) ($r['n_calls'] ?? 0).'/'.(string) ($r['n_puts'] ?? 0),
            $r['max_age_s'] === null ? '—' : (string) $r['max_age_s'],
            $r['stale'] === null ? '—' : (string) $r['stale'],
            ($r['appended'] ?? false) ? 'yes' : (($r['block'] ?? null) ?: 'no'),
        ], $results);

        $this->table(
            ['ticker', 'dte', 'spot', 'verdict', 'farΔ', 'ach_bps', 'fill_p', 'c/p', 'age_s', 'stale', 'appended'],
            $rows,
        );

        $appended = count(array_filter($results, fn (array $r): bool => ($r['appended'] ?? false) === true));
        $this->info("sweep complete — {$appended}/".count($results).' rows appended.');
    }
}
