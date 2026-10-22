<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HarnessOrderRef;
use App\Robinhood\Convex\ConvexProbeLifecycle;
use App\Robinhood\Mcp\BrokerCreditExhausted;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use App\Robinhood\Thesis\ThesisSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ConvexCreditDeferralTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('evidenceAvailability')]
    public function test_entry_throttle_returns_to_staged_and_later_reuses_reserved_ref(bool $unwritableEvidence): void
    {
        if ($unwritableEvidence) {
            config()->set('harness.convex.evidence.dir', __FILE__.'/not-a-directory');
        }
        $this->travelTo(Carbon::parse('2026-10-07T18:00:00Z'));
        $client = $this->client();
        $refs = [];
        $client->expects($this->exactly(2))->method('placeOptionOrder')->willReturnCallback(
            static function (array $payload, string $ref) use (&$refs): array {
                $refs[] = $ref;
                if (count($refs) === 1) {
                    throw new BrokerCreditExhausted('fixture local budget exhausted');
                }

                return ['id' => 'entry-1'];
            },
        );
        $service = $this->service($client);
        $run = $service->enter($service->stage($this->spec()));
        $this->assertSame('staged', $run->status, (string) $run->block_reason);
        $this->assertSame(ConvexProbeLifecycle::ENTRY_CREDIT_DEFERRED, $run->block_reason);
        $this->assertNull($run->entry_submitted_at);
        $this->assertNull($run->entry_order_id);
        $this->assertSame('reserved', HarnessOrderRef::sole()->status);
        $this->travel(1)->minutes();
        $run = $service->enter($run);
        $this->assertSame('entry_pending', $run->status, (string) $run->block_reason);
        $this->assertSame('entry-1', $run->entry_order_id);
        $this->assertSame($refs[0], $refs[1]);
        $this->assertSame(1, HarnessOrderRef::count());
    }

    public static function evidenceAvailability(): array
    {
        return ['normal evidence' => [false], 'evidence IO failure' => [true]];
    }

    public function test_generic_error_with_credit_text_keeps_entry_ambiguous(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:00:00Z'));
        $client = $this->client();
        $client->expects($this->once())->method('placeOptionOrder')
            ->willThrowException(new \RuntimeException('broker credits exhausted after unknown transmission'));
        $service = $this->service($client);
        $run = $service->enter($service->stage($this->spec()));
        $this->assertSame('ambiguous', $run->status, (string) $run->block_reason);
        $this->assertNotNull($run->entry_submitted_at);
        $this->assertSame('failed', HarnessOrderRef::sole()->status);
    }

    #[DataProvider('exitThrottlePoints')]
    public function test_exit_throttle_preserves_owned_position_and_retries_later(string $point): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:00:00Z'));
        $client = $this->client(held: true, throttleExitContext: $point === 'context');
        $placements = 0;
        $client->expects($point === 'context' ? $this->once() : $this->exactly(2))
            ->method('placeOptionOrder')->willReturnCallback(
                static function () use (&$placements, $point): array {
                    if (++$placements === 1 && $point === 'placement') {
                        throw new BrokerCreditExhausted('fixture local budget exhausted');
                    }

                    return ['id' => 'exit-1'];
                },
            );
        $service = $this->service($client);
        $run = $service->stage($this->spec());
        $run->forceFill(['status' => 'held', 'option_id' => 'option-1', 'chain_id' => 'chain-iwm',
            'entry_order_id' => 'entry-1', 'entry_filled_qty' => 1, 'entry_average_price' => 0.50,
            'expiration_date' => '2026-10-16', 'strike_price' => 290,
            'opened_at' => now()->subMinutes(11), 'exit_due_at' => now()->subMinute()])->save();
        $run = $service->tick($run);
        $this->assertSame('held', $run->status, (string) $run->block_reason);
        $this->assertSame('exit_broker_credit_throttled_retry_later', $run->block_reason);
        $this->assertNull($run->exit_intent_key);
        $this->assertNull($run->exit_submitted_at);
        $this->assertSame(1.0, (float) $run->entry_filled_qty);
        $this->travel(1)->minutes();
        $run = $service->tick($run);
        $this->assertSame('exit_pending', $run->status, (string) $run->block_reason);
        $this->assertSame('exit-1', $run->exit_order_id);
        $this->assertSame(2, $run->exit_attempt);
    }

    public static function exitThrottlePoints(): array
    {
        return [['context'], ['placement']];
    }

    private function client(bool $held = false, bool $throttleExitContext = false): RobinhoodMcpClient
    {
        $client = $this->createMock(RobinhoodMcpClient::class);
        $portfolioCalls = 0;
        $client->method('getPortfolio')->willReturnCallback(static function () use (&$portfolioCalls, $throttleExitContext): array {
            if (++$portfolioCalls === 1 && $throttleExitContext) {
                throw new BrokerCreditExhausted('fixture local budget exhausted');
            }

            return ['cash' => '100.00', 'settled_cash' => '100.00', 'buying_power' => '100.00'];
        });
        $client->method('getEquityOrders')->willReturn(['orders' => []]);
        $client->method('getOptionOrders')->willReturn(['orders' => $held ? [[
            'id' => 'entry-1', 'state' => 'filled', 'processed_quantity' => '1',
            'legs' => [['option_id' => 'option-1', 'side' => 'buy', 'position_effect' => 'open',
                'executions' => [['quantity' => '1', 'price' => '0.50', 'timestamp' => '2026-10-07T17:49:00Z']]]],
        ]] : []]);
        $client->method('getOptionPositions')->willReturn(['positions' => $held ? [[
            'option_id' => 'option-1', 'quantity' => '1', 'chain_symbol' => 'IWM', 'average_price' => '0.50',
        ]] : []]);
        $client->method('getAccountCapability')->willReturn(['account_number' => 'fixture-account',
            'option_level' => 'option_level_2', 'agentic_allowed' => true]);
        $client->method('listTools')->willReturn(array_map(static fn ($name) => ['name' => $name],
            (array) config('harness.options.required_tools')));
        $client->method('getOptionChains')->willReturn(['chains' => [[
            'id' => 'chain-iwm', 'expiration_dates' => ['2026-10-16'],
        ]]]);
        $client->method('getEquityQuotes')->willReturn(['results' => [[
            'quote' => ['symbol' => 'IWM', 'last_trade_price' => '280.00'],
        ]]]);
        $client->method('getOptionInstruments')->willReturn(['instruments' => [[
            'id' => 'option-1', 'chain_id' => 'chain-iwm', 'chain_symbol' => 'IWM',
            'expiration_date' => '2026-10-16', 'strike_price' => '290.00',
            'type' => 'call', 'state' => 'active', 'tradability' => 'tradable',
        ]]]);
        $client->method('getOptionQuotes')->willReturnCallback(static fn () => ['results' => [[
            'quote' => ['instrument_id' => 'option-1', 'bid_price' => '0.48', 'ask_price' => '0.50',
                'delta' => '0.20', 'updated_at' => now()->toIso8601String()],
        ]]]);
        $client->method('reviewOptionOrder')->willReturn(['ok' => true, 'alerts' => []]);

        return $client;
    }

    private function service(RobinhoodMcpClient $client): ConvexProbeLifecycle
    {
        return new ConvexProbeLifecycle($client, array_replace_recursive((array) config('harness'), [
            'agentic_account' => 'fixture-account', 'kill_switch' => false, 'options' => ['enabled' => true],
        ]), new JsonlRecorder(sys_get_temp_dir().'/theta-credit-test-'.getmypid()));
    }

    private function spec(): ThesisSpec
    {
        return ThesisSpec::fromArray([
            'spec_version' => '1', 'thesis_id' => 'credit-test', 'thesis' => 'fixture lifecycle',
            'legs' => [[
                'leg_id' => 'probe', 'role' => 'probe', 'asset_class' => 'option', 'symbol' => 'IWM',
                'direction' => 'long', 'structure' => 'single_leg_long_call',
                'dte' => ['min' => 7, 'max' => 21], 'moneyness' => ['mode' => 'delta', 'target' => 0.20],
                'sizing' => ['mode' => 'balance_fraction', 'fraction' => 1.0],
                'entry' => ['type' => 'marketable_limit'],
                'invalidation' => ['time_stop' => '2026-10-10'],
                'fast_exit' => ['max_hold_minutes' => 10, 'profit_target_mult' => 1.30, 'hard_stop_pct' => 30],
            ]],
        ]);
    }
}
