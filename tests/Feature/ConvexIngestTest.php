<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wiring + config-propagation coverage for the read-only convex data plane.
 * The broker MCP and the greeks /run/* math service are both faked; the sealed
 * python math is exercised separately in-container. These tests pin the PHP
 * assembly: DTE-band selection, the clean-row (schema) gate, dry-run, the
 * default-off scheduler arm, and the null outcome-column placeholders.
 */
class ConvexIngestTest extends TestCase
{
    private string $matrixPath;

    private bool $schemaOk = true;

    private int $equityQuoteCalls = 0;

    private int $optionQuoteCalls = 0;

    private array $sweptTickers = [];

    private bool $instrumentCursorFollowed = false;

    private float $maxQuotedStrike = 0.0;

    private array $matrixRepegCaps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-07 10:00:00', 'America/New_York'));
        $this->matrixPath = sys_get_temp_dir().'/convex_matrix_'.uniqid().'.csv';
        @unlink($this->matrixPath);

        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.convex.enabled', true);
        config()->set('harness.convex.tickers', ['SPY']);
        config()->set('harness.convex.dte_floor', 45);
        config()->set('harness.convex.dte_ceiling', 90);
        config()->set('harness.convex.tenors_per_ticker', 2);
        config()->set('harness.convex.matrix_path', $this->matrixPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->matrixPath);
        parent::tearDown();
    }

    public function test_sweep_appends_only_in_band_tenors_capped_with_null_outcomes(): void
    {
        config()->set('harness.convex.entry_repeg_cap_frac', 0.25);
        $this->fakeBrokerAndGreeks();

        $this->artisan('theta:convex-ingest --once')
            ->expectsOutputToContain('rows appended')
            ->assertExitCode(0);

        $this->assertFileExists($this->matrixPath);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->matrixPath)), fn ($l) => $l !== ''));
        $header = str_getcsv($lines[0]);

        // Header is the sealed 41-column contract, outcome columns last.
        $this->assertCount(41, $header);
        $this->assertSame('ticker', $header[0]);
        $this->assertContains('verdict', $header);
        $this->assertSame('o_filled', $header[34]);
        $this->assertSame('o_closed_ts', $header[40]);

        // Two in-band tenors only (expirations at +30 and +120 excluded; +75
        // dropped by the tenors_per_ticker=2 cap, nearest-first).
        $data = array_slice($lines, 1);
        $this->assertCount(2, $data, 'only the two nearest in-band tenors should be appended');

        $col = array_flip($header);
        foreach ($data as $line) {
            $cells = str_getcsv($line);
            $this->assertSame('SPY', $cells[$col['ticker']]);
            $dte = (int) $cells[$col['dte']];
            $this->assertGreaterThanOrEqual(45, $dte);
            $this->assertLessThanOrEqual(90, $dte);
            $this->assertSame('ADMIT', $cells[$col['verdict']]);
            // Reserved outcome columns are empty (schema null / pandas NaN), never a literal token.
            foreach (['o_filled', 'o_entry_fill_px', 'o_pnl_pct', 'o_exit_reason', 'o_closed_ts'] as $o) {
                $this->assertSame('', $cells[$col[$o]], "{$o} must be an empty placeholder");
            }
        }

        $dtes = array_map(fn ($line) => (int) str_getcsv($line)[$col['dte']], $data);
        sort($dtes);
        $this->assertSame([50, 60], $dtes, 'nearest-first in-band tenors');
        $this->assertSame(['SPY'], array_values(array_unique($this->sweptTickers)));

        // The call wing lives on page 2 (above spot): the service must follow
        // the instrument cursor and actually quote above-spot strikes.
        $this->assertTrue($this->instrumentCursorFollowed, 'service must paginate instruments past page 1');
        $this->assertGreaterThan(660.0, $this->maxQuotedStrike, 'the above-spot call wing must be quoted');
        $this->assertSame([0.25, 0.25], $this->matrixRepegCaps,
            'the matrix math must receive the same entry cap as the live order rail');
    }

    public function test_schema_drift_row_is_surfaced_not_appended(): void
    {
        $this->schemaOk = false;
        $this->fakeBrokerAndGreeks();

        $this->artisan('theta:convex-ingest --once')
            ->expectsOutputToContain('0/2 rows appended')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->matrixPath);
    }

    public function test_dry_run_computes_but_writes_nothing(): void
    {
        $this->fakeBrokerAndGreeks();

        $this->artisan('theta:convex-ingest --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->matrixPath);
        $this->assertGreaterThan(0, $this->optionQuoteCalls, 'dry-run still reads live quotes');
    }

    public function test_disarmed_scheduler_run_does_not_touch_broker(): void
    {
        config()->set('harness.convex.enabled', false);
        $this->fakeBrokerAndGreeks();

        $this->artisan('theta:convex-ingest')
            ->expectsOutputToContain('disarmed')
            ->assertExitCode(0);

        $this->assertSame(0, $this->equityQuoteCalls, 'a disarmed scheduled run must make no broker calls');
        $this->assertFileDoesNotExist($this->matrixPath);
    }

    private function fakeBrokerAndGreeks(): void
    {
        Http::fake(function (Request $request) {
            $url = (string) $request->url();
            if (str_contains($url, '/run/')) {
                return $this->fakeGreeks($url, (array) $request->data());
            }

            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => []]);
            }
            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }
            if ($method === 'tools/list') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => ['tools' => array_map(
                    fn (string $n): array => ['name' => $n],
                    ['get_equity_quotes', 'get_option_chains', 'get_option_instruments', 'get_option_quotes'],
                )]]);
            }
            if ($method !== 'tools/call') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
            }

            $tool = $payload['params']['name'] ?? '';
            $args = $payload['params']['arguments'] ?? [];

            return match ($tool) {
                'get_equity_quotes' => $this->equityQuotes($payload['id'], $args),
                'get_option_chains' => $this->chains($payload['id'], $args),
                'get_option_instruments' => $this->instruments($payload['id'], $args),
                'get_option_quotes' => $this->optionQuotes($payload['id'], $args),
                default => $this->toolResponse($payload['id'], ['ok' => true]),
            };
        });
    }

    private function fakeGreeks(string $url, array $body)
    {
        $script = basename(parse_url($url, PHP_URL_PATH) ?: '');
        $args = (array) ($body['args'] ?? []);

        if ($script === 'convex_feature_row') {
            if (in_array('csv-header', $args, true)) {
                return Http::response(['ok' => true, 'script' => $script, 'stdout_json' => null, 'stdout_raw' => implode(',', $this->featureColumns())]);
            }

            $this->matrixRepegCaps[] = (float) $this->argValue($args, '--repeg-cap-frac');

            $row = $this->featureRow(
                (string) $this->argValue($args, '--ticker'),
                (string) $this->argValue($args, '--bucket'),
                (float) $this->argValue($args, '--spot'),
                (int) $this->argValue($args, '--dte'),
            );

            return Http::response(['ok' => true, 'script' => $script, 'stdout_json' => $row, 'stdout_raw' => null]);
        }

        if ($script === 'convex_feature_schema') {
            return Http::response([
                'ok' => $this->schemaOk,
                'script' => $script,
                'stdout_json' => ['ok' => $this->schemaOk, 'violations' => $this->schemaOk ? [] : ['forced_drift']],
                'stdout_raw' => null,
            ]);
        }

        return Http::response(['ok' => false, 'script' => $script, 'stdout_json' => null, 'stdout_raw' => '']);
    }

    private function equityQuotes(mixed $id, array $args): mixed
    {
        $this->equityQuoteCalls++;
        $symbols = (array) ($args['symbols'] ?? ['SPY']);
        $results = array_map(fn (string $s): array => ['quote' => [
            'symbol' => strtoupper($s),
            'last_trade_price' => '660.0000',
            'state' => 'active',
            'has_traded' => true,
        ]], $symbols);

        return $this->toolResponse($id, ['data' => ['results' => $results]]);
    }

    private function chains(mixed $id, array $args): mixed
    {
        $this->sweptTickers[] = strtoupper((string) ($args['underlying_symbol'] ?? 'SPY'));
        $exp = fn (int $d): string => now()->addDays($d)->toDateString();

        return $this->toolResponse($id, ['data' => ['chains' => [[
            'id' => 'chain-spy',
            'symbol' => $args['underlying_symbol'] ?? 'SPY',
            'expiration_dates' => [$exp(30), $exp(50), $exp(60), $exp(75), $exp(120)],
            'trade_value_multiplier' => '100.0000',
        ]]]]);
    }

    private function instruments(mixed $id, array $args): mixed
    {
        $type = (string) ($args['type'] ?? 'call');
        $expiration = (string) ($args['expiration_dates'] ?? now()->addDays(50)->toDateString());
        $cursor = $args['cursor'] ?? null;

        // Spot is 660. Page 1 is strikes BELOW spot (the call wing is not here);
        // page 2 spans spot. A service that does not follow `next` sees no call
        // wing at all — the exact pagination bug this guards.
        if ($cursor === null) {
            return $this->toolResponse($id, ['data' => [
                'instruments' => $this->instrumentRows($type, range(500, 650, 5), $expiration),
                'next' => 'https://api.example/options/instruments/?cursor=PAGE2',
            ]]);
        }

        $this->instrumentCursorFollowed = true;

        return $this->toolResponse($id, ['data' => [
            'instruments' => $this->instrumentRows($type, range(655, 820, 5), $expiration),
        ]]);
    }

    /** @param list<int> $strikes */
    private function instrumentRows(string $type, array $strikes, string $expiration): array
    {
        return array_map(fn (int $strike): array => [
            'id' => $type[0].'-'.$strike.'-'.$expiration,
            'chain_id' => 'chain-spy',
            'expiration_date' => $expiration,
            'strike_price' => number_format($strike, 4, '.', ''),
            'type' => $type,
            'state' => 'active',
            'tradability' => 'tradable',
        ], $strikes);
    }

    private function optionQuotes(mixed $id, array $args): mixed
    {
        $this->optionQuoteCalls++;
        $results = [];
        foreach ((array) ($args['instrument_ids'] ?? []) as $iid) {
            $strike = (float) explode('-', (string) $iid)[1];
            $this->maxQuotedStrike = max($this->maxQuotedStrike, $strike);
            $results[] = ['quote' => [
                'instrument_id' => $iid,
                'bid_price' => '1.100000',
                'ask_price' => '1.200000',
                'delta' => '0.2000',
                'implied_volatility' => '0.1500',
                'gamma' => '0.0100',
                'volume' => 500,
                'open_interest' => 2500,
                'updated_at' => now()->subSeconds(3)->toIso8601ZuluString('microsecond'),
            ]];
        }

        return $this->toolResponse($id, ['data' => ['results' => $results]]);
    }

    private function argValue(array $args, string $flag): mixed
    {
        $idx = array_search($flag, $args, true);

        return $idx === false ? null : ($args[$idx + 1] ?? null);
    }

    /** @return list<string> the sealed 41-column FEATURE_COLUMNS order */
    private function featureColumns(): array
    {
        return [
            'ticker', 'bucket', 'snapshot_ts', 'agent_id', 'policy_version', 'source', 'spot', 'dte',
            'atm_iv', 'realized_vol', 'iv_rv_ratio', 'rr25', 'fringe_richness', 'quiet_dispersion',
            'max_quotable_sigma', 'n_quotable_past_2sigma', 'wing_slope', 'vega_class',
            'far_strike', 'far_delta', 'quoted_spread_bps', 'achievable_cost_bps', 'fill_probability', 'availability',
            'anchor_strike', 'anchor_delta_accel', 'fringe_strike', 'convex_asymmetry', 'convex_accel', 'flip_move',
            'verdict', 'failed_signals', 'pending_signals', 'diagnostics',
            'o_filled', 'o_entry_fill_px', 'o_exit_px', 'o_pnl_pct', 'o_underlying_move_pct', 'o_exit_reason', 'o_closed_ts',
        ];
    }

    /** @return array<string,mixed> a schema-valid ADMIT row echoing the args */
    private function featureRow(string $ticker, string $bucket, float $spot, int $dte): array
    {
        $row = [
            'ticker' => $ticker, 'bucket' => $bucket, 'snapshot_ts' => now()->toIso8601ZuluString(),
            'agent_id' => 'claude/theta-fringe', 'policy_version' => 'convex-wrapper-napkin-v1', 'source' => 'robinhood_mcp',
            'spot' => $spot, 'dte' => $dte,
            'atm_iv' => 0.15, 'realized_vol' => null, 'iv_rv_ratio' => null, 'rr25' => -0.02,
            'fringe_richness' => -0.01, 'quiet_dispersion' => 0.02,
            'max_quotable_sigma' => 2.4, 'n_quotable_past_2sigma' => 3, 'wing_slope' => -0.1, 'vega_class' => 'TAILWIND',
            'far_strike' => round($spot * 1.05, 2), 'far_delta' => 0.2, 'quoted_spread_bps' => 120.0,
            'achievable_cost_bps' => 35.0, 'fill_probability' => 0.9, 'availability' => 0.8,
            'anchor_strike' => round($spot * 1.02, 2), 'anchor_delta_accel' => 0.03, 'fringe_strike' => round($spot * 1.1, 2),
            'convex_asymmetry' => 3.1, 'convex_accel' => 1.4, 'flip_move' => 0.01,
            'verdict' => 'ADMIT', 'failed_signals' => '', 'pending_signals' => '', 'diagnostics' => 'iv_rv_ratio|no_catalyst',
        ];
        foreach (['o_filled', 'o_entry_fill_px', 'o_exit_px', 'o_pnl_pct', 'o_underlying_move_pct', 'o_exit_reason', 'o_closed_ts'] as $o) {
            $row[$o] = null;
        }

        return $row;
    }

    private function toolResponse(mixed $id, array $body): mixed
    {
        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['isError' => false, 'content' => [['type' => 'text', 'text' => json_encode($body)]]],
        ]);
    }
}
