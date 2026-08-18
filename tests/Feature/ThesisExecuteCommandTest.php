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
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ThesisExecuteCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $reviewCalls = 0;

    private int $placeCalls = 0;

    private int $cancelCalls = 0;

    private array $lastPlaceArguments = [];

    /** @var list<array<string,mixed>> broker option orders, mutated by the fake */
    private array $brokerOptionOrders = [];

    /** State an order takes once cancel_option_order is called against it. */
    private string $stateAfterCancel = 'cancelled';

    private bool $advertiseCancelTool = true;

    /** Blocking alerts the fake review returns; empty means a clean review. */
    private array $reviewAlerts = [];

    /** Make place_option_order fail at the transport, i.e. outcome unknown. */
    private bool $failPlaceWithTransportError = false;

    /** Simulate slow broker I/O by advancing the clock during get_portfolio. */
    private ?Carbon $travelToOnPortfolioRead = null;

    private string $quoteBid = '2.000000';

    private string $quoteAsk = '2.200000';

    private int $optionQuoteAgeSeconds = 2;

    private float $cash = 1000.0;

    protected function setUp(): void
    {
        parent::setUp();

        // 09:35 ET — inside the default 09:30-10:15 entry window.
        $this->travelTo(Carbon::parse('2026-08-17 09:35:00', 'America/New_York'));
        // The cancel verifier polls with real delays in production.
        Sleep::fake();

        config()->set('harness.oauth_token', 'fixture-token');
        config()->set('harness.kill_switch', false);
        config()->set('harness.thesis.enabled', true);
        config()->set('harness.thesis.max_open_positions', 3);
        config()->set('harness.thesis.per_play_max_debit', 500.0);
        config()->set('harness.thesis.per_day_max_debit', 1000.0);
        config()->set('harness.thesis.entry.window_close_et', '10:15');
        config()->set('harness.thesis.entry.reprice_seconds', 45);
        config()->set('harness.thesis.entry.max_chase_bps', 150.0);
        config()->set('harness.options.enabled', true);
        config()->set('harness.options.max_spread_bps', 1500.0);
    }

    public function test_places_the_assembled_play_as_a_worked_limit_inside_the_book(): void
    {
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('placed')
            ->assertExitCode(0);

        $this->assertSame(1, $this->reviewCalls);
        $this->assertSame(1, $this->placeCalls);
        // bid 2.00 / ask 2.20 -> mid 2.10. The thesis lane works the mid; it
        // does not lift the ask the way the scalp lane does.
        $this->assertSame('2.10', $this->lastPlaceArguments['price']);

        $row->refresh();
        $this->assertSame('submitted', $row->status);
        $this->assertSame('thesis_position', $row->strategy_label, 'the shared executor must not relabel a thesis play');
        $this->assertSame('broker-order-1', $row->broker_order_id);
        $this->assertSame('2.10000000', (string) $row->limit_price);
        $this->assertCount(1, $row->notes['thesis_entry']['placements']);
        $this->assertSame('placed', $row->notes['thesis_entry']['placements'][0]['event']);
    }

    public function test_repegs_after_the_reprice_window_and_stops_at_the_chase_cap(): void
    {
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame('2.10', $this->lastPlaceArguments['price']);

        // The book walks up while we rest. anchor 2.10 + 150 bps = 2.1315,
        // so the re-peg tops out at 2.13 even though the mid is now 2.15.
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('repegged')
            ->assertExitCode(0);

        $this->assertSame(1, $this->cancelCalls);
        $this->assertSame(2, $this->placeCalls);
        $this->assertSame('2.13', $this->lastPlaceArguments['price']);

        $row->refresh();
        $this->assertSame('submitted', $row->status);
        $this->assertSame('broker-order-2', $row->broker_order_id);
        $this->assertSame('2.13000000', (string) $row->limit_price);
        $this->assertCount(2, $row->notes['thesis_entry']['placements']);
        $this->assertSame('repegged', $row->notes['thesis_entry']['placements'][1]['event']);
        // A fresh persisted ref per re-peg — never a reused idempotency key.
        $this->assertNotSame(
            $row->notes['thesis_entry']['placements'][0]['client_ref_id'],
            $row->notes['thesis_entry']['placements'][1]['client_ref_id'],
        );
        $this->assertSame('cancelled', $this->brokerOptionOrders[0]['state']);
    }

    public function test_inside_the_reprice_window_the_resting_order_is_left_alone(): void
    {
        $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->travel(30)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(0, $this->cancelCalls);
        $this->assertSame(1, $this->placeCalls);
    }

    public function test_refuses_to_replace_when_the_cancel_is_unconfirmed(): void
    {
        // The broker still reports the order as queued after the cancel. Two
        // live orders on one play is never an acceptable trade for one entry.
        $row = $this->assembledPlay();
        $this->stateAfterCancel = 'queued';
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('cancel_unconfirmed')
            ->assertExitCode(0);

        $this->assertSame(1, $this->cancelCalls);
        $this->assertSame(1, $this->placeCalls, 'no second order may exist while the first is unconfirmed');

        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, $row->status);
        $this->assertSame('cancel_unconfirmed', $row->notes['thesis_entry']['blocked']['reason']);
        $this->assertSame('order_still_queued', $row->notes['thesis_entry']['blocked']['detail']);
        $this->assertSame('broker-order-1', $row->notes['thesis_entry']['blocked']['broker_order_id']);
    }

    public function test_a_blocked_play_keeps_being_supervised_until_broker_truth_clears_it(): void
    {
        // "Blocked" means stop re-pegging, never stop watching. The order is
        // still live gfd — abandoning it lets it fill at 15:59 on a play the
        // no-chase rule already expired.
        $row = $this->assembledPlay();
        $this->stateAfterCancel = 'queued';
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';
        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(1, $this->cancelCalls);
        $this->assertSame(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, $row->refresh()->status);

        // Next tick: still blocked, still supervised, cancel retried, and the
        // lane still refuses to place a second order.
        $this->travel(60)->seconds();
        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('blocked with a live order')
            ->assertExitCode(0);

        $this->assertSame(2, $this->cancelCalls, 'a blocked play with a live order must keep being cancelled');
        $this->assertSame(1, $this->placeCalls, 'a blocked play never re-places');
        $this->assertSame(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, $row->refresh()->status);

        // Broker truth finally confirms the cancel: only now does the play
        // leave the lane.
        $this->stateAfterCancel = 'cancelled';
        $this->travel(60)->seconds();
        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_EXPIRED_UNFILLED, $row->status);
        $this->assertSame('blocked_order_cancel_retry_confirmed', $row->notes['thesis_entry']['expired']['reason']);
    }

    public function test_a_blocked_play_is_still_swept_after_the_window_closes(): void
    {
        $row = $this->assembledPlay();
        $this->stateAfterCancel = 'queued';
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';
        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, $row->refresh()->status);

        // Past the close the blocked row must still be reachable by the sweep.
        $this->travelTo(Carbon::parse('2026-08-17 10:16:00', 'America/New_York'));
        $this->stateAfterCancel = 'cancelled';

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(2, $this->cancelCalls);
        $this->assertSame(ThesisLaneConfig::STATUS_EXPIRED_UNFILLED, $row->refresh()->status);
    }

    public function test_a_concurrent_invocation_cannot_double_place_during_a_repeg(): void
    {
        // withoutOverlapping only guards the scheduler against itself. A manual
        // run racing a scheduled tick must not both cancel order A and both
        // place — the second cancel of an already-cancelled order reads back
        // `cancelled`, which would otherwise look like clear-to-replace.
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame(1, $this->placeCalls);

        // Simulate the other invocation already inside the critical section.
        $row->refresh()->forceFill(['status' => ThesisLaneConfig::STATUS_REPEGGING])->save();
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(0, $this->cancelCalls, 'the second invocation must not cancel a re-peg in flight');
        $this->assertSame(1, $this->placeCalls, 'the second invocation must not place a second order');
        $this->assertSame(ThesisLaneConfig::STATUS_REPEGGING, $row->refresh()->status);
    }

    public function test_a_repeg_back_to_a_previously_used_price_still_mints_a_fresh_ref(): void
    {
        // intentKey hashes the limit price, so without a per-attempt seed the
        // ref for a repeated price comes back `placed`, the duplicate gate
        // aborts, and the play dies with its old order already cancelled.
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame('2.10', $this->lastPlaceArguments['price']);

        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';
        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame('2.13', $this->lastPlaceArguments['price']);

        // Book comes back: the limit returns to a price already used today.
        $this->travel(60)->seconds();
        $this->quoteBid = '2.000000';
        $this->quoteAsk = '2.200000';
        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(3, $this->placeCalls);
        $this->assertSame('2.10', $this->lastPlaceArguments['price']);
        $this->assertSame('submitted', $row->refresh()->status);
        $this->assertSame(3, $row->notes['thesis_entry']['attempt_seq']);
    }

    public function test_a_rail_block_after_a_confirmed_cancel_returns_the_play_to_placeable(): void
    {
        // The old order is proven dead, so a failed re-place must leave a
        // placeable row with no live order — not `risk_aborted` oblivion.
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';
        $this->reviewAlerts = [['severity' => 'error', 'message' => 'insufficient buying power']];

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(1, $this->cancelCalls);
        $this->assertSame(1, $this->placeCalls, 'the rail blocked before the place call');

        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_ASSEMBLED, $row->status);
        $this->assertNull($row->broker_order_id, 'the cancelled order id must not linger as if live');
        $this->assertContains('broker-order-1', $row->notes['thesis_entry']['prior_broker_order_ids']);
        $this->assertStringContainsString('rail_block', $row->notes['thesis_entry']['last_place_block']['reason']);
        $this->assertSame(1, $row->notes['thesis_entry']['place_attempts_failed']);
    }

    public function test_an_unconfirmed_placement_stays_supervised_and_adopts_its_orphan_order(): void
    {
        // A throw around the place call is not proof of rejection: a timeout
        // after the broker accepted looks identical.
        $row = $this->assembledPlay();
        $this->failPlaceWithTransportError = true;
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, $row->status);
        $this->assertSame('place_unconfirmed_after_throw', $row->notes['thesis_entry']['blocked']['reason']);
        $this->assertTrue($row->notes['thesis_entry']['blocked']['requires_operator_reconcile']);

        // The order actually landed. The next tick finds and adopts it, so
        // ordinary supervision can cancel it.
        $this->failPlaceWithTransportError = false;
        $this->brokerOptionOrders[] = [
            'id' => 'orphan-order-1',
            'state' => 'queued',
            'legs' => [['option_id' => 'opt-100', 'side' => 'buy', 'position_effect' => 'open']],
        ];
        $this->travel(60)->seconds();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame('orphan-order-1', $row->refresh()->broker_order_id);

        // And now it is cancellable like any other supervised order.
        $this->travel(60)->seconds();
        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame(ThesisLaneConfig::STATUS_EXPIRED_UNFILLED, $row->refresh()->status);
    }

    public function test_parking_the_thesis_mid_window_cancels_the_resting_order(): void
    {
        // Parking is the operator's kill surface. It must reach a live entry
        // order on the next tick, ahead of the reprice cadence.
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame(1, $this->placeCalls);

        ThesisIntent::query()->update(['status' => ThesisIntent::STATUS_PARKED, 'parked_at' => now()]);
        // Deliberately still inside the reprice window — standing down does
        // not wait for the cadence.
        $this->travel(5)->seconds();

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('thesis_stood_down')
            ->assertExitCode(0);

        $this->assertSame(1, $this->cancelCalls);
        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_EXPIRED_UNFILLED, $row->status);
        $this->assertSame('thesis_stood_down', $row->notes['thesis_entry']['expired']['reason']);
    }

    public function test_a_window_that_closes_during_broker_io_never_places(): void
    {
        // The window is re-read immediately before the broker write path, not
        // once per tick: resolver and broker-context I/O sit in between.
        $this->assembledPlay();
        $this->travelToOnPortfolioRead = Carbon::parse('2026-08-17 10:16:00', 'America/New_York');
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(0, $this->placeCalls, 'a placement must not land past the window close');
        $this->assertSame(0, $this->reviewCalls);
    }

    public function test_zero_max_open_positions_is_a_hard_stop_not_unbounded(): void
    {
        config()->set('harness.thesis.max_open_positions', 0);
        $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('max_open_positions is not configured')
            ->assertExitCode(0);

        $this->assertSame(0, $this->placeCalls);
    }

    public function test_a_window_close_past_the_scheduled_sweep_bound_is_clamped(): void
    {
        // The cron bound in routes/console.php is not env-configurable. A
        // 10:30 close would leave a resting gfd order with zero sweep ticks.
        config()->set('harness.thesis.entry.window_close_et', '10:30');
        $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('clamped to 10:15')
            ->assertExitCode(0);
    }

    public function test_a_fill_landing_before_the_cancel_never_produces_a_second_order(): void
    {
        $row = $this->assembledPlay();
        $this->stateAfterCancel = 'filled';
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('filled before the cancel landed')
            ->assertExitCode(0);

        $this->assertSame(1, $this->placeCalls);
        $this->assertSame('filled', $row->refresh()->notes['thesis_entry']['filled_during_cancel']['broker_state']);
    }

    public function test_no_repeg_when_the_broker_does_not_advertise_option_cancel(): void
    {
        // Fail closed on a versionless tool surface: without a proven cancel
        // path the resting order simply keeps working — and stays supervised,
        // so it is never left to fill hours after the window shut.
        $row = $this->assembledPlay();
        $this->advertiseCancelTool = false;
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(0, $this->cancelCalls);
        $this->assertSame(1, $this->placeCalls);
        $this->assertSame('submitted', $row->refresh()->status);

        // Window closes with no cancel tool: the play is blocked but STILL
        // supervised, never abandoned with a live order.
        $this->travelTo(Carbon::parse('2026-08-17 10:16:00', 'America/New_York'));
        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_ENTRY_BLOCKED, $row->status);
        $this->assertSame('cancel_capability_unavailable', $row->notes['thesis_entry']['blocked']['reason']);

        // The tool surface returns; supervision cancels and retires the play.
        $this->advertiseCancelTool = true;
        $this->travel(60)->seconds();
        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(1, $this->cancelCalls);
        $this->assertSame(ThesisLaneConfig::STATUS_EXPIRED_UNFILLED, $row->refresh()->status);
    }

    public function test_repeg_does_not_compete_with_its_own_debit_against_the_daily_budget(): void
    {
        // The working row is already counted in today's journal-derived gross
        // entry debit. Without netting it out, a $210 play would burn $423 of
        // a $400 budget just by re-pegging once.
        config()->set('harness.thesis.per_day_max_debit', 400.0);
        $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->travel(60)->seconds();
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('repegged')
            ->assertExitCode(0);

        $this->assertSame(2, $this->placeCalls);
    }

    public function test_window_close_cancels_the_resting_order_and_expires_the_play(): void
    {
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);
        $this->assertSame(1, $this->placeCalls);

        $this->travelTo(Carbon::parse('2026-08-17 10:16:00', 'America/New_York'));
        $this->quoteBid = '2.050000';
        $this->quoteAsk = '2.250000';

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('expired unfilled')
            ->assertExitCode(0);

        $this->assertSame(1, $this->cancelCalls);
        $this->assertSame(1, $this->placeCalls, 'no chasing past the window close');

        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_EXPIRED_UNFILLED, $row->status);
        $this->assertSame('entry_window_closed', $row->notes['thesis_entry']['expired']['reason']);
        $this->assertNotNull($row->closed_at);
    }

    public function test_window_close_expires_a_play_that_never_placed(): void
    {
        $row = $this->assembledPlay();
        $this->travelTo(Carbon::parse('2026-08-17 10:16:00', 'America/New_York'));
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')->assertExitCode(0);

        $this->assertSame(0, $this->placeCalls);
        $row->refresh();
        $this->assertSame(ThesisLaneConfig::STATUS_EXPIRED_UNFILLED, $row->status);
        $this->assertSame('entry_window_closed_unplaced', $row->notes['thesis_entry']['expired']['reason']);
    }

    public function test_disarmed_when_the_thesis_lane_is_disabled(): void
    {
        config()->set('harness.thesis.enabled', false);
        $row = $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('thesis executor disarmed: thesis lane disabled')
            ->assertExitCode(0);

        $this->assertSame(0, $this->placeCalls);
        $this->assertSame(ThesisLaneConfig::STATUS_ASSEMBLED, $row->refresh()->status);
    }

    public function test_disarmed_when_the_kill_switch_is_engaged(): void
    {
        config()->set('harness.kill_switch', true);
        $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('kill switch engaged')
            ->assertExitCode(0);

        $this->assertSame(0, $this->placeCalls);
    }

    public function test_idle_outside_regular_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-08-17 08:45:00', 'America/New_York'));
        $this->assembledPlay();
        $this->fakeMcp();

        $this->artisan('theta:thesis-execute')
            ->expectsOutputToContain('regular options session closed')
            ->assertExitCode(0);

        $this->assertSame(0, $this->placeCalls);
    }

    public function test_executor_runs_regardless_of_harness_mode(): void
    {
        // Mode independence: the scalp autopilot disarms under approval mode.
        // This lane is approval-first by construction and must not.
        $this->fakeMcp();

        foreach (['approval', 'auto', 'anything-else'] as $mode) {
            RobinhoodTradeJournal::query()->delete();
            ThesisIntent::query()->delete();
            $this->placeCalls = 0;
            $this->reviewCalls = 0;
            $this->cancelCalls = 0;
            $this->brokerOptionOrders = [];
            config()->set('harness.mode', $mode);

            $this->assembledPlay();

            $this->artisan('theta:thesis-execute')->assertExitCode(0);

            $this->assertSame(1, $this->placeCalls, "thesis executor must place under HARNESS_MODE={$mode}");
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function assembledPlay(): RobinhoodTradeJournal
    {
        $thesis = ThesisIntent::query()->create([
            'underlying_symbol' => 'C',
            'direction' => ThesisIntent::DIRECTION_BULLISH,
            'structure' => ThesisIntent::STRUCTURE_LONG_CALL,
            'dte_min' => 30,
            'dte_max' => 70,
            'max_debit' => 500.0,
            'entry_limit_mode' => 'mid',
            'entry_limit_offset_bps' => 0.0,
            'thesis' => 'Rate-cut path supports the money-centre banks.',
            'price_stop' => 88.0,
            'time_stop_date' => Carbon::parse('2026-10-01'),
            'profit_target_bps' => 6000.0,
            'status' => ThesisIntent::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        return RobinhoodTradeJournal::query()->create([
            'account_number' => (string) config('harness.agentic_account'),
            'asset_class' => 'option',
            'symbol' => 'C',
            'underlying_symbol' => 'C',
            'option_id' => 'opt-100',
            'chain_id' => 'chain-c',
            'option_type' => 'call',
            'expiration_date' => '2026-10-16',
            'strike_price' => 100.0,
            'status' => ThesisLaneConfig::STATUS_ASSEMBLED,
            'intent' => 'long_call',
            'side' => 'buy',
            'position_effect' => 'open',
            'order_type' => 'limit',
            'time_in_force' => 'gfd',
            'market_hours' => 'regular_hours',
            'quantity' => 1,
            'limit_price' => 2.10,
            'dollar_amount' => 210.00,
            'strategy_label' => ThesisLaneConfig::STRATEGY_LABEL,
            'structure_class' => 'single_leg_long',
            'thesis_intent_id' => $thesis->id,
            'thesis' => $thesis->thesis,
            'watchlisted_at' => now(),
            'scanner_payload' => ['intent_source' => 'theta:thesis-assemble'],
            'notes' => ['thesis_entry' => [
                'assembled_at' => now()->toISOString(),
                'anchor_mid' => 2.10,
                'anchor_source' => 'assembly_live_quote',
                'entry_limit_offset_bps' => 0.0,
                'per_play_max_debit' => 500.0,
                'assembly_limit_price' => 2.10,
            ]],
        ]);
    }

    private function fakeMcp(): void
    {
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => []]);
            }
            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }
            if ($method === 'tools/list') {
                $tools = (array) config('harness.options.required_tools');
                if ($this->advertiseCancelTool) {
                    $tools[] = 'cancel_option_order';
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => array_map(fn (string $name): array => ['name' => $name], $tools)],
                ]);
            }
            if ($method !== 'tools/call') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
            }

            $tool = $payload['params']['name'] ?? '';
            $arguments = $payload['params']['arguments'] ?? [];

            return match ($tool) {
                'get_option_instruments' => $this->toolResponse($payload['id'], ['data' => ['instruments' => [[
                    'id' => 'opt-100',
                    'chain_id' => 'chain-c',
                    'chain_symbol' => 'C',
                    'underlying_type' => 'equity',
                    'expiration_date' => $arguments['expiration_dates'] ?? '2026-10-16',
                    'strike_price' => $arguments['strike_price'] ?? '100.0000',
                    'type' => $arguments['type'] ?? 'call',
                    'state' => 'active',
                    'tradability' => 'tradable',
                ]]]]),
                'get_option_quotes' => $this->toolResponse($payload['id'], ['data' => ['results' => [['quote' => [
                    'instrument_id' => $arguments['instrument_ids'][0] ?? 'opt-100',
                    'bid_price' => $this->quoteBid,
                    'ask_price' => $this->quoteAsk,
                    'mark_price' => $this->quoteAsk,
                    'updated_at' => now()->subSeconds($this->optionQuoteAgeSeconds)->toIso8601ZuluString('microsecond'),
                ]]]]]),
                'get_portfolio' => $this->portfolio($payload['id']),
                'get_equity_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => []]]),
                'get_option_orders' => $this->toolResponse($payload['id'], ['data' => ['orders' => $this->brokerOptionOrders]]),
                'get_option_positions' => $this->toolResponse($payload['id'], ['data' => ['positions' => []]]),
                'get_accounts' => $this->toolResponse($payload['id'], ['data' => ['accounts' => [[
                    'account_number' => config('harness.agentic_account'),
                    'type' => 'cash',
                    'option_level' => 'option_level_2',
                    'agentic_allowed' => true,
                ]]]]),
                'review_option_order' => $this->review($payload['id']),
                'place_option_order' => $this->place($payload['id'], $arguments),
                'cancel_option_order' => $this->cancel($payload['id'], $arguments),
                default => $this->toolResponse($payload['id'], ['ok' => true]),
            };
        });
    }

    private function portfolio(mixed $id): mixed
    {
        if ($this->travelToOnPortfolioRead !== null) {
            $this->travelTo($this->travelToOnPortfolioRead);
            $this->travelToOnPortfolioRead = null;
        }

        return $this->toolResponse($id, ['data' => [
            'cash' => (string) $this->cash,
            'buying_power' => ['buying_power' => (string) $this->cash],
        ]]);
    }

    private function review(mixed $id): mixed
    {
        $this->reviewCalls++;

        return $this->toolResponse($id, ['ok' => true, 'alerts' => $this->reviewAlerts]);
    }

    private function place(mixed $id, array $arguments): mixed
    {
        if ($this->failPlaceWithTransportError) {
            return Http::response('gateway timeout', 504);
        }

        $this->placeCalls++;
        $this->lastPlaceArguments = $arguments;
        $orderId = 'broker-order-'.$this->placeCalls;

        $this->brokerOptionOrders[] = [
            'id' => $orderId,
            'state' => 'queued',
            'legs' => [[
                'option_id' => $arguments['legs'][0]['option_id'] ?? 'opt-100',
                'side' => 'buy',
                'position_effect' => 'open',
            ]],
        ];

        return $this->toolResponse($id, [
            'order_id' => $orderId,
            'state' => 'queued',
            'ref_id' => $arguments['ref_id'] ?? null,
        ]);
    }

    private function cancel(mixed $id, array $arguments): mixed
    {
        $this->cancelCalls++;
        $orderId = (string) ($arguments['order_id'] ?? '');

        foreach ($this->brokerOptionOrders as $index => $order) {
            if (($order['id'] ?? null) === $orderId) {
                $this->brokerOptionOrders[$index]['state'] = $this->stateAfterCancel;
            }
        }

        return $this->toolResponse($id, ['ok' => true, 'order_id' => $orderId]);
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
