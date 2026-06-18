<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RobinhoodTradeJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptionIntentProducerCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $reviewCalls = 0;

    private int $placeCalls = 0;

    private int $quoteCalls = 0;

    private int $portfolioCalls = 0;

    private array $optionQuotes = [];

    private array $optionPositions = [];

    private array $optionStrikes = [741, 742];

    private float $buyingPower = 250.0;

    private array $barCloses = [740.80, 740.84, 740.88, 740.93, 740.98, 741.03, 741.08, 741.11, 741.16, 741.18];

    private array $barVolumes = [1000, 1050, 1100, 1125, 1175, 1200, 1250, 1300, 1325, 1400];

    private array $barsBySymbol = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-12 10:00:00', 'America/Los_Angeles'));
        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.mode', 'auto');
        config()->set('harness.options.producer.enabled', true);
        config()->set('harness.options.producer.symbols', ['SPY']);
        config()->set('harness.options.producer.expiration_min_days', 1);
        config()->set('harness.options.producer.max_chain_pages', 1);
        config()->set('harness.options.max_contract_ask', 5.0);
        config()->set('harness.options.per_play_max_debit', 75.0);
        config()->set('harness.options.per_day_max_debit', 250.0);
        config()->set('harness.options.per_underlying_max_debit', 100.0);
        config()->set('harness.options.max_open_positions', 4);
        config()->set('harness.options.producer.buying_power_reserve_debit', 0.0);
        config()->set('harness.options.producer.entry_symbol_cooldown_minutes', 0);
        config()->set('harness.alpaca.key_id', 'alpaca-key');
        config()->set('harness.alpaca.secret_key', 'alpaca-secret');
    }

    public function test_fresh_data_produces_marketable_reviewed_option_intent(): void
    {
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '4.500000', 'ask_price' => '4.550000'],
            'opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('reviewed option intent')
            ->assertExitCode(0);

        $this->assertSame(1, $this->reviewCalls);
        $this->assertSame(0, $this->placeCalls, 'producer reviews only; autopilot places later');

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('reviewed', $row->status);
        $this->assertSame('SPY', $row->underlying_symbol);
        $this->assertSame('opt-742', $row->option_id);
        $this->assertSame('742.00000000', (string) $row->strike_price);
        $this->assertSame('0.70000000', (string) $row->limit_price);
        $this->assertSame('70.00', (string) $row->dollar_amount);
        $this->assertSame('theta:option-intent-producer', $row->scanner_payload['intent_source']);
        $this->assertSame('ALLOW', $row->scanner_payload['momentum_signal']['verdict']);
        $this->assertSame('momentum_confirmed', $row->scanner_payload['momentum_signal']['reason']);
        $this->assertSame(0.70, $row->notes['rail_verdict']['intent']['limit_price']);
    }

    public function test_dynamic_universe_ranks_seed_symbols_by_live_momentum(): void
    {
        config()->set('harness.options.producer.universe_source', 'dynamic');
        config()->set('harness.options.producer.symbols', ['SPY', 'QQQ']);
        config()->set('harness.options.producer.dynamic_max_symbols', 1);
        $this->barsBySymbol = [
            'SPY' => [
                'closes' => [741.10, 741.11, 741.10, 741.11, 741.10, 741.11, 741.10, 741.11, 741.10, 741.11],
                'volumes' => $this->barVolumes,
            ],
            'QQQ' => [
                'closes' => [740.80, 740.84, 740.88, 740.93, 740.98, 741.03, 741.08, 741.11, 741.16, 741.25],
                'volumes' => $this->barVolumes,
            ],
        ];
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '4.500000', 'ask_price' => '4.550000'],
            'opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --limit=1')
            ->expectsOutputToContain('reviewed option intent')
            ->assertExitCode(0);

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('reviewed', $row->status);
        $this->assertSame('QQQ', $row->underlying_symbol);
        $this->assertSame('momentum_confirmed', $row->scanner_payload['momentum_signal']['reason']);
    }

    public function test_producer_reviews_highest_capital_velocity_candidate_first(): void
    {
        config()->set('harness.options.producer.universe_source', 'static');
        config()->set('harness.options.producer.symbols', ['SPY', 'QQQ']);
        $this->barsBySymbol = [
            'SPY' => [
                'closes' => [740.80, 740.84, 740.88, 740.93, 740.98, 741.03, 741.08, 741.11, 741.16, 741.18],
                'volumes' => $this->barVolumes,
            ],
            'QQQ' => [
                'closes' => [740.80, 740.90, 741.00, 741.12, 741.24, 741.40, 741.58, 741.74, 741.90, 742.10],
                'volumes' => [1000, 1010, 1025, 1040, 1055, 1075, 1100, 1125, 1150, 1800],
            ],
        ];
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '4.500000', 'ask_price' => '4.550000'],
            'opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --limit=1')
            ->expectsOutputToContain('QQQ: reviewed option intent')
            ->assertExitCode(0);

        $this->assertSame(1, $this->reviewCalls);

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('reviewed', $row->status);
        $this->assertSame('QQQ', $row->underlying_symbol);
        $this->assertSame('capital_velocity_v1', $row->scanner_payload['selection']['allocation']['method']);
        $this->assertEqualsWithDelta(70.0, $row->scanner_payload['selection']['allocation']['capital_required'], 0.0001);
        $this->assertGreaterThan(0.0, $row->scanner_payload['selection']['allocation']['expected_gain']);
        $this->assertGreaterThan(0.0, $row->scanner_payload['selection']['allocation']['capital_velocity_score']);
    }


    public function test_absent_momentum_records_named_block_without_broker_review(): void
    {
        $this->barCloses = [741.10, 741.11, 741.10, 741.11, 741.10, 741.11, 741.10, 741.11, 741.10, 741.11];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('momentum signal absent')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls);
        $this->assertSame(0, $this->placeCalls);
        $this->assertSame(0, RobinhoodTradeJournal::query()->where('status', 'reviewed')->count());

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('risk_aborted', $row->status);
        $this->assertStringContainsString('momentum_signal_absent', $row->notes['producer_block']['reason']);
        $this->assertSame('BLOCK', $row->scanner_payload['momentum_signal']['verdict']);
        $this->assertSame('directional_momentum_below_min', $row->scanner_payload['momentum_signal']['reason']);
    }

    public function test_cooldown_suppressed_scan_still_records_recent_activity(): void
    {
        $this->barCloses = [741.10, 741.11, 741.10, 741.11, 741.10, 741.11, 741.10, 741.11, 741.10, 741.11];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')->assertExitCode(0);
        $this->travel(1)->minute();
        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')->assertExitCode(0);

        $rows = RobinhoodTradeJournal::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('risk_aborted', $rows[0]->status);
        $this->assertSame('scan_suppressed', $rows[1]->status);
        $this->assertStringContainsString('momentum_signal_absent', $rows[1]->notes['producer_block']['reason']);
        $this->assertSame($rows[0]->id, $rows[1]->notes['producer_block']['suppressed_by_recent_block']);
        $this->assertSame('BLOCK', $rows[1]->scanner_payload['momentum_signal']['verdict']);
    }

    public function test_open_position_produces_reviewed_sell_to_close_before_new_entries(): void
    {
        config()->set('harness.options.producer.exit_max_hold_minutes', 15.0);
        $entry = RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_id' => 'opt-742',
            'chain_id' => 'chain-spy',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 742.0,
            'side' => 'buy',
            'position_effect' => 'open',
            'status' => 'filled',
            'intent' => 'long_call',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 0.63,
            'dollar_amount' => 63.00,
            'filled_quantity' => 1,
            'average_fill_price' => 0.63,
            'filled_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);
        $this->optionPositions = [[
            'option_id' => 'opt-742',
            'chain_id' => 'chain-spy',
            'underlying_symbol' => 'SPY',
            'underlying_type' => 'equity',
            'type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => '742.0000',
            'quantity' => '1',
            'average_price' => '63.0000',
            'tradability' => 'tradable',
            'state' => 'active',
        ]];
        $this->optionQuotes = [
            'opt-742' => ['bid_price' => '0.700000', 'ask_price' => '0.720000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->assertExitCode(0);

        $this->assertSame(1, $this->reviewCalls);
        $this->assertSame(0, $this->placeCalls);

        $exit = RobinhoodTradeJournal::query()->whereKeyNot($entry->id)->sole();
        $this->assertSame('reviewed', $exit->status);
        $this->assertSame('sell', $exit->side);
        $this->assertSame('close', $exit->position_effect);
        $this->assertSame('close_long_call', $exit->intent);
        $this->assertSame('0.70000000', (string) $exit->limit_price);
        $this->assertSame('theta:option-intent-producer:exit', $exit->scanner_payload['intent_source']);
        $this->assertSame('max_hold_reached', $exit->scanner_payload['exit_signal']['reason']);
    }

    public function test_exit_pass_can_close_multiple_open_positions_even_when_entry_limit_is_one(): void
    {
        config()->set('harness.options.producer.exit_max_hold_minutes', 1.0);

        foreach ([741, 742] as $strike) {
            RobinhoodTradeJournal::query()->create([
                'account_number' => (string) config('harness.agentic_account'),
                'asset_class' => 'option',
                'symbol' => 'SPY',
                'underlying_symbol' => 'SPY',
                'option_id' => 'opt-'.$strike,
                'chain_id' => 'chain-spy',
                'option_type' => 'call',
                'expiration_date' => '2026-06-15',
                'strike_price' => (float) $strike,
                'side' => 'buy',
                'position_effect' => 'open',
                'status' => 'filled',
                'intent' => 'long_call',
                'order_type' => 'limit',
                'time_in_force' => 'gfd',
                'market_hours' => 'regular_hours',
                'quantity' => 1,
                'limit_price' => 0.30,
                'dollar_amount' => 30.00,
                'filled_quantity' => 1,
                'average_fill_price' => 0.30,
                'filled_at' => now()->subMinutes($strike === 741 ? 5 : 3),
                'created_at' => now()->subMinutes($strike === 741 ? 5 : 3),
                'updated_at' => now()->subMinutes($strike === 741 ? 5 : 3),
            ]);
        }

        $this->optionPositions = [
            $this->position('opt-742', 742, openedMinutesAgo: 3),
            $this->position('opt-741', 741, openedMinutesAgo: 5),
        ];
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '0.310000', 'ask_price' => '0.320000'],
            'opt-742' => ['bid_price' => '0.310000', 'ask_price' => '0.320000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->assertExitCode(0);

        $exits = RobinhoodTradeJournal::query()
            ->where('side', 'sell')
            ->where('position_effect', 'close')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $exits);
        $this->assertSame(['opt-741', 'opt-742'], $exits->pluck('option_id')->all());
        $this->assertSame('close_long_call', $exits[0]->intent);
        $this->assertSame('max_hold_reached', $exits[0]->scanner_payload['exit_signal']['reason']);
    }

    public function test_max_hold_does_not_exit_at_negative_pnl_before_stop_loss(): void
    {
        config()->set('harness.options.producer.exit_max_hold_minutes', 1.0);
        config()->set('harness.options.producer.exit_max_hold_min_pnl_bps', 1.0);

        RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_id' => 'opt-741',
            'chain_id' => 'chain-spy',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 741.0,
            'side' => 'buy',
            'position_effect' => 'open',
            'status' => 'filled',
            'intent' => 'long_call',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 0.30,
            'dollar_amount' => 30.00,
            'filled_quantity' => 1,
            'average_fill_price' => 0.30,
            'filled_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->optionPositions = [$this->position('opt-741', 741, openedMinutesAgo: 5)];
        $this->optionStrikes = [741];
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '0.290000', 'ask_price' => '0.300000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()
            ->where('side', 'sell')
            ->where('position_effect', 'close')
            ->count());

        $block = RobinhoodTradeJournal::query()
            ->where('intent', 'producer_scan')
            ->where('underlying_symbol', 'SPY')
            ->first();

        $this->assertSame('exit_max_hold_waiting_for_min_pnl', $block->notes['producer_block']['reason']);
        $this->assertSame('max_hold_waiting_for_min_pnl', $block->scanner_payload['exit_signal']['reason']);
        $this->assertSame(-333.33, $block->scanner_payload['exit_signal']['pnl_bps']);
    }

    public function test_no_under_cap_contract_records_risk_aborted_scan_not_reviewed(): void
    {
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '4.500000', 'ask_price' => '4.550000'],
            'opt-742' => ['bid_price' => '1.000000', 'ask_price' => '1.050000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('no marketable contract')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls);
        $this->assertSame(0, RobinhoodTradeJournal::query()->where('status', 'reviewed')->count());

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('risk_aborted', $row->status);
        $this->assertStringContainsString('no_marketable_contract', $row->notes['producer_block']['reason']);
    }

    public function test_contract_selection_caps_quote_fanout_per_symbol(): void
    {
        config()->set('harness.options.producer.quote_chunk_limit', 1);
        $this->optionStrikes = range(741, 805);
        $this->optionQuotes = collect($this->optionStrikes)
            ->mapWithKeys(fn (int $strike): array => ['opt-'.$strike => ['bid_price' => '0.490000', 'ask_price' => '0.500000']])
            ->all();
        $this->buyingPower = 1.59;
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('no marketable contract')
            ->assertExitCode(0);

        $this->assertSame(1, $this->quoteCalls);
        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('risk_aborted', $row->status);
        $this->assertContains('quote_chunk_limit_reached', $row->scanner_payload['selection']['blockers']);
        $this->assertSame(1.59, $row->scanner_payload['selection']['constraints']['buying_power']);
    }

    public function test_entry_broker_context_is_reused_across_momentum_symbols(): void
    {
        config()->set('harness.options.producer.universe_source', 'static');
        config()->set('harness.options.producer.symbols', ['SPY', 'QQQ']);
        $this->barsBySymbol = [
            'SPY' => ['closes' => $this->barCloses, 'volumes' => $this->barVolumes],
            'QQQ' => ['closes' => $this->barCloses, 'volumes' => $this->barVolumes],
        ];
        $this->buyingPower = 1.59;
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '4.500000', 'ask_price' => '4.550000'],
            'opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --limit=2')
            ->expectsOutputToContain('no marketable contract')
            ->assertExitCode(0);

        $this->assertSame(1, $this->portfolioCalls, 'broker context should be assembled once per producer run, not once per symbol');
        $this->assertSame(2, RobinhoodTradeJournal::query()->where('status', 'risk_aborted')->count());
        $this->assertSame(['SPY', 'QQQ'], RobinhoodTradeJournal::query()->orderBy('id')->pluck('underlying_symbol')->all());
    }

    public function test_selection_skips_contracts_above_live_buying_power(): void
    {
        $this->buyingPower = 44.67;
        $this->optionStrikes = [742, 743];
        $this->optionQuotes = [
            'opt-742' => ['bid_price' => '0.660000', 'ask_price' => '0.670000'],
            'opt-743' => ['bid_price' => '0.420000', 'ask_price' => '0.440000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('reviewed option intent')
            ->assertExitCode(0);

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('reviewed', $row->status);
        $this->assertSame('opt-743', $row->option_id);
        $this->assertSame('0.44000000', (string) $row->limit_price);
        $this->assertSame('44.00', (string) $row->dollar_amount);
        $this->assertSame(44.67, $row->scanner_payload['selection']['constraints']['buying_power']);
        $this->assertSame(0.4467, $row->scanner_payload['selection']['constraints']['max_premium']);
    }

    public function test_selection_reserves_configured_buying_power_before_entries(): void
    {
        config()->set('harness.options.per_play_max_debit', 100.0);
        config()->set('harness.options.producer.buying_power_reserve_debit', 25.0);
        $this->buyingPower = 100.00;
        $this->optionStrikes = [742, 743];
        $this->optionQuotes = [
            'opt-742' => ['bid_price' => '0.780000', 'ask_price' => '0.800000'],
            'opt-743' => ['bid_price' => '0.720000', 'ask_price' => '0.740000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('reviewed option intent')
            ->assertExitCode(0);

        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('reviewed', $row->status);
        $this->assertSame('opt-743', $row->option_id);
        $this->assertSame('0.74000000', (string) $row->limit_price);
        $this->assertEqualsWithDelta(25.0, $row->scanner_payload['selection']['constraints']['buying_power_reserve_debit'], 0.0001);
        $this->assertSame(0.75, $row->scanner_payload['selection']['constraints']['max_premium']);
        $this->assertSame('remaining_buying_power_after_reserve', $row->scanner_payload['selection']['constraints']['max_premium_limiter']);
        $this->assertSame(0.75, $row->scanner_payload['selection']['constraints']['premium_caps']['remaining_buying_power_after_reserve']);
    }

    public function test_exhausted_buying_power_reserve_records_named_limiter_constraints(): void
    {
        config()->set('harness.options.per_play_max_debit', 100.0);
        config()->set('harness.options.producer.buying_power_reserve_debit', 25.0);
        $this->buyingPower = 25.00;
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('no marketable contract')
            ->assertExitCode(0);

        $row = RobinhoodTradeJournal::query()->where('status', 'risk_aborted')->sole();
        $selection = $row->scanner_payload['selection'];

        $this->assertContains('producer_max_premium_exhausted', $selection['blockers']);
        $this->assertContains('remaining_buying_power_after_reserve_limited', $selection['blockers']);
        $this->assertStringContainsString('producer_max_premium_exhausted', $row->notes['producer_block']['reason']);
        $this->assertEqualsWithDelta(0.0, $selection['constraints']['max_premium'], 0.0001);
        $this->assertSame('remaining_buying_power_after_reserve', $selection['constraints']['max_premium_limiter']);
        $this->assertEqualsWithDelta(0.0, $selection['constraints']['premium_caps']['remaining_buying_power_after_reserve'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $selection['constraints']['buying_power_reserve_debit'], 0.0001);
    }

    public function test_capital_independent_evaluation_selects_contract_and_defers_when_capital_short(): void
    {
        // Armed: decision-making must not depend on capital. A capital-short
        // account still evaluates the ticker, selects a contract, and records
        // execution_deferred — never reviewed, never placed, no broker review.
        config()->set('harness.options.producer.capital_independent_evaluation', true);
        $this->buyingPower = 5.00; // far below the ~$70 contract debit
        $this->optionStrikes = [742];
        $this->optionQuotes = ['opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000']];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('execution deferred (capital)')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls, 'no broker review is spent on an unfundable order');
        $this->assertSame(0, $this->placeCalls);
        $this->assertSame(0, RobinhoodTradeJournal::query()->where('status', 'reviewed')->count());

        $row = RobinhoodTradeJournal::query()->where('status', 'execution_deferred')->sole();
        $this->assertSame('SPY', $row->underlying_symbol);
        $this->assertStringContainsString('execution_deferred_capital', $row->notes['producer_block']['reason']);
        $this->assertStringContainsString('buying_power_insufficient', $row->notes['producer_block']['reason']);
        // The ticker WAS evaluated: a contract was selected despite low capital.
        $this->assertSame('opt-742', $row->scanner_payload['selection']['contract']['option_id']);
        $this->assertTrue($row->scanner_payload['selection']['constraints']['capital_independent_selection']);
    }

    public function test_deferred_decision_is_reevaluated_and_promoted_when_capital_frees(): void
    {
        // Deferred decisions are never executed stale: they are re-evaluated
        // fresh each cycle and only promote to `reviewed` once capital frees.
        config()->set('harness.options.producer.capital_independent_evaluation', true);
        config()->set('harness.options.producer.block_cooldown_minutes', 0);
        $this->optionStrikes = [742];
        $this->optionQuotes = ['opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000']];
        $this->fakeDataAndBroker();

        // Cycle 1 — capital short: evaluated, execution deferred, NOT placed.
        $this->buyingPower = 5.00;
        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')->assertExitCode(0);
        $this->assertSame(0, $this->placeCalls);
        $this->assertSame(0, $this->reviewCalls);
        $this->assertSame(1, RobinhoodTradeJournal::query()->where('status', 'execution_deferred')->count());
        $this->assertSame(0, RobinhoodTradeJournal::query()->where('status', 'reviewed')->count());

        // Cycle 2 — capital freed: fresh re-evaluation promotes to reviewed.
        $this->buyingPower = 250.00;
        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')->assertExitCode(0);
        $this->assertSame(1, $this->reviewCalls, 'a fresh review happens only once capital-ready');
        $this->assertSame(1, RobinhoodTradeJournal::query()->where('status', 'reviewed')->count());
    }

    public function test_legacy_capital_coupled_mode_blocks_capital_short_before_evaluation(): void
    {
        // Flag off (default): legacy behaviour — capital gates the candidate at
        // pre-review, recording risk_aborted (no execution_deferred rows).
        $this->buyingPower = 5.00;
        $this->optionStrikes = [742];
        $this->optionQuotes = ['opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000']];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()->where('status', 'execution_deferred')->count());
        $this->assertSame(0, RobinhoodTradeJournal::query()->where('status', 'reviewed')->count());
        $this->assertGreaterThan(0, RobinhoodTradeJournal::query()->where('status', 'risk_aborted')->count());
    }

    public function test_dry_run_previews_decisions_off_session_without_writes_or_orders(): void
    {
        // Pre-market (session closed): --dry-run must still evaluate and print
        // the decision, while writing nothing and never reviewing/placing.
        $this->travelTo(Carbon::parse('2026-06-12 05:00:00', 'America/New_York'));
        config()->set('harness.options.producer.capital_independent_evaluation', true);
        $this->optionStrikes = [742];
        $this->optionQuotes = ['opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000']];
        $this->buyingPower = 250.0;
        $this->fakeDataAndBroker();

        // "DRY RUN" printing at all proves the session gate was bypassed (the
        // idle path returns before it); "SPY:" proves the ticker was evaluated.
        $this->artisan('theta:option-intent-producer --symbols=SPY --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('SPY:')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls, 'dry-run never reviews');
        $this->assertSame(0, $this->placeCalls, 'dry-run never places');
        $this->assertSame(0, RobinhoodTradeJournal::query()->count(), 'dry-run writes no journal rows');
    }

    public function test_recent_same_symbol_entry_blocks_new_entry_before_review(): void
    {
        config()->set('harness.options.producer.entry_symbol_cooldown_minutes', 10);

        RobinhoodTradeJournal::query()->create([
            'account_number' => config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY 2026-06-15 742C',
            'underlying_symbol' => 'SPY',
            'option_id' => 'recent-opt',
            'chain_id' => 'recent-chain',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 742.0,
            'status' => 'filled',
            'intent' => 'long_call',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'quantity' => 1,
            'limit_price' => 0.32,
            'dollar_amount' => 32.00,
            'filled_at' => now()->subMinutes(4),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '4.500000', 'ask_price' => '4.550000'],
            'opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('entry symbol cooldown')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls);
        $block = RobinhoodTradeJournal::query()
            ->where('intent', 'producer_scan')
            ->where('underlying_symbol', 'SPY')
            ->sole();
        $this->assertSame('entry_symbol_cooldown', $block->notes['producer_block']['reason']);
        $this->assertSame(10, $block->scanner_payload['recent_entry']['cooldown_minutes']);
    }

    public function test_min_hold_floor_holds_discretionary_exit_inside_floor(): void
    {
        // Anti-churn: max-hold of 1 min would normally exit a 3-min-old position,
        // but the 10-min minimum-hold floor holds the discretionary exit back.
        config()->set('harness.options.producer.exit_max_hold_minutes', 1.0);
        config()->set('harness.options.producer.exit_max_hold_min_pnl_bps', 1.0);
        config()->set('harness.options.producer.exit_min_hold_minutes', 10.0);

        RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_id' => 'opt-741',
            'chain_id' => 'chain-spy',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 741.0,
            'side' => 'buy',
            'position_effect' => 'open',
            'status' => 'filled',
            'intent' => 'long_call',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 0.30,
            'dollar_amount' => 30.00,
            'filled_quantity' => 1,
            'average_fill_price' => 0.30,
            'filled_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $this->optionPositions = [$this->position('opt-741', 741, openedMinutesAgo: 3)];
        $this->optionStrikes = [741];
        // Slightly green: enough for max-hold to fire, well below the 12% target.
        $this->optionQuotes = ['opt-741' => ['bid_price' => '0.310000', 'ask_price' => '0.330000']];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->assertExitCode(0);

        $this->assertSame(0, RobinhoodTradeJournal::query()
            ->where('side', 'sell')
            ->where('position_effect', 'close')
            ->count(), 'discretionary exit must be held by the minimum-hold floor');

        $block = RobinhoodTradeJournal::query()
            ->where('intent', 'producer_scan')
            ->where('underlying_symbol', 'SPY')
            ->get()
            ->first(fn ($r) => ($r->scanner_payload['exit_signal']['reason'] ?? null) === 'min_hold_floor');

        $this->assertNotNull($block, 'expected a min-hold-floor exit block');
        $this->assertSame('exit_min_hold_floor', $block->notes['producer_block']['reason']);
        $this->assertSame('min_hold_floor', $block->scanner_payload['exit_signal']['reason']);
        $this->assertLessThan(10.0, (float) $block->scanner_payload['exit_signal']['held_minutes']);
    }

    public function test_protective_stop_loss_still_exits_inside_min_hold_floor(): void
    {
        // The minimum-hold floor must NEVER suppress a protective stop-loss: a
        // position 3 min old and deeply red must still be allowed to exit.
        config()->set('harness.options.producer.exit_min_hold_minutes', 10.0);

        RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_id' => 'opt-741',
            'chain_id' => 'chain-spy',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 741.0,
            'side' => 'buy',
            'position_effect' => 'open',
            'status' => 'filled',
            'intent' => 'long_call',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 0.30,
            'dollar_amount' => 30.00,
            'filled_quantity' => 1,
            'average_fill_price' => 0.30,
            'filled_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $this->optionPositions = [$this->position('opt-741', 741, openedMinutesAgo: 3)];
        $this->optionStrikes = [741];
        // Deeply red (−33%): past the 20% stop-loss band.
        $this->optionQuotes = ['opt-741' => ['bid_price' => '0.200000', 'ask_price' => '0.220000']];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->assertExitCode(0);

        $exit = RobinhoodTradeJournal::query()
            ->where('side', 'sell')
            ->where('position_effect', 'close')
            ->first();

        $this->assertNotNull($exit, 'protective stop-loss must fire inside the minimum-hold floor');
        $this->assertSame('reviewed', $exit->status);
        $this->assertSame('close_long_call', $exit->intent);
        $this->assertSame('stop_loss_reached', $exit->scanner_payload['exit_signal']['reason']);
    }

    public function test_recent_close_blocks_reentry_within_cooldown(): void
    {
        // Anti-churn: a position in SPY closed 5 min ago blocks a fresh open for
        // the configured 30-min re-entry cooldown (open->close->reopen guard).
        config()->set('harness.options.producer.reentry_after_exit_minutes', 30);

        RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY 2026-06-15 742C',
            'underlying_symbol' => 'SPY',
            'option_id' => 'closed-opt',
            'chain_id' => 'closed-chain',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 742.0,
            'status' => 'filled',
            'intent' => 'close_long_call',
            'side' => 'sell',
            'position_effect' => 'close',
            'order_type' => 'limit',
            'quantity' => 1,
            'limit_price' => 0.40,
            'filled_quantity' => 1,
            'average_fill_price' => 0.40,
            'filled_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '4.500000', 'ask_price' => '4.550000'],
            'opt-742' => ['bid_price' => '0.680000', 'ask_price' => '0.700000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('re-entry after exit cooldown')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls);
        $block = RobinhoodTradeJournal::query()
            ->where('intent', 'producer_scan')
            ->where('underlying_symbol', 'SPY')
            ->sole();
        $this->assertSame('reentry_after_exit_cooldown', $block->notes['producer_block']['reason']);
        $this->assertSame(30, $block->scanner_payload['recent_exit']['cooldown_minutes']);
    }

    public function test_min_contract_ask_filters_sub_floor_contracts(): void
    {
        // Premium floor: contracts cheaper than the floor are where a 1-2c spread
        // and the percentage stop live inside the noise band — skip them.
        config()->set('harness.options.min_contract_ask', 0.50);
        $this->optionQuotes = [
            'opt-741' => ['bid_price' => '0.380000', 'ask_price' => '0.400000'],
            'opt-742' => ['bid_price' => '0.300000', 'ask_price' => '0.320000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('no marketable contract')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reviewCalls);
        $row = RobinhoodTradeJournal::sole();
        $this->assertSame('risk_aborted', $row->status);
        $this->assertContains('ask_below_min_premium', $row->scanner_payload['selection']['blockers']);
    }

    public function test_held_position_exit_block_reason_is_not_double_prefixed(): void
    {
        // Regression: 'exit_thresholds_not_met' already carries the exit_ prefix,
        // so the block reason must not become 'exit_exit_thresholds_not_met'.
        config()->set('harness.options.producer.exit_max_hold_minutes', 15.0);
        RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY',
            'underlying_symbol' => 'SPY',
            'option_id' => 'opt-741',
            'chain_id' => 'chain-spy',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 741.0,
            'side' => 'buy',
            'position_effect' => 'open',
            'status' => 'filled',
            'intent' => 'long_call',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 0.30,
            'dollar_amount' => 30.00,
            'filled_quantity' => 1,
            'average_fill_price' => 0.30,
            'filled_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->optionPositions = [$this->position('opt-741', 741, openedMinutesAgo: 5)];
        $this->optionStrikes = [741];
        // +3.3%: below the 12% target, above the -20% stop, held < max → no exit.
        $this->optionQuotes = ['opt-741' => ['bid_price' => '0.310000', 'ask_price' => '0.330000']];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->assertExitCode(0);

        $block = RobinhoodTradeJournal::query()
            ->where('intent', 'producer_scan')
            ->where('underlying_symbol', 'SPY')
            ->get()
            ->first(fn ($r) => ($r->scanner_payload['exit_signal']['reason'] ?? null) === 'exit_thresholds_not_met');

        $this->assertNotNull($block, 'expected a holding (thresholds-not-met) exit block');
        $this->assertSame('exit_thresholds_not_met', $block->notes['producer_block']['reason']);
    }

    public function test_selection_skips_contracts_above_remaining_daily_gross_budget_after_top_off(): void
    {
        config()->set('harness.options.daily_gross_entry_debit', 250.0);

        RobinhoodTradeJournal::query()->create([
            'account_number' => config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY 2026-06-15 741C',
            'underlying_symbol' => 'SPY',
            'option_id' => 'spent-opt',
            'chain_id' => 'spent-chain',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 741.0,
            'status' => 'filled',
            'intent' => 'long_call',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'quantity' => 1,
            'limit_price' => 2.40,
            'dollar_amount' => 240.00,
            'filled_at' => now(),
        ]);

        $this->buyingPower = 1000.00;
        $this->optionStrikes = [742, 743];
        $this->optionQuotes = [
            'opt-742' => ['bid_price' => '0.660000', 'ask_price' => '0.670000'],
            'opt-743' => ['bid_price' => '0.080000', 'ask_price' => '0.090000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('reviewed option intent')
            ->assertExitCode(0);

        $row = RobinhoodTradeJournal::query()->where('status', 'reviewed')->sole();
        $this->assertSame('opt-743', $row->option_id);
        $this->assertSame('0.09000000', (string) $row->limit_price);
        $this->assertSame('9.00', (string) $row->dollar_amount);
        $this->assertEqualsWithDelta(240.0, $row->scanner_payload['selection']['constraints']['options_spent_today_debit'], 0.0001);
        $this->assertEqualsWithDelta(250.0, $row->scanner_payload['selection']['constraints']['daily_gross_entry_debit'], 0.0001);
        $this->assertSame(0.1, $row->scanner_payload['selection']['constraints']['max_premium']);
        $this->assertSame('remaining_daily_gross_entry_debit', $row->scanner_payload['selection']['constraints']['max_premium_limiter']);
        $this->assertSame(0.1, $row->scanner_payload['selection']['constraints']['premium_caps']['remaining_daily_gross_entry_debit']);
    }

    public function test_daily_gross_budget_limiter_is_named_when_all_contracts_are_above_remaining_budget(): void
    {
        config()->set('harness.options.daily_gross_entry_debit', 250.0);

        RobinhoodTradeJournal::query()->create([
            'account_number' => config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'SPY 2026-06-15 741C',
            'underlying_symbol' => 'SPY',
            'option_id' => 'spent-opt',
            'chain_id' => 'spent-chain',
            'option_type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => 741.0,
            'status' => 'filled',
            'intent' => 'long_call',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'quantity' => 1,
            'limit_price' => 2.40,
            'dollar_amount' => 240.00,
            'filled_at' => now(),
        ]);

        $this->buyingPower = 1000.00;
        $this->optionStrikes = [742, 743];
        $this->optionQuotes = [
            'opt-742' => ['bid_price' => '0.660000', 'ask_price' => '0.670000'],
            'opt-743' => ['bid_price' => '0.100000', 'ask_price' => '0.110000'],
        ];
        $this->fakeDataAndBroker();

        $this->artisan('theta:option-intent-producer --symbols=SPY --limit=1')
            ->expectsOutputToContain('no marketable contract')
            ->assertExitCode(0);

        $row = RobinhoodTradeJournal::query()->where('status', 'risk_aborted')->sole();
        $this->assertContains('remaining_daily_gross_entry_debit_limited', $row->scanner_payload['selection']['blockers']);
        $this->assertStringContainsString('remaining_daily_gross_entry_debit_limited', $row->notes['producer_block']['reason']);
        $this->assertSame('remaining_daily_gross_entry_debit', $row->scanner_payload['selection']['constraints']['max_premium_limiter']);
        $this->assertSame(0.1, $row->scanner_payload['selection']['constraints']['premium_caps']['remaining_daily_gross_entry_debit']);
    }

    private function fakeDataAndBroker(): void
    {
        Http::fake(function (Request $request) {
            $url = (string) $request->url();
            $symbol = $this->symbolFromAlpacaUrl($url);
            if ($symbol !== null && str_contains($url, "/v2/stocks/{$symbol}/quotes/latest")) {
                return Http::response(['quote' => [
                    'bp' => 741.14,
                    'bs' => 10,
                    'ap' => 741.15,
                    'as' => 12,
                    't' => now()->subSecond()->toIso8601ZuluString('microsecond'),
                ]]);
            }
            if ($symbol !== null && str_contains($url, "/v2/stocks/{$symbol}/bars/latest")) {
                $bars = $this->bars($symbol);
                $latest = $bars[array_key_last($bars)];

                return Http::response(['bar' => $latest]);
            }
            if ($symbol !== null && str_contains($url, "/v2/stocks/{$symbol}/bars")) {
                return Http::response(['bars' => $this->bars($symbol)]);
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
                    'id' => 'chain-spy',
                    'symbol' => $arguments['underlying_symbol'] ?? $arguments['chain_symbol'] ?? 'SPY',
                    'expiration_dates' => ['2026-06-12', '2026-06-15'],
                    'trade_value_multiplier' => '100.0000',
                ]]]]),
                'get_option_instruments' => $this->toolResponse($payload['id'], ['data' => ['instruments' => [
                    ...array_map(fn (int $strike): array => $this->instrument(
                        'opt-'.$strike,
                        $strike,
                        (string) ($arguments['chain_symbol'] ?? 'SPY'),
                    ), $this->optionStrikes),
                ]]]),
                'get_option_quotes' => $this->toolResponse($payload['id'], [
                    'data' => ['results' => array_map(
                        fn (string $id): array => ['quote' => array_merge([
                            'instrument_id' => $id,
                            'mark_price' => $this->optionQuotes[$id]['ask_price'],
                            'updated_at' => now()->subSeconds(2)->toIso8601ZuluString('microsecond'),
                        ], $this->optionQuotes[$id])],
                        $arguments['instrument_ids'],
                    )],
                    'call' => ++$this->quoteCalls,
                ]),
                'get_portfolio' => $this->toolResponse($payload['id'], ['data' => [
                    'cash' => '250.00',
                    'buying_power' => ['buying_power' => (string) $this->buyingPower],
                    'call' => ++$this->portfolioCalls,
                ]]),
                'get_equity_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => []]]),
                'get_option_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => []]]),
                'get_option_positions' => $this->toolResponse($payload['id'], ['data' => ['positions' => $this->optionPositions]]),
                'get_accounts' => $this->toolResponse($payload['id'], ['data' => ['accounts' => [[
                    'account_number' => config('harness.agentic_account'),
                    'type' => 'cash',
                    'option_level' => 'option_level_2',
                    'agentic_allowed' => true,
                ]]]]),
                'review_option_order' => $this->review($payload['id']),
                'place_option_order' => $this->place($payload['id']),
                default => $this->toolResponse($payload['id'], ['ok' => true]),
            };
        });
    }

    private function instrument(string $id, int $strike, string $symbol = 'SPY'): array
    {
        return [
            'id' => $id,
            'chain_id' => 'chain-spy',
            'chain_symbol' => strtoupper($symbol),
            'underlying_type' => 'equity',
            'expiration_date' => '2026-06-15',
            'strike_price' => number_format($strike, 4, '.', ''),
            'type' => 'call',
            'state' => 'active',
            'tradability' => 'tradable',
        ];
    }

    private function position(string $optionId, int $strike, int $openedMinutesAgo): array
    {
        return [
            'option_id' => $optionId,
            'chain_id' => 'chain-spy',
            'underlying_symbol' => 'SPY',
            'underlying_type' => 'equity',
            'type' => 'call',
            'expiration_date' => '2026-06-15',
            'strike_price' => number_format($strike, 4, '.', ''),
            'quantity' => '1',
            'average_price' => '30.0000',
            'tradability' => 'tradable',
            'state' => 'active',
            'opened_at' => now()->subMinutes($openedMinutesAgo)->toIso8601String(),
        ];
    }

    private function bars(string $symbol = 'SPY'): array
    {
        $seed = $this->barsBySymbol[strtoupper($symbol)] ?? null;
        $closes = $seed['closes'] ?? $this->barCloses;
        $volumes = $seed['volumes'] ?? $this->barVolumes;
        $bars = [];
        foreach ($closes as $index => $close) {
            $bars[] = [
                'o' => $close - 0.02,
                'h' => $close + 0.04,
                'l' => $close - 0.04,
                'c' => $close,
                'v' => $volumes[$index] ?? 1000,
                't' => now()->subMinutes(count($closes) - $index - 1)->toIso8601ZuluString('microsecond'),
            ];
        }

        return $bars;
    }

    private function symbolFromAlpacaUrl(string $url): ?string
    {
        if (preg_match('#/v2/stocks/([^/]+)/(?:quotes|bars)#', $url, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function review(mixed $id)
    {
        $this->reviewCalls++;

        return $this->toolResponse($id, ['ok' => true, 'alerts' => []]);
    }

    private function place(mixed $id)
    {
        $this->placeCalls++;

        return $this->toolResponse($id, ['order_id' => 'unexpected-place']);
    }

    private function toolResponse(mixed $id, array $body)
    {
        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['isError' => false, 'content' => [['type' => 'text', 'text' => json_encode($body)]]],
        ]);
    }
}
