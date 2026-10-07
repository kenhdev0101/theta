<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConvexProbeRun;
use App\Robinhood\Convex\ConvexMatrixAdmission;
use App\Robinhood\Convex\ConvexProbeLifecycle;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use App\Robinhood\Thesis\ThesisSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class ConvexProbeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_is_immutable_and_tick_cannot_place_a_staged_probe(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->expects($this->never())->method('getOptionOrders');
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $spec = $this->spec();

        $first = $service->stage($spec);
        $again = $service->stage($spec);

        $this->assertSame($first->id, $again->id);
        $this->assertSame('staged', $service->tick($first)->status);
        $this->assertSame(1, ConvexProbeRun::query()->count());

        $altered = $spec->toArray();
        $altered['thesis'] = 'different';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different immutable spec');
        $service->stage(ThesisSpec::fromArray($altered));
    }

    public function test_status_without_thesis_lists_multiple_runs(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $first = $this->spec()->toArray();
        $second = $first;
        $second['thesis_id'] = 'convex-test-second';
        $service->stage(ThesisSpec::fromArray($first));
        $service->stage(ThesisSpec::fromArray($second));

        $this->artisan('theta:convex-probe status')
            ->expectsOutputToContain('"thesis_id":"convex-test"')
            ->expectsOutputToContain('"thesis_id":"convex-test-second"')
            ->assertExitCode(0);
    }

    public function test_precommitted_time_profit_and_stop_exit_triggers(): void
    {
        $now = Carbon::parse('2026-10-07T18:00:00Z');
        $run = new ConvexProbeRun;
        $run->forceFill([
            'spec' => $this->spec()->toArray(),
            'entry_average_price' => 1.00,
            'exit_due_at' => $now->copy()->addMinutes(10),
        ]);

        $this->assertFalse(ConvexProbeLifecycle::exitTriggered($run, ['mid' => 1.00], $now));
        $this->assertTrue(ConvexProbeLifecycle::exitTriggered($run, ['mid' => 1.31], $now));
        $this->assertTrue(ConvexProbeLifecycle::exitTriggered($run, ['mid' => 0.69], $now));
        $this->assertTrue(ConvexProbeLifecycle::exitTriggered($run, ['mid' => 1.00], $now->copy()->addMinutes(10)));
    }

    public function test_external_expiry_precedes_all_wall_clock_triggers_and_is_idempotent(): void
    {
        $now = Carbon::parse('2026-10-07T18:00:00Z');
        $this->travelTo($now);
        $client = $this->createMock(RobinhoodMcpClient::class);
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill(['status' => 'held', 'exit_due_at' => $now->copy()->addHours(2)])->save();

        $expired = $service->expire($run, 'event-1', 'test-feed', 'structure invalidated');
        $this->assertSame('THESIS_EXPIRED', $expired->stewardshipLabel());
        $this->assertSame('thesis_expired', ConvexProbeLifecycle::exitReason($expired, ['mid' => 1.0], $now));
        $this->assertSame($expired->thesis_expired_at->toIso8601String(),
            $service->expire($expired, 'event-1', 'test-feed', 'structure invalidated')->thesis_expired_at->toIso8601String());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different immutable');
        $service->expire($expired, 'event-2', 'test-feed', 'different expiry');
    }

    public function test_two_distinct_probe_ids_reconcile_shuffled_broker_arrays_and_separate_expiry_events(): void
    {
        $now = Carbon::parse('2026-10-07T18:00:00Z');
        $this->travelTo($now);
        $orders = [];
        foreach ([2, 1] as $i) {
            $orders[] = [
                'id' => 'entry-'.$i, 'state' => 'filled', 'processed_quantity' => '1.0000',
                'legs' => [['option_id' => 'option-'.$i, 'side' => 'buy', 'position_effect' => 'open',
                    'executions' => [['price' => '0.22', 'quantity' => '1.0000', 'timestamp' => '2026-10-07T17:59:00Z']]]],
            ];
        }
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => $orders]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => [
            ['option_id' => 'option-2', 'quantity' => '1.0000'],
            ['option_id' => 'option-1', 'quantity' => '1.0000'],
        ]]]);
        $client->method('getOptionInstruments')->willReturnCallback(static function (array $args): array {
            $i = (string) ($args['strike_price'] ?? '') === '291.0000' ? 2 : 1;

            return ['data' => ['instruments' => [[
                'id' => 'option-'.$i, 'chain_id' => 'chain-1', 'chain_symbol' => 'IWM',
                'expiration_date' => '2026-10-16', 'strike_price' => '29'.$i.'.0000',
                'type' => 'call', 'state' => 'active', 'tradability' => 'tradable',
            ]]]];
        });
        $client->method('getOptionQuotes')->willReturnCallback(static function (array $ids): array {
            $id = $ids[0];

            return ['data' => ['results' => [
                ['quote' => ['instrument_id' => $id === 'option-1' ? 'option-2' : 'option-1',
                    'bid_price' => '8.00', 'ask_price' => '9.00', 'updated_at' => '2026-10-07T18:00:00Z']],
                ['quote' => ['instrument_id' => $id, 'bid_price' => '0.19', 'ask_price' => '0.22',
                    'updated_at' => '2026-10-07T17:57:00Z']],
            ]]];
        });
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, [
            'agentic_account' => '872449178', 'convex' => ['min_quote_fresh_seconds' => 60],
            'options' => ['min_quote_fresh_seconds' => 10],
        ], new JsonlRecorder(sys_get_temp_dir()));
        $runs = [];
        foreach ([1, 2] as $i) {
            $raw = $this->spec()->toArray();
            $raw['thesis_id'] = 'convex-test-'.$i;
            $run = $service->stage(ThesisSpec::fromArray($raw));
            $run->forceFill([
                'status' => 'entry_pending', 'option_id' => 'option-'.$i, 'entry_order_id' => 'entry-'.$i,
                'expiration_date' => '2026-10-16', 'strike_price' => 289 + $i,
                'entry_submitted_at' => $now->copy()->subMinutes(1),
            ])->save();
            $runs[$i] = $run;
        }

        $first = $service->tick($runs[1]);
        $second = $service->tick($runs[2]);
        $this->assertSame('held', $first->status);
        $this->assertSame('held', $second->status);
        $this->assertSame('fresh_exit_bid_unavailable', $first->block_reason);
        $this->assertSame('fresh_exit_bid_unavailable', $second->block_reason);
        $this->assertSame(1.0, (float) $first->entry_filled_qty);
        $this->assertSame(1.0, (float) $second->entry_filled_qty);
        $this->assertNotSame($first->option_id, $second->option_id);

        $first = $service->expire($first, 'event-1', 'test-feed', 'first structural failure');
        $this->assertSame('THESIS_EXPIRED', $first->stewardshipLabel());
        $this->assertSame('THESIS_ACTIVE', $second->refresh()->stewardshipLabel());
        $second = $service->expire($second, 'event-2', 'test-feed', 'second structural failure');
        $this->assertSame('thesis_expired', ConvexProbeLifecycle::exitReason($first, ['mid' => 1], $now));
        $this->assertSame('thesis_expired', ConvexProbeLifecycle::exitReason($second, ['mid' => 1], $now));
        $this->assertNotSame($first->thesis_expiry_event_id, $second->thesis_expiry_event_id);
    }

    public function test_expiry_cancels_resting_entry_without_waiting_three_minutes(): void
    {
        $now = Carbon::parse('2026-10-07T18:00:00Z');
        $this->travelTo($now);
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [[
            'id' => 'entry-1', 'state' => 'open', 'processed_quantity' => '0.0000',
            'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open']],
        ]]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => []]]);
        $client->expects($this->once())->method('cancelOptionOrder')->with('entry-1');
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill(['status' => 'entry_pending', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1',
            'entry_submitted_at' => $now->copy()->subSeconds(5)])->save();

        $result = $service->tick($service->expire($run, 'event-1', 'test-feed', 'structure invalidated'));
        $this->assertSame('entry_pending', $result->status);
        $this->assertSame('entry_cancel_requested_await_broker_confirmation', $result->block_reason);
    }

    public function test_expiry_cancels_resting_exit_without_waiting_two_minutes(): void
    {
        $now = Carbon::parse('2026-10-07T18:00:00Z');
        $this->travelTo($now);
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [
            ['id' => 'exit-1', 'state' => 'open', 'processed_quantity' => '0.0000',
                'legs' => [['option_id' => 'option-1', 'side' => 'sell', 'position_effect' => 'close']]],
            ['id' => 'entry-1', 'state' => 'filled', 'processed_quantity' => '1.0000',
                'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open',
                    'executions' => [['price' => '0.22', 'quantity' => '1.0000', 'timestamp' => '2026-10-07T17:59:00Z']]]],
            ],
        ]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => [[
            'option_id' => 'option-1', 'quantity' => '1.0000',
        ]]]]);
        $client->expects($this->once())->method('cancelOptionOrder')->with('exit-1');
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill([
            'status' => 'exit_pending', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1',
            'entry_filled_qty' => 1, 'exit_order_id' => 'exit-1',
            'exit_submitted_at' => $now->copy()->subSeconds(5),
        ])->save();

        $result = $service->tick($service->expire($run, 'event-1', 'test-feed', 'structure invalidated'));
        $this->assertSame('exit_pending', $result->status);
        $this->assertSame('thesis_expired', $result->exit_reason);
        $this->assertSame('exit_cancel_requested_await_broker_confirmation', $result->block_reason);
    }

    public function test_matrix_entry_evidence_uses_exact_fresh_admit_row(): void
    {
        $matrix = tempnam(sys_get_temp_dir(), 'convex-matrix-');
        $columns = ['ticker', 'snapshot_ts', 'dte', 'far_delta', 'far_strike', 'spot', 'verdict', 'failed_signals'];
        $row = ['ticker' => 'IWM', 'snapshot_ts' => now()->toIso8601String(),
            'dte' => '16', 'far_delta' => '0.20', 'far_strike' => '290', 'spot' => '280',
            'verdict' => 'ADMIT', 'failed_signals' => ''];
        $handle = fopen($matrix, 'w');
        fputcsv($handle, $columns);
        fputcsv($handle, array_values($row));
        fclose($handle);
        try {
            $client = $this->createMock(RobinhoodMcpClient::class);
            $service = new ConvexProbeLifecycle($client, [
                'agentic_account' => '872449178', 'convex' => ['matrix_path' => $matrix],
            ], new JsonlRecorder(sys_get_temp_dir()));
            $raw = $this->spec()->toArray();
            $raw['matrix_source'] = ['row_sha256' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
            $this->assertSame('staged', $service->stage(ThesisSpec::fromArray($raw))->status);

            $raw['thesis_id'] = 'convex-test-reject';
            $raw['matrix_source']['row_sha256'] = str_repeat('a', 64);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hash not found');
            $service->stage(ThesisSpec::fromArray($raw));
        } finally {
            unlink($matrix);
        }
    }

    public function test_matrix_evidence_binds_the_emitted_call_tenor_and_wing(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:00:00Z'));
        $row = ['dte' => '72', 'far_delta' => '0.1786', 'far_strike' => '58', 'spot' => '53.725'];
        $contract = ['expiration_date' => '2026-12-18', 'strike_price' => 58];
        $this->assertNull(ConvexMatrixAdmission::contractBlocker($row, $contract, ['delta' => 0.179]));
        $this->assertNull(ConvexMatrixAdmission::contractBlocker($row,
            ['expiration_date' => '2026-12-18', 'strike_price' => 57], ['delta' => 0.248]));
        $this->assertSame('matrix_contract_tenor_mismatch', ConvexMatrixAdmission::contractBlocker($row,
            ['expiration_date' => '2026-12-31', 'strike_price' => 58], ['delta' => 0.179]));
        $this->assertSame('matrix_contract_wing_mismatch', ConvexMatrixAdmission::contractBlocker($row,
            ['expiration_date' => '2026-12-18', 'strike_price' => 65], ['delta' => 0.179]));
        $this->assertSame('matrix_contract_wing_mismatch', ConvexMatrixAdmission::contractBlocker($row,
            $contract, ['delta' => 0.30]));

        $raw = $this->spec()->toArray();
        $raw['legs'][0]['structure'] = 'single_leg_long_put';
        $raw['matrix_source'] = ['row_sha256' => str_repeat('a', 64)];
        $service = new ConvexProbeLifecycle($this->createMock(RobinhoodMcpClient::class),
            ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot admit a put');
        $service->stage(ThesisSpec::fromArray($raw));
    }

    public function test_matrix_evidence_rejects_blank_relative_and_invalid_absolute_timestamps(): void
    {
        $matrix = tempnam(sys_get_temp_dir(), 'convex-bad-time-');
        try {
            foreach (['', 'now', '2026-02-30T18:00:00+00:00'] as $timestamp) {
                $row = ['ticker' => 'IWM', 'snapshot_ts' => $timestamp,
                    'dte' => '16', 'far_delta' => '0.20', 'far_strike' => '290',
                    'spot' => '280', 'verdict' => 'ADMIT', 'failed_signals' => ''];
                $handle = fopen($matrix, 'w');
                fputcsv($handle, array_keys($row));
                fputcsv($handle, array_values($row));
                fclose($handle);
                try {
                    ConvexMatrixAdmission::verify([
                        'row_sha256' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    ], $this->spec()->legs()[0], $matrix);
                    $this->fail('malformed matrix timestamp was accepted: '.$timestamp);
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('timestamp', $e->getMessage());
                }
            }
        } finally {
            unlink($matrix);
        }
    }

    public function test_confirmed_exit_cancellation_reaches_quote_gate_in_same_tick_after_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:00:00Z'));
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [
            ['id' => 'exit-1', 'state' => 'cancelled', 'processed_quantity' => '0.0000',
                'legs' => [['option_id' => 'option-1', 'side' => 'sell', 'position_effect' => 'close']]],
            ['id' => 'entry-1', 'state' => 'filled', 'processed_quantity' => '1.0000',
                'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open',
                    'executions' => [['price' => '0.22', 'quantity' => '1.0000', 'timestamp' => '2026-10-07T17:59:00Z']]]],
            ],
        ]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => [[
            'option_id' => 'option-1', 'quantity' => '1.0000',
        ]]]]);
        $client->method('getOptionInstruments')->willReturn(['data' => ['instruments' => [[
            'id' => 'option-1', 'chain_id' => 'chain-1', 'chain_symbol' => 'IWM',
            'expiration_date' => '2026-10-16', 'strike_price' => '290.0000',
            'type' => 'call', 'state' => 'active', 'tradability' => 'tradable',
        ]]]]);
        $client->method('getOptionQuotes')->willReturn(['data' => ['results' => [[
            'quote' => ['instrument_id' => 'option-1', 'bid_price' => '0.19', 'ask_price' => '0.22',
                'updated_at' => '2026-10-07T17:57:00Z'],
        ]]]]);
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, [
            'agentic_account' => '872449178', 'convex' => ['min_quote_fresh_seconds' => 60],
        ], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill(['status' => 'exit_pending', 'option_id' => 'option-1',
            'entry_order_id' => 'entry-1', 'entry_filled_qty' => 1,
            'exit_order_id' => 'exit-1', 'exit_attempt' => 1,
            'expiration_date' => '2026-10-16', 'strike_price' => 290])->save();

        $result = $service->tick($service->expire($run, 'event-1', 'test-feed', 'structure invalidated'));
        $this->assertSame('held', $result->status);
        $this->assertSame('fresh_exit_bid_unavailable', $result->block_reason);
        $this->assertNull($result->exit_order_id);
        $this->assertSame('thesis_expired', $result->exit_reason);
    }

    public function test_stage_rejects_an_exit_rule_that_the_one_contract_supervisor_does_not_execute(): void
    {
        $raw = $this->spec()->toArray();
        $raw['legs'][0]['fast_exit']['trail_from_peak_pct'] = 20;
        $client = $this->createMock(RobinhoodMcpClient::class);
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exit rule');
        $service->stage(ThesisSpec::fromArray($raw));
    }

    public function test_broker_filled_entry_becomes_owned_only_with_matching_position_and_stale_exit_quote_places_nothing(): void
    {
        $now = Carbon::parse('2026-10-07T18:00:00Z');
        $this->travelTo($now);
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [[
            'id' => 'entry-1', 'state' => 'filled', 'processed_quantity' => '1.0000',
            'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open', 'executions' => [[
                'price' => '0.22', 'quantity' => '1.0000', 'timestamp' => '2026-10-07T17:59:00Z',
            ]]]],
        ]]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => [[
            'option_id' => 'option-1', 'quantity' => '1.0000',
        ]]]]);
        $client->method('getOptionInstruments')->willReturn(['data' => ['instruments' => [[
            'id' => 'option-1', 'chain_id' => 'chain-1', 'chain_symbol' => 'IWM',
            'expiration_date' => '2026-10-16', 'strike_price' => '290.0000',
            'type' => 'call', 'state' => 'active', 'tradability' => 'tradable',
        ]]]]);
        $client->method('getOptionQuotes')->willReturn(['data' => ['results' => [[
            'quote' => ['instrument_id' => 'option-1', 'bid_price' => '0.19', 'ask_price' => '0.22',
                'updated_at' => '2026-10-07T17:57:00Z'],
        ]]]]);
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, [
            'agentic_account' => '872449178', 'convex' => ['min_quote_fresh_seconds' => 60],
            'options' => ['min_quote_fresh_seconds' => 10],
        ], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill([
            'status' => 'entry_pending', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1',
            'expiration_date' => '2026-10-16', 'strike_price' => 290,
            'entry_submitted_at' => $now->copy()->subMinutes(1),
        ])->save();

        $updated = $service->tick($run);

        $this->assertSame('held', $updated->status);
        $this->assertSame(1.0, (float) $updated->entry_filled_qty);
        $this->assertSame(0.22, (float) $updated->entry_average_price);
        $this->assertSame('2026-10-07T18:09:00+00:00', $updated->exit_due_at->toIso8601String());
        $this->assertSame('fresh_exit_bid_unavailable', $updated->block_reason);
    }

    public function test_broker_order_with_wrong_leg_does_not_confer_probe_ownership(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [[
            'id' => 'entry-1', 'state' => 'filled', 'processed_quantity' => '1.0000',
            'legs' => [['option_id' => 'other-option', 'side' => 'buy', 'position_effect' => 'open']],
        ]]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => [[
            'option_id' => 'option-1', 'quantity' => '1.0000',
        ]]]]);
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill(['status' => 'entry_pending', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1'])->save();

        $updated = $service->tick($run);

        $this->assertSame('ambiguous', $updated->status);
        $this->assertSame('entry_order_leg_conflicts_with_probe_ownership', $updated->block_reason);
        $this->assertSame(0.0, (float) $updated->entry_filled_qty);
    }

    public function test_terminal_sell_to_close_requires_broker_fill_and_zero_position(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [[
            'id' => 'entry-1', 'state' => 'filled', 'processed_quantity' => '1.0000',
            'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open',
                'executions' => [['price' => '0.22', 'quantity' => '1.0000', 'timestamp' => '2026-10-07T17:59:00Z']]]],
        ], [
            'id' => 'exit-1', 'state' => 'filled', 'processed_quantity' => '1.0000',
            'legs' => [['option_id' => 'option-1', 'side' => 'sell', 'position_effect' => 'close',
                'executions' => [['price' => '0.25', 'quantity' => '1.0000', 'timestamp' => '2026-10-07T18:09:00Z']]]],
        ]]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => []]]);
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill([
            'status' => 'exit_pending', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1',
            'exit_order_id' => 'exit-1', 'entry_filled_qty' => 1,
        ])->save();

        $updated = $service->tick($run);

        $this->assertSame('closed', $updated->status);
        $this->assertSame(1.0, (float) $updated->exit_filled_qty);
        $this->assertSame(0.25, (float) $updated->exit_average_price);
        $this->assertNotNull($updated->closed_at);
    }

    public function test_restart_with_unverified_exit_intent_never_creates_another_sell(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => []]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => []]]);
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill([
            'status' => 'exit_pending', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1',
            'entry_filled_qty' => 1, 'exit_attempt' => 1, 'exit_intent_key' => 'unverified-ref',
        ])->save();

        $updated = $service->tick($run);

        $this->assertSame('ambiguous', $updated->status);
        $this->assertSame('exit_placement_identity_unverified_reconcile_before_retry', $updated->block_reason);
        $this->assertSame(1, $updated->exit_attempt);
    }

    public function test_cancelled_unfilled_entry_closes_only_when_broker_position_is_zero(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [[
            'id' => 'entry-1', 'state' => 'cancelled', 'processed_quantity' => '0.0000',
            'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open']],
        ]]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => [[
            'option_id' => 'option-1', 'quantity' => '1.0000',
        ]]]]);
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill(['status' => 'entry_pending', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1'])->save();

        $updated = $service->tick($run);

        $this->assertSame('ambiguous', $updated->status);
        $this->assertSame('cancelled_unfilled_entry_has_broker_position', $updated->block_reason);
        $this->assertNull($updated->closed_at);
    }

    public function test_probe_entry_cannot_spend_pending_deposit_even_if_buying_power_includes_it(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getPortfolio')->willReturn(['data' => [
            'cash' => '100.00', 'pending_deposits' => '100.00',
            'buying_power' => ['buying_power' => '100.00'],
        ]]);
        $client->method('getEquityOrders')->willReturn(['data' => ['orders' => []]]);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => []]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => []]]);
        $client->method('getAccountCapability')->willReturn([]);
        $client->method('listTools')->willReturn([]);
        $client->expects($this->never())->method('getOptionChains');
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, ['agentic_account' => '872449178'], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());

        $updated = $service->enter($run);

        $this->assertSame('staged', $updated->status);
        $this->assertSame('settled_cash_or_buying_power_unverified', $updated->block_reason);
        $this->assertNull($updated->entry_intent_key);
    }

    public function test_exit_resolver_must_return_the_frozen_owned_option_id(): void
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $client->method('getOptionOrders')->willReturn(['data' => ['orders' => [[
            'id' => 'entry-1', 'state' => 'filled', 'processed_quantity' => '1.0000',
            'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open',
                'executions' => [['price' => '0.22', 'quantity' => '1.0000', 'timestamp' => '2026-10-07T17:59:00Z']]]],
        ]]]]);
        $client->method('getOptionPositions')->willReturn(['data' => ['positions' => [[
            'option_id' => 'option-1', 'quantity' => '1.0000',
        ]]]]);
        $client->method('getOptionInstruments')->willReturn(['data' => ['instruments' => [[
            'id' => 'option-2', 'chain_id' => 'chain-1', 'chain_symbol' => 'IWM',
            'expiration_date' => '2026-10-16', 'strike_price' => '290.0000',
            'type' => 'call', 'state' => 'active', 'tradability' => 'tradable',
        ]]]]);
        $client->method('getOptionQuotes')->willReturn(['data' => ['results' => [[
            'quote' => ['instrument_id' => 'option-2', 'bid_price' => '0.19', 'ask_price' => '0.22',
                'updated_at' => now()->toIso8601String()],
        ]]]]);
        $client->expects($this->never())->method('placeOptionOrder');
        $service = new ConvexProbeLifecycle($client, [
            'agentic_account' => '872449178', 'convex' => ['min_quote_fresh_seconds' => 60],
            'options' => ['min_quote_fresh_seconds' => 10],
        ], new JsonlRecorder(sys_get_temp_dir()));
        $run = $service->stage($this->spec());
        $run->forceFill([
            'status' => 'held', 'option_id' => 'option-1', 'entry_order_id' => 'entry-1',
            'entry_filled_qty' => 1, 'expiration_date' => '2026-10-16', 'strike_price' => 290,
        ])->save();

        $updated = $service->tick($run, forceExit: true);

        $this->assertSame('ambiguous', $updated->status);
        $this->assertSame('exit_contract_id_conflicts_with_owned_option', $updated->block_reason);
        $this->assertNull($updated->exit_intent_key);
    }

    private function spec(): ThesisSpec
    {
        return ThesisSpec::fromArray([
            'spec_version' => '1', 'thesis_id' => 'convex-test', 'thesis' => 'mechanical lifecycle probe',
            'legs' => [[
                'leg_id' => 'probe', 'role' => 'probe', 'asset_class' => 'option',
                'symbol' => 'IWM', 'direction' => 'long', 'structure' => 'single_leg_long_call',
                'dte' => ['min' => 7, 'max' => 21], 'moneyness' => ['mode' => 'delta', 'target' => 0.20],
                'sizing' => ['mode' => 'balance_fraction', 'fraction' => 1.0],
                'entry' => ['type' => 'marketable_limit'],
                'invalidation' => ['time_stop' => Carbon::today()->addDays(3)->toDateString()],
                'fast_exit' => ['max_hold_minutes' => 10, 'profit_target_mult' => 1.30, 'hard_stop_pct' => 30],
            ]],
        ]);
    }
}
