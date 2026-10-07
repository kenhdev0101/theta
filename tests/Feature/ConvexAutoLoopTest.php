<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConvexProbeRun;
use App\Robinhood\Convex\ConvexAutoLoop;
use App\Robinhood\Convex\ConvexLoopLock;
use App\Robinhood\Convex\ConvexMatrixSnapshot;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class ConvexAutoLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_lock_serializes_and_releases(): void
    {
        $release = ConvexLoopLock::acquire();
        $this->assertNotNull($release);
        try {
            $this->assertNull(ConvexLoopLock::acquire());
        } finally {
            $release();
        }
        $again = ConvexLoopLock::acquire();
        $this->assertNotNull($again);
        $again();
    }

    public function test_matrix_transition_maps_only_fresh_structural_failures_to_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:02:00Z'));
        $path = tempnam(sys_get_temp_dir(), 'convex-matrix-');
        $this->assertIsString($path);
        try {
            $this->writeMatrix($path, [
                ['XLF', '72', '2026-10-07T18:00:00Z', 'ADMIT', '', '', '0.20'],
                ['XLF', '72', '2026-10-07T18:01:00Z', 'REJECT', 'conventional_smirk|executable_wing', '', '0.20'],
                ['XLP', '72', '2026-10-07T18:01:00Z', 'REJECT', 'executable_wing', '', '0.20'],
            ]);
            $latest = (new ConvexMatrixSnapshot($path))->latest();
            $this->assertCount(2, $latest);
            $this->assertSame('REJECT', $latest['XLF:72']['row']['verdict']);
            $this->assertSame(['conventional_smirk'], ConvexMatrixSnapshot::structuralFailures($latest['XLF:72']['row']));
            $this->assertSame([], ConvexMatrixSnapshot::structuralFailures($latest['XLP:72']['row']));
            $this->assertSame([], ConvexMatrixSnapshot::structuralFailures([
                'verdict' => 'REJECT', 'failed_signals' => 'conventional_smirk', 'pending_signals' => 'fringe_not_rich',
            ]));
        } finally {
            unlink($path);
        }
    }

    public function test_loop_persists_expiry_before_a_broker_failure_and_does_not_enter_when_disarmed(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:02:00Z'));
        $path = tempnam(sys_get_temp_dir(), 'convex-matrix-');
        $this->assertIsString($path);
        try {
            $this->writeMatrix($path, [
                ['XLF', '72', '2026-10-07T18:01:00Z', 'REJECT', 'fringe_not_rich', '', '0.20'],
                ['XLP', '72', '2026-10-07T18:01:00Z', 'ADMIT', '', '', '0.20'],
            ]);
            $run = ConvexProbeRun::query()->create([
                'thesis_id' => 'convex-auto-existing', 'leg_id' => 'near-delta-call', 'account_number' => '872449178',
                'status' => 'held', 'underlying_symbol' => 'XLF', 'option_type' => 'call',
                'spec' => $this->boundSpec(), 'spec_hash' => str_repeat('a', 64),
                'option_id' => 'option-xlf', 'expiration_date' => '2026-12-18',
                'entry_submitted_at' => Carbon::parse('2026-10-07T18:00:00Z'),
            ]);
            $client = $this->createMock(RobinhoodMcpClient::class);
            $client->expects($this->once())->method('getOptionOrders')->willThrowException(new RuntimeException('broker unavailable'));
            $cfg = [
                'agentic_account' => '872449178', 'kill_switch' => false,
                'convex' => ['matrix_path' => $path, 'auto_entry_enabled' => false, 'tickers' => ['XLF', 'XLP']],
            ];
            $events = (new ConvexAutoLoop($client, $cfg, new JsonlRecorder(sys_get_temp_dir())))->run();

            $this->assertSame('matrix-'.substr($this->rowHash(['XLF', '72', '2026-10-07T18:01:00Z', 'REJECT', 'fringe_not_rich', '', '0.20']), 0, 32),
                $run->fresh()->thesis_expiry_event_id);
            $this->assertSame('convex_matrix_transition', $run->fresh()->thesis_expiry_source);
            $this->assertSame('THESIS_EXPIRED', $run->fresh()->stewardshipLabel());
            $this->assertSame(1, ConvexProbeRun::query()->count());
            $this->assertSame('thesis_expired', $events[0]['event']);
            $this->assertSame('stewardship_blocked', $events[1]['event']);
        } finally {
            unlink($path);
        }
    }

    public function test_fresh_admit_stages_and_checks_entry_without_a_manual_command(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:02:00Z'));
        $path = tempnam(sys_get_temp_dir(), 'convex-matrix-');
        $this->assertIsString($path);
        try {
            $this->writeMatrix($path, [
                ['XLF', '72', '2026-10-07T18:01:00Z', 'ADMIT', '', '', '0.20'],
            ]);
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
            $cfg = [
                'agentic_account' => '872449178', 'kill_switch' => false,
                'convex' => ['matrix_path' => $path, 'auto_entry_enabled' => true,
                    'auto_max_hold_minutes' => 5, 'tickers' => ['XLF'], 'bucket' => 'index'],
            ];

            $events = (new ConvexAutoLoop($client, $cfg, new JsonlRecorder(sys_get_temp_dir())))->run();

            $this->assertSame(['staged', 'entry_checked'], array_column($events, 'event'));
            $this->assertSame(1, ConvexProbeRun::query()->count());
            $run = ConvexProbeRun::query()->firstOrFail();
            $this->assertSame('staged', $run->status);
            $this->assertSame('settled_cash_or_buying_power_unverified', $run->block_reason);
            $this->assertSame('XLF', $run->underlying_symbol);
            $this->assertSame('external_event:thesis_expired', data_get($run->spec, 'legs.0.invalidation.thesis_stop'));
        } finally {
            unlink($path);
        }
    }

    public function test_two_contracts_receive_independent_matrix_expiry_events(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:02:00Z'));
        $path = tempnam(sys_get_temp_dir(), 'convex-matrix-');
        $this->assertIsString($path);
        try {
            $this->writeMatrix($path, [
                ['XLP', '72', '2026-10-07T18:01:00Z', 'REJECT', 'conventional_smirk', '', '0.20'],
                ['XLF', '72', '2026-10-07T18:01:00Z', 'REJECT', 'fringe_not_rich', '', '0.20'],
            ]);
            foreach (['XLF', 'XLP'] as $ticker) {
                ConvexProbeRun::query()->create([
                    'thesis_id' => 'convex-'.$ticker, 'leg_id' => 'near-delta-call', 'account_number' => '872449178',
                    'status' => 'held', 'underlying_symbol' => $ticker, 'option_type' => 'call',
                    'spec' => $this->boundSpec(), 'spec_hash' => str_repeat('a', 64),
                    'option_id' => 'option-'.$ticker, 'entry_submitted_at' => Carbon::parse('2026-10-07T18:00:00Z'),
                ]);
            }
            $client = $this->createMock(RobinhoodMcpClient::class);
            $client->expects($this->exactly(2))->method('getOptionOrders')
                ->willThrowException(new RuntimeException('broker unavailable'));
            $cfg = ['agentic_account' => '872449178', 'kill_switch' => false,
                'convex' => ['matrix_path' => $path, 'auto_entry_enabled' => false, 'tickers' => ['XLF', 'XLP']]];

            $events = (new ConvexAutoLoop($client, $cfg, new JsonlRecorder(sys_get_temp_dir())))->run();

            $this->assertSame(2, count(array_filter($events, fn (array $event): bool => $event['event'] === 'thesis_expired')));
            $this->assertNotSame(
                ConvexProbeRun::query()->where('thesis_id', 'convex-XLF')->firstOrFail()->thesis_expiry_event_id,
                ConvexProbeRun::query()->where('thesis_id', 'convex-XLP')->firstOrFail()->thesis_expiry_event_id,
            );
        } finally {
            unlink($path);
        }
    }

    public function test_newer_admit_prevents_an_older_reject_from_expiring_a_position(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:03:00Z'));
        $path = tempnam(sys_get_temp_dir(), 'convex-matrix-');
        $this->assertIsString($path);
        try {
            $this->writeMatrix($path, [
                ['XLF', '72', '2026-10-07T18:01:00Z', 'REJECT', 'conventional_smirk', '', '0.20'],
                ['XLF', '72', '2026-10-07T18:02:00Z', 'ADMIT', '', '', '0.20'],
            ]);
            $run = ConvexProbeRun::query()->create([
                'thesis_id' => 'convex-held', 'leg_id' => 'near-delta-call', 'account_number' => '872449178',
                'status' => 'held', 'underlying_symbol' => 'XLF', 'option_type' => 'call',
                'spec' => $this->boundSpec(), 'spec_hash' => str_repeat('a', 64),
                'option_id' => 'option-xlf', 'entry_submitted_at' => Carbon::parse('2026-10-07T18:00:00Z'),
            ]);
            $client = $this->createMock(RobinhoodMcpClient::class);
            $client->method('getOptionOrders')->willThrowException(new RuntimeException('broker unavailable'));
            $cfg = ['agentic_account' => '872449178', 'kill_switch' => true,
                'convex' => ['matrix_path' => $path, 'auto_entry_enabled' => false]];

            $events = (new ConvexAutoLoop($client, $cfg, new JsonlRecorder(sys_get_temp_dir())))->run();

            $this->assertNull($run->fresh()->thesis_expired_at);
            $this->assertSame(['stewardship_blocked'], array_column($events, 'event'));
        } finally {
            unlink($path);
        }
    }

    public function test_unbound_manual_probe_is_stewarded_without_a_matrix_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07T18:03:00Z'));
        $path = tempnam(sys_get_temp_dir(), 'convex-matrix-');
        $this->assertIsString($path);
        try {
            $this->writeMatrix($path, [
                ['XLF', '72', '2026-10-07T18:02:00Z', 'REJECT', 'conventional_smirk', '', '0.20'],
            ]);
            $run = ConvexProbeRun::query()->create([
                'thesis_id' => 'convex-manual', 'leg_id' => 'probe', 'account_number' => '872449178',
                'status' => 'held', 'underlying_symbol' => 'XLF', 'option_type' => 'call',
                'spec' => ['legs' => [['dte' => ['min' => 72]]]], 'spec_hash' => str_repeat('a', 64),
                'option_id' => 'option-xlf', 'entry_submitted_at' => Carbon::parse('2026-10-07T18:00:00Z'),
            ]);
            $client = $this->createMock(RobinhoodMcpClient::class);
            $client->method('getOptionOrders')->willThrowException(new RuntimeException('broker unavailable'));
            $cfg = ['agentic_account' => '872449178', 'kill_switch' => true,
                'convex' => ['matrix_path' => $path, 'auto_entry_enabled' => false]];

            (new ConvexAutoLoop($client, $cfg, new JsonlRecorder(sys_get_temp_dir())))->run();

            $this->assertNull($run->fresh()->thesis_expired_at);
        } finally {
            unlink($path);
        }
    }

    /** @param list<list<string>> $rows */
    private function writeMatrix(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        $this->assertIsResource($handle);
        fputcsv($handle, ['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta', 'bucket', 'far_strike', 'spot']);
        foreach ($rows as $row) {
            fputcsv($handle, [...$row, 'index', '58', '53']);
        }
        fclose($handle);
    }

    /** @param list<string> $row */
    private function rowHash(array $row): string
    {
        return hash('sha256', json_encode(array_combine(
            ['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta', 'bucket', 'far_strike', 'spot'],
            [...$row, 'index', '58', '53'],
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function boundSpec(): array
    {
        return [
            'matrix_source' => ['row_sha256' => str_repeat('b', 64)],
            'legs' => [['dte' => ['min' => 72], 'invalidation' => ['thesis_stop' => 'external_event:thesis_expired']]],
        ];
    }
}
