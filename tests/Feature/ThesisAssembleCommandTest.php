<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RobinhoodTradeJournal;
use App\Models\ThesisIntent;
use App\Robinhood\Thesis\ThesisLaneConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThesisAssembleCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $reviewCalls = 0;

    private int $placeCalls = 0;

    /** Strike ladder listed for the chosen expiration. */
    private array $strikes = [95.0, 100.0, 105.0];

    /** Expiration dates the chain advertises. */
    private array $expirations = ['2026-08-21', '2026-09-18', '2026-10-16', '2026-11-20'];

    /** option_id => [bid_price, ask_price] */
    private array $optionQuotes = [];

    private int $optionQuoteAgeSeconds = 2;

    private float $underlyingBid = 99.98;

    private float $underlyingAsk = 100.02;

    private int $underlyingQuoteAgeSeconds = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Pre-open: 08:45 ET on a Monday. The lane assembles here by design.
        $this->travelTo(Carbon::parse('2026-08-17 08:45:00', 'America/New_York'));

        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.thesis.enabled', true);
        config()->set('harness.thesis.max_open_positions', 3);
        config()->set('harness.thesis.per_play_max_debit', 500.0);
        config()->set('harness.thesis.per_day_max_debit', 1000.0);
        config()->set('harness.options.enabled', true);
        config()->set('harness.options.max_spread_bps', 1500.0);
        config()->set('harness.alpaca.key_id', 'alpaca-key');
        config()->set('harness.alpaca.secret_key', 'alpaca-secret');

        $this->optionQuotes = $this->defaultOptionQuotes();
    }

    public function test_assembly_derives_the_nearest_atm_contract_inside_the_dte_band(): void
    {
        $thesis = $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('assembled play')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls, 'assembly derives; it never reviews');
        $this->assertSame(0, $this->placeCalls, 'assembly never places');

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame(ThesisLaneConfig::STATUS_ASSEMBLED, $row->status);
        $this->assertSame($thesis->id, $row->thesis_intent_id);
        $this->assertSame('thesis_position', $row->strategy_label);
        $this->assertSame('C', $row->underlying_symbol);
        $this->assertSame('call', $row->option_type);
        $this->assertSame('buy', $row->side);
        $this->assertSame('open', $row->position_effect);
        $this->assertSame('1.00000000', (string) $row->quantity);

        // dte band 30-70 -> midpoint 50 -> 2026-10-16 is 60 DTE, closest.
        $this->assertSame('2026-10-16', $row->expiration_date?->toDateString());
        // spot mid 100.00 -> nearest listed strike is 100.
        $this->assertSame('100.00000000', (string) $row->strike_price);
        $this->assertSame('opt-100', $row->option_id);

        // bid 2.00 / ask 2.20 -> mid 2.10, offset 0 -> passive limit at mid.
        $this->assertSame('2.10000000', (string) $row->limit_price);
        $this->assertSame('210.00', (string) $row->dollar_amount);
        $this->assertSame(2.1, $row->notes['thesis_entry']['anchor_mid']);
        $this->assertSame('assembly_live_quote', $row->notes['thesis_entry']['anchor_source']);
        $this->assertSame('theta:thesis-assemble', $row->scanner_payload['intent_source']);
    }

    public function test_assembly_status_is_not_visible_to_the_scalp_autopilot(): void
    {
        // The scalp autopilot claims `reviewed` option rows every minute all
        // session. A thesis play must never be reachable from that query.
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereIn('status', ['reviewed', 'autopilot_claimed'])
            ->count());
    }

    public function test_assembly_is_idempotent_across_reruns(): void
    {
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')->assertExitCode(0);
        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('existing_play_open')
            ->assertExitCode(0);

        $this->assertSame(1, RobinhoodTradeJournal::query()->count());
    }

    public function test_assembly_respects_the_lane_max_open_positions(): void
    {
        config()->set('harness.thesis.max_open_positions', 1);
        $this->activeThesis('C');
        $this->activeThesis('F');
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('max_open_positions_reached')
            ->assertExitCode(0);

        $this->assertSame(1, RobinhoodTradeJournal::query()->count());
        $this->assertSame('C', RobinhoodTradeJournal::sole()->underlying_symbol);
    }

    public function test_wide_spread_skips_with_a_named_reason_and_writes_nothing(): void
    {
        config()->set('harness.options.max_spread_bps', 500.0);
        // bid 1.00 / ask 3.00 -> mid 2.00 -> 10,000 bps wide.
        $this->optionQuotes['opt-100'] = ['bid_price' => '1.000000', 'ask_price' => '3.000000'];
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('spread_above_max')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->count());
    }

    public function test_premium_above_the_play_cap_skips_with_a_named_reason(): void
    {
        // Thesis cap $150 -> a $2.20 ask ($220) is over the precommitment.
        $this->activeThesis('C', maxDebit: 150.0);
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('ask_above_max')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->count());
    }

    public function test_stale_underlying_quote_without_a_usable_book_skips(): void
    {
        $this->underlyingBid = 0.0;
        $this->underlyingAsk = 0.0;
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('underlying_quote_unusable')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->count());
    }

    public function test_pre_open_stale_option_quote_still_assembles_and_flags_the_anchor(): void
    {
        // Options do not trade pre-open. A stale quote is recorded, not fatal —
        // the executor re-anchors against the first live quote and the rails
        // still enforce the freshness gate before any money moves.
        $this->optionQuoteAgeSeconds = 60_000;
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('stale pre-open anchor')
            ->assertExitCode(0);

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame(ThesisLaneConfig::STATUS_ASSEMBLED, $row->status);
        $this->assertSame('assembly_stale_quote', $row->notes['thesis_entry']['anchor_source']);
        $this->assertTrue($row->notes['thesis_entry']['assembly_quote']['stale']);
    }

    public function test_dry_run_previews_without_writing_a_journal_row(): void
    {
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('WOULD ASSEMBLE')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->count(), 'dry-run writes no journal rows');
        $this->assertSame(0, $this->reviewCalls);
        $this->assertSame(0, $this->placeCalls);
    }

    public function test_disarmed_when_the_thesis_lane_is_disabled(): void
    {
        config()->set('harness.thesis.enabled', false);
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('thesis assembly disarmed: thesis lane disabled')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->count());
    }

    public function test_disarmed_when_the_kill_switch_is_engaged(): void
    {
        config()->set('harness.kill_switch', true);
        $this->activeThesis();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('kill switch engaged')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->count());
    }

    public function test_a_draft_thesis_is_never_assembled(): void
    {
        $this->activeThesis()->forceFill(['status' => ThesisIntent::STATUS_DRAFT])->save();
        $this->fakeDataAndBroker();

        $this->artisan('theta:thesis-assemble')
            ->expectsOutputToContain('no enterable active theses')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->count());
    }

    public function test_assembly_runs_regardless_of_harness_mode(): void
    {
        // Mode independence: the lane is approval-first by construction. The
        // scalp producer disarms under approval mode; this lane must not.
        // Http::fake merges stub callbacks, so register the transport once.
        $this->fakeDataAndBroker();

        foreach (['approval', 'auto', 'anything-else'] as $mode) {
            RobinhoodTradeJournal::query()->delete();
            ThesisIntent::query()->delete();
            config()->set('harness.mode', $mode);
            $this->activeThesis();

            $this->artisan('theta:thesis-assemble')
                ->expectsOutputToContain('assembled play')
                ->assertExitCode(0);

            $this->assertSame(1, RobinhoodTradeJournal::query()->count(), "thesis assembly must run under HARNESS_MODE={$mode}");
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function activeThesis(string $symbol = 'C', float $maxDebit = 500.0): ThesisIntent
    {
        return ThesisIntent::query()->create([
            'underlying_symbol' => $symbol,
            'direction' => ThesisIntent::DIRECTION_BULLISH,
            'structure' => ThesisIntent::STRUCTURE_LONG_CALL,
            'dte_min' => 30,
            'dte_max' => 70,
            'max_debit' => $maxDebit,
            'entry_limit_mode' => 'mid',
            'entry_limit_offset_bps' => 0.0,
            'thesis' => 'Rate-cut path supports the money-centre banks into the next print.',
            'catalyst' => 'FOMC + bank earnings window',
            'price_stop' => 88.0,
            'time_stop_date' => Carbon::parse('2026-10-01'),
            'profit_target_bps' => 6000.0,
            'status' => ThesisIntent::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
    }

    private function defaultOptionQuotes(): array
    {
        return [
            'opt-95' => ['bid_price' => '5.900000', 'ask_price' => '6.100000'],
            'opt-100' => ['bid_price' => '2.000000', 'ask_price' => '2.200000'],
            'opt-105' => ['bid_price' => '0.900000', 'ask_price' => '1.000000'],
        ];
    }

    private function fakeDataAndBroker(): void
    {
        Http::fake(function (Request $request) {
            $url = (string) $request->url();
            if (preg_match('#/v2/stocks/([^/]+)/quotes/latest#', $url) === 1) {
                return Http::response(['quote' => [
                    'bp' => $this->underlyingBid,
                    'bs' => 10,
                    'ap' => $this->underlyingAsk,
                    'as' => 12,
                    't' => now()->subSeconds($this->underlyingQuoteAgeSeconds)->toIso8601ZuluString('microsecond'),
                ]]);
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
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => array_map(
                        fn (string $name): array => ['name' => $name],
                        (array) config('harness.options.required_tools'),
                    )],
                ]);
            }
            if ($method !== 'tools/call') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
            }

            $tool = $payload['params']['name'] ?? '';
            $arguments = $payload['params']['arguments'] ?? [];

            return match ($tool) {
                'get_option_chains' => $this->toolResponse($payload['id'], ['data' => ['chains' => [[
                    'id' => 'chain-'.strtolower((string) ($arguments['underlying_symbol'] ?? 'c')),
                    'symbol' => $arguments['underlying_symbol'] ?? 'C',
                    'expiration_dates' => $this->expirations,
                    'trade_value_multiplier' => '100.0000',
                ]]]]),
                'get_option_instruments' => $this->instrumentsResponse($payload['id'], $arguments),
                'get_option_quotes' => $this->quotesResponse($payload['id'], $arguments),
                'review_option_order' => $this->review($payload['id']),
                'place_option_order' => $this->place($payload['id']),
                default => $this->toolResponse($payload['id'], ['ok' => true]),
            };
        });
    }

    private function instrumentsResponse(mixed $id, array $arguments): mixed
    {
        $symbol = strtoupper((string) ($arguments['chain_symbol'] ?? 'C'));
        $expiration = (string) ($arguments['expiration_dates'] ?? '2026-10-16');
        $type = (string) ($arguments['type'] ?? 'call');

        $strikes = $this->strikes;
        // An exact-strike filter narrows the ladder (resolveExact contract).
        if (isset($arguments['strike_price'])) {
            $wanted = (float) $arguments['strike_price'];
            $strikes = array_values(array_filter($strikes, fn (float $s): bool => abs($s - $wanted) < 0.0001));
        }

        return $this->toolResponse($id, ['data' => ['instruments' => array_map(
            fn (float $strike): array => [
                'id' => 'opt-'.(int) $strike,
                'chain_id' => 'chain-'.strtolower($symbol),
                'chain_symbol' => $symbol,
                'underlying_type' => 'equity',
                'expiration_date' => $expiration,
                'strike_price' => number_format($strike, 4, '.', ''),
                'type' => $type,
                'state' => 'active',
                'tradability' => 'tradable',
            ],
            $strikes,
        )]]);
    }

    private function quotesResponse(mixed $id, array $arguments): mixed
    {
        return $this->toolResponse($id, ['data' => ['results' => array_map(
            function (string $optionId): array {
                $quote = $this->optionQuotes[$optionId] ?? ['bid_price' => '1.000000', 'ask_price' => '1.100000'];

                return ['quote' => array_merge([
                    'instrument_id' => $optionId,
                    'mark_price' => $quote['ask_price'],
                    'updated_at' => now()->subSeconds($this->optionQuoteAgeSeconds)->toIso8601ZuluString('microsecond'),
                ], $quote)];
            },
            array_values((array) ($arguments['instrument_ids'] ?? [])),
        )]]);
    }

    private function review(mixed $id): mixed
    {
        $this->reviewCalls++;

        return $this->toolResponse($id, ['ok' => true, 'alerts' => []]);
    }

    private function place(mixed $id): mixed
    {
        $this->placeCalls++;

        return $this->toolResponse($id, ['order_id' => 'unexpected-place']);
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
