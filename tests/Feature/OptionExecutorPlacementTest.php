<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConvexProbeRun;
use App\Models\HarnessOrderRef;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionExecutor;
use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fixture-transport option executor flow. Integration proof is live broker
 * truth; these pin the deterministic mechanics: ref durable before place,
 * review alerts block, re-runs cannot double-place, and the wire payload
 * matches the live-measured legs schema.
 */
class OptionExecutorPlacementTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $placedRefIds = [];

    /** @var list<array> */
    private array $placedArguments = [];

    private array $reviewResponse = ['ok' => true, 'alerts' => []];

    private int $reviewCalls = 0;

    private ?\Closure $onReview = null;

    private ?array $liveOptionQuote = null;

    private function fakeMcp(): void
    {
        Http::fake(function (Request $request) {
            $payload = $request->data();
            $method = $payload['method'] ?? '';

            if ($method === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => ['protocolVersion' => '2025-03-26']]);
            }
            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }

            if ($method === 'tools/call') {
                $tool = $payload['params']['name'] ?? '';
                $arguments = $payload['params']['arguments'] ?? [];

                if ($tool === 'review_option_order') {
                    $this->reviewCalls++;
                    if ($this->onReview !== null) {
                        ($this->onReview)();
                    }

                    return $this->toolResponse($payload['id'], $this->reviewResponse);
                }

                if ($tool === 'place_option_order') {
                    $refId = (string) ($arguments['ref_id'] ?? '');
                    $this->placedRefIds[] = $refId;
                    $this->placedArguments[] = $arguments;

                    Assert::assertTrue(
                        HarnessOrderRef::query()->where('client_ref_id', $refId)->exists(),
                        'option ref_id must be persisted BEFORE the broker place call',
                    );

                    return $this->toolResponse($payload['id'], [
                        'data' => [
                            'order' => [
                                'id' => 'opt-broker-1',
                                'state' => 'queued',
                                'ref_id' => $refId,
                            ],
                        ],
                    ]);
                }

                if ($tool === 'get_option_quotes' && $this->liveOptionQuote !== null) {
                    return $this->toolResponse($payload['id'], ['data' => ['results' => [
                        ['quote' => $this->liveOptionQuote],
                    ]]]);
                }

                return $this->toolResponse($payload['id'], ['ok' => true]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
        });
    }

    private function toolResponse(mixed $id, array $body)
    {
        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['isError' => false, 'content' => [['type' => 'text', 'text' => json_encode($body)]]],
        ]);
    }

    private function executor(): OptionExecutor
    {
        $cfg = array_replace_recursive((array) config('harness'), [
            'oauth_token' => 'fixture-token',
            'kill_switch' => false,
        ]);

        return new OptionExecutor(
            client: RobinhoodMcpClient::fromConfig($cfg),
            rails: new OptionRailPipeline,
            refs: new OrderRefStore,
            telemetry: new JsonlRecorder(sys_get_temp_dir().'/theta-opt-test-'.getmypid()),
            cfg: $cfg,
        );
    }

    private function intent(array $overrides = []): OptionOrderIntent
    {
        $defaults = [
            'underlyingSymbol' => 'SPY',
            'optionType' => 'call',
            'expirationDate' => '2026-06-15',
            'strikePrice' => 740.0,
            'side' => 'buy',
            'positionEffect' => 'open',
            'quantity' => 1,
            'limitPrice' => 0.50,
            'optionId' => 'opt-uuid-1',
            'chainId' => 'chain-uuid-1',
            'source' => 'fixture-test',
        ];

        return new OptionOrderIntent(...array_merge($defaults, $overrides));
    }

    private function greenContext(): array
    {
        return [
            'account' => ['account_number' => config('harness.agentic_account'), 'option_level' => 'option_level_2', 'agentic_allowed' => true],
            'capability_tools' => (array) config('harness.options.required_tools'),
            'contract' => ['option_id' => 'opt-uuid-1', 'chain_id' => 'chain-uuid-1', 'tradability' => 'tradable', 'state' => 'active'],
            'quote' => ['bid' => 0.48, 'ask' => 0.50, 'mid' => 0.49, 'spread_bps' => 408.0, 'age_seconds' => 2.0, 'stale' => false],
            'session_open' => true,
            'settled_cash' => 245.83,
            'buying_power' => 245.83,
            'options_spent_today_debit' => 0.0,
            'options_open_debit' => 0.0,
            'underlying_open_debit' => [],
            'open_option_positions' => [],
            'open_option_orders' => [],
        ];
    }

    public function test_allowed_bto_places_with_persisted_ref_and_measured_wire_format(): void
    {
        $this->fakeMcp();

        $result = $this->executor()->execute($this->intent(), $this->greenContext());

        $this->assertSame('LIVE_POSTED_NOT_FILLED', $result['classification']);
        $this->assertCount(1, $this->placedRefIds);

        $ref = HarnessOrderRef::sole();
        $this->assertSame('placed', $ref->status);
        $this->assertSame('opt-broker-1', $ref->broker_order_id);
        $this->assertSame($ref->client_ref_id, $this->placedRefIds[0]);

        $wire = $this->placedArguments[0];
        $this->assertSame('opt-uuid-1', $wire['legs'][0]['option_id']);
        $this->assertSame('buy', $wire['legs'][0]['side']);
        $this->assertSame('open', $wire['legs'][0]['position_effect']);
        $this->assertSame('limit', $wire['type']);
        $this->assertSame('1', $wire['quantity']);
        $this->assertSame('0.50', $wire['price']);
        $this->assertSame(config('harness.agentic_account'), $wire['account_number']);

        $journal = RobinhoodTradeJournal::sole();
        $this->assertSame('option', $journal->asset_class);
        $this->assertSame('submitted', $journal->status);
        $this->assertSame('opt-broker-1', $journal->broker_order_id);
        $this->assertSame('open', $journal->position_effect);
        $this->assertSame('chain-uuid-1', $journal->chain_id);
        $this->assertSame('long_call', $journal->intent);
    }

    public function test_convex_resting_entry_preserves_bounded_limit_through_review_and_place(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:02:00Z'));
        config()->set('harness.options.max_spread_bps', 500);
        config()->set('harness.convex.entry_repeg_cap_frac', 0.5);
        $this->fakeMcp();
        $context = $this->greenContext();
        $context['quote'] = [
            'bid' => 0.43, 'ask' => 0.48, 'mid' => 0.455, 'spread_bps' => 1098.90,
            'age_seconds' => 1.0, 'stale' => false, 'updated_at' => now()->toIso8601String(),
        ];
        $intent = $this->intent([
            'limitPrice' => 0.46, 'source' => 'theta:convex-probe',
            'underlyingSymbol' => 'XLF', 'expirationDate' => '2026-12-18', 'strikePrice' => 58.0,
            'strategyLabel' => 'convex_thesis_probe',
            'metadata' => ['entry_mode' => 'convex_bounded_resting', 'hard_stop_pct' => 30.0, 'profit_target_mult' => 1.30,
                'thesis_id' => 'convex-fixture', 'spec_hash' => str_repeat('a', 64)],
        ]);
        $this->liveOptionQuote = ['instrument_id' => 'opt-uuid-1', 'bid_price' => '0.43',
            'ask_price' => '0.48', 'updated_at' => now()->toIso8601String()];

        $missing = $this->executor()->execute($intent, $context);
        $this->assertSame('LIVE_RISK_ABORTED', $missing['classification']);
        $this->assertContains('matrix_admission:matrix_evidence_required_for_bounded_entry', $missing['verdict']->blockReasons());
        $this->assertSame(0, $this->reviewCalls);
        $this->assertSame([], $this->placedArguments);

        $path = tempnam(sys_get_temp_dir(), 'convex-place-');
        $this->assertIsString($path);
        try {
            $columns = ['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta'];
            $admit = ['XLF', '72', '2026-10-07T18:00:00Z', 'ADMIT', '', '', '0.20'];
            $handle = fopen($path, 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $admit);
            fclose($handle);
            $hash = hash('sha256', json_encode(array_combine($columns, $admit), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            config()->set('harness.convex.matrix_path', $path);
            ConvexProbeRun::query()->create([
                'thesis_id' => 'convex-fixture', 'leg_id' => 'probe',
                'account_number' => (string) config('harness.agentic_account'), 'status' => 'entry_pending',
                'spec' => ['matrix_source' => ['row_sha256' => $hash],
                    'legs' => [['fast_exit' => ['hard_stop_pct' => 30.0, 'profit_target_mult' => 1.30]]]],
                'spec_hash' => str_repeat('a', 64), 'underlying_symbol' => 'XLF', 'option_type' => 'call',
                'option_id' => 'opt-uuid-1', 'entry_limit_price' => 0.46,
                'entry_intent_key' => $intent->intentKey(now()->toDateString()),
            ]);
            $context['convex_matrix_source'] = ['row_sha256' => 'invalid'];
            $malformed = $this->executor()->execute($intent, $context);
            $this->assertSame('LIVE_RISK_ABORTED', $malformed['classification']);
            $this->assertContains('matrix_admission:bounded_convex_run_ownership_unverified', $malformed['verdict']->blockReasons());
            $this->assertSame(0, $this->reviewCalls);

            $context['convex_matrix_source'] = ['row_sha256' => $hash];
            $forged = $this->intent([
                'limitPrice' => 0.46, 'source' => 'theta:convex-probe', 'underlyingSymbol' => 'XLF',
                'expirationDate' => '2026-12-18', 'strikePrice' => 58.0,
                'strategyLabel' => 'convex_thesis_probe',
                'metadata' => ['entry_mode' => 'convex_bounded_resting', 'hard_stop_pct' => 99.0, 'profit_target_mult' => 1.30,
                    'thesis_id' => 'convex-fixture', 'spec_hash' => str_repeat('a', 64)],
            ]);
            $unowned = $this->executor()->execute($forged, $context);
            $this->assertContains('matrix_admission:bounded_convex_run_ownership_unverified', $unowned['verdict']->blockReasons());
            $this->assertSame(0, $this->reviewCalls);

            $stored = ConvexProbeRun::query()->where('thesis_id', 'convex-fixture')->firstOrFail();
            $originalSpec = $stored->spec;
            $missingTargetSpec = $originalSpec;
            unset($missingTargetSpec['legs'][0]['fast_exit']['profit_target_mult']);
            $stored->forceFill(['spec' => $missingTargetSpec])->save();
            $missingTargetIntent = $this->intent([
                'limitPrice' => 0.46, 'source' => 'theta:convex-probe', 'underlyingSymbol' => 'XLF',
                'expirationDate' => '2026-12-18', 'strikePrice' => 58.0, 'strategyLabel' => 'convex_thesis_probe',
                'metadata' => ['entry_mode' => 'convex_bounded_resting', 'hard_stop_pct' => 30.0,
                    'thesis_id' => 'convex-fixture', 'spec_hash' => str_repeat('a', 64)],
            ]);
            $missingTarget = $this->executor()->execute($missingTargetIntent, $context);
            $this->assertContains('matrix_admission:bounded_convex_run_ownership_unverified', $missingTarget['verdict']->blockReasons());
            $this->assertSame(0, $this->reviewCalls);
            $stored->forceFill(['spec' => $originalSpec])->save();

            $result = $this->executor()->execute($intent, $context);

            $this->assertSame('LIVE_POSTED_NOT_FILLED', $result['classification']);
            $this->assertSame('0.46', $this->placedArguments[0]['price']);
            $this->assertSame('buy', $this->placedArguments[0]['legs'][0]['side']);
            $this->assertSame('open', $this->placedArguments[0]['legs'][0]['position_effect']);
        } finally {
            unlink($path);
        }
    }

    public static function unsafeConvexRefreshQuotes(): array
    {
        return [
            'bid deteriorates' => ['0.20', 'now', 'spread:convex_entry_exceeds_exit_risk_or_reprice_cap'],
            'timestamp absent' => ['0.43', '', 'quote_freshness:quote_unavailable_before_place'],
            'timestamp relative' => ['0.43', 'yesterday', 'quote_freshness:quote_unavailable_before_place'],
        ];
    }

    #[DataProvider('unsafeConvexRefreshQuotes')]
    public function test_convex_entry_aborts_if_refreshed_quote_is_unverifiable_or_risky(string $bid, string $stamp, string $reason): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:02:00Z'));
        config()->set('harness.options.max_spread_bps', 500);
        config()->set('harness.convex.entry_repeg_cap_frac', 0.5);
        $path = tempnam(sys_get_temp_dir(), 'convex-place-');
        $this->assertIsString($path);
        try {
            $columns = ['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta'];
            $admit = ['XLF', '72', '2026-10-07T18:00:00Z', 'ADMIT', '', '', '0.20'];
            $handle = fopen($path, 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $admit);
            fclose($handle);
            $hash = hash('sha256', json_encode(array_combine($columns, $admit), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            config()->set('harness.convex.matrix_path', $path);
            $intent = $this->intent([
                'limitPrice' => 0.46, 'source' => 'theta:convex-probe',
                'underlyingSymbol' => 'XLF', 'expirationDate' => '2026-12-18', 'strikePrice' => 58.0,
                'strategyLabel' => 'convex_thesis_probe',
                'metadata' => ['entry_mode' => 'convex_bounded_resting', 'hard_stop_pct' => 30.0, 'profit_target_mult' => 1.30,
                    'thesis_id' => 'convex-deteriorated', 'spec_hash' => str_repeat('b', 64)],
            ]);
            ConvexProbeRun::query()->create([
                'thesis_id' => 'convex-deteriorated', 'leg_id' => 'probe',
                'account_number' => (string) config('harness.agentic_account'), 'status' => 'entry_pending',
                'spec' => ['matrix_source' => ['row_sha256' => $hash],
                    'legs' => [['fast_exit' => ['hard_stop_pct' => 30.0, 'profit_target_mult' => 1.30]]]],
                'spec_hash' => str_repeat('b', 64), 'underlying_symbol' => 'XLF', 'option_type' => 'call',
                'option_id' => 'opt-uuid-1', 'entry_limit_price' => 0.46,
                'entry_intent_key' => $intent->intentKey(now()->toDateString()),
            ]);
            $this->liveOptionQuote = ['instrument_id' => 'opt-uuid-1', 'bid_price' => $bid,
                'ask_price' => '0.48', 'updated_at' => $stamp === 'now' ? now()->toIso8601String() : $stamp];
            $this->fakeMcp();
            $context = $this->greenContext();
            $context['quote'] = ['bid' => 0.43, 'ask' => 0.48, 'mid' => 0.455, 'spread_bps' => 1098.90,
                'age_seconds' => 1.0, 'stale' => false, 'updated_at' => now()->toIso8601String()];
            $context['convex_matrix_source'] = ['row_sha256' => $hash];

            $result = $this->executor()->execute($intent, $context);

            $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
            $this->assertContains($reason, $result['verdict']->blockReasons());
            $this->assertSame(1, $this->reviewCalls);
            $this->assertSame([], $this->placedArguments);
        } finally {
            unlink($path);
        }
    }

    public function test_new_matrix_reject_during_review_blocks_convex_placement(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:02:00Z'));
        $path = tempnam(sys_get_temp_dir(), 'convex-place-');
        $this->assertIsString($path);
        try {
            $columns = ['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta'];
            $admit = ['XLF', '72', '2026-10-07T18:00:00Z', 'ADMIT', '', '', '0.20'];
            $handle = fopen($path, 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $admit);
            fclose($handle);
            $hash = hash('sha256', json_encode(array_combine($columns, $admit), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            config()->set('harness.convex.matrix_path', $path);
            $this->onReview = static function () use ($path): void {
                $handle = fopen($path, 'a');
                fputcsv($handle, ['XLF', '72', '2026-10-07T18:01:00Z', 'REJECT', 'conventional_smirk', '', '0.20']);
                fclose($handle);
            };
            $this->fakeMcp();
            $context = $this->greenContext();
            $context['quote']['updated_at'] = now()->toIso8601String();
            $context['convex_matrix_source'] = ['row_sha256' => $hash];

            $result = $this->executor()->execute($this->intent([
                'source' => 'theta:convex-probe', 'underlyingSymbol' => 'XLF',
                'expirationDate' => '2026-12-18', 'strikePrice' => 58.0,
            ]), $context);

            $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
            $this->assertContains('matrix_admission:matrix_admit_superseded_before_place', $result['verdict']->blockReasons());
            $this->assertSame([], $this->placedRefIds);
        } finally {
            unlink($path);
        }
    }

    public function test_long_put_bto_maps_to_long_put_intent(): void
    {
        $this->fakeMcp();

        $this->executor()->execute($this->intent(['optionType' => 'put']), $this->greenContext());

        $this->assertSame('long_put', RobinhoodTradeJournal::sole()->intent);
    }

    public function test_sell_to_close_maps_position_effect_close(): void
    {
        $this->fakeMcp();

        $this->executor()->execute(
            $this->intent(['side' => 'sell', 'positionEffect' => 'close', 'limitPrice' => 0.48]),
            $this->greenContext(),
        );

        $wire = $this->placedArguments[0];
        $this->assertSame('sell', $wire['legs'][0]['side']);
        $this->assertSame('close', $wire['legs'][0]['position_effect']);
        $this->assertSame('close_long_call', RobinhoodTradeJournal::sole()->intent);
    }

    public function test_review_alert_blocks_placement(): void
    {
        $this->fakeMcp();
        $this->reviewResponse = ['ok' => true, 'alerts' => [['severity' => 'error', 'message' => 'Order would exceed buying power']]];

        $result = $this->executor()->execute($this->intent(), $this->greenContext());

        $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
        $this->assertSame(OptionRailPipeline::BLOCK_REVIEW_ALERT, $result['verdict']->verdict());
        $this->assertSame(1, $this->reviewCalls);
        $this->assertCount(0, $this->placedRefIds, 'a blocking review alert must prevent placement');
        $this->assertSame('risk_aborted', RobinhoodTradeJournal::sole()->status);
    }

    public function test_convex_probe_rechecks_quote_time_after_broker_review_before_placement(): void
    {
        $this->fakeMcp();
        $context = $this->greenContext();
        $context['quote']['updated_at'] = now()->subMinutes(2)->toIso8601String();

        $result = $this->executor()->execute($this->intent(['source' => 'theta:convex-probe']), $context);

        $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
        $this->assertContains('quote_freshness:quote_stale_before_place', $result['verdict']->blockReasons());
        $this->assertSame(1, $this->reviewCalls);
        $this->assertCount(0, $this->placedRefIds);
    }

    public function test_pre_review_block_generates_no_broker_review_traffic(): void
    {
        $this->fakeMcp();

        $context = array_merge($this->greenContext(), ['settled_cash' => null]);
        $result = $this->executor()->execute($this->intent(), $context);

        $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
        $this->assertSame(OptionRailPipeline::BLOCK_CAP, $result['verdict']->verdict());
        $this->assertSame(0, $this->reviewCalls, 'capability/cap blocks must not reach broker review');
        $this->assertCount(0, $this->placedRefIds);
        $this->assertSame(0, HarnessOrderRef::count(), 'no ref is reserved for a pre-review block');
    }

    public function test_rerun_of_placed_option_intent_cannot_double_place(): void
    {
        $this->fakeMcp();

        $executor = $this->executor();
        $first = $executor->execute($this->intent(), $this->greenContext());
        $second = $executor->execute($this->intent(), $this->greenContext());

        $this->assertSame('LIVE_POSTED_NOT_FILLED', $first['classification']);
        $this->assertSame('LIVE_RISK_ABORTED', $second['classification']);
        $this->assertSame(OptionRailPipeline::BLOCK_DUPLICATE, $second['verdict']->verdict());
        $this->assertCount(1, $this->placedRefIds);
        $this->assertSame(1, HarnessOrderRef::count());
    }

    public function test_broker_place_failure_marks_ref_failed_and_journals_rejected(): void
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
            if ($method === 'tools/call') {
                $tool = $payload['params']['name'] ?? '';
                if ($tool === 'review_option_order') {
                    return $this->toolResponse($payload['id'], ['ok' => true, 'alerts' => []]);
                }
                if ($tool === 'place_option_order') {
                    return Http::response('broker unavailable', 503);
                }
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $payload['id'] ?? null, 'result' => []]);
        });

        $executor = $this->executor();
        $intent = $this->intent();

        try {
            $executor->execute($intent, $this->greenContext());
            $this->fail('placement transport failure must rethrow');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('mcp transport failure', $e->getMessage());
        }

        $ref = HarnessOrderRef::sole();
        $this->assertSame('failed', $ref->status);
        $this->assertSame('rejected', RobinhoodTradeJournal::sole()->status);

        // Re-run is blocked until reconciled — never a silent resubmit.
        $second = $executor->execute($intent, $this->greenContext());
        $this->assertSame('LIVE_RISK_ABORTED', $second['classification']);
        $this->assertSame(OptionRailPipeline::BLOCK_DUPLICATE, $second['verdict']->verdict());
        $this->assertContains('duplicate:ref_attempted_requires_reconcile', $second['verdict']->blockReasons());
        $this->assertSame(1, HarnessOrderRef::count());
    }

    public function test_symbolic_intent_without_option_id_is_refused(): void
    {
        $this->fakeMcp();

        $this->expectExceptionMessageMatches('/resolver-stamped option_id/');

        $this->executor()->execute($this->intent(['optionId' => null]), $this->greenContext());
    }
}
