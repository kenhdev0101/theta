<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Convex\ConvexEvidence;
use App\Robinhood\Convex\ConvexMatrixSnapshot;
use App\Robinhood\Convex\FeatureMatrixWriter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ConvexEvidenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-22T18:00:00Z'));
        $this->dir = sys_get_temp_dir().'/convex-evidence-test-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function cfg(array $extra = []): array
    {
        return ['convex' => ['evidence' => ['dir' => $this->dir, ...$extra]]];
    }

    public function test_whitelist_preserves_quote_identity_without_credentials_or_payloads(): void
    {
        $recorder = new ConvexEvidence($this->cfg());
        $this->assertTrue($recorder->record('signal_observed', [
            'ticker' => 'SPY', 'matrix_row_hash' => str_repeat('a', 64), 'oauth_token' => 'secret-token',
            'account_number' => 'private-account', 'error' => 'secret-error',
            'quote' => ['bid' => '4.15', 'ask' => '4.18', 'updated_at' => '2026-10-22T17:59:58Z', 'Authorization' => 'secret-header'],
            'samples' => [['option_id' => 'broker-option-id', 'strike_price' => 800, 'payload' => ['token' => 'nested-secret']]],
        ]));
        $raw = file_get_contents($this->dir.'/convex-evidence-2026-10-22.jsonl');
        $row = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('4.18', $row['quote']['ask']);
        $this->assertSame('2026-10-22T17:59:58Z', $row['quote']['updated_at']);
        $this->assertSame('broker-option-id', $row['samples'][0]['option_id']);
        $this->assertStringNotContainsString('secret', $raw);
        $this->assertStringNotContainsString('account', $raw);
    }

    public function test_daily_cap_is_atomic_and_lock_contention_skips_without_waiting(): void
    {
        $recorder = new ConvexEvidence($this->cfg(['max_daily_bytes' => 1024]));
        $file = $this->dir.'/convex-evidence-2026-10-22.jsonl';
        $this->assertTrue($recorder->record('signal_observed', ['ticker' => 'SPY']));
        $lock = fopen($file, 'r+');
        flock($lock, LOCK_EX);
        $start = microtime(true);
        $this->assertFalse($recorder->record('signal_observed', []));
        $this->assertLessThan(0.5, microtime(true) - $start);
        fclose($lock);
        for ($i = 0; $i < 20; $i++) {
            $recorder->record('signal_observed', ['ticker' => 'SPY']);
        }
        clearstatcache(true, $file);
        $this->assertLessThanOrEqual(1024, filesize($file));
        $this->assertFalse($recorder->record('signal_observed', ['ticker' => 'SPY']));
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $this->assertIsArray(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
        }
        $this->assertNotNull($recorder->maintain()['last_drop']);
    }

    public function test_record_cap_disabled_and_unwritable_paths_are_nonfatal(): void
    {
        $huge = array_fill(0, 32, ['quote' => array_fill_keys(['option_id', 'updated_at', 'bid', 'ask', 'delta', 'entry_limit', 'strike_price', 'expiration_date'], str_repeat('x', 160))]);
        $this->assertFalse(ConvexEvidence::stamp($this->cfg(), 'selection_observed', ['samples' => $huge]));
        $this->assertFalse(ConvexEvidence::stamp($this->cfg(['enabled' => false]), 'signal_observed', []));
        file_put_contents($this->dir.'/not-a-directory', 'occupied');
        $this->assertFalse(ConvexEvidence::stamp($this->cfg(['dir' => $this->dir.'/not-a-directory/child']), 'signal_observed', []));
        $this->assertFileDoesNotExist($this->dir.'/convex-evidence-2026-10-22.jsonl');
    }

    public function test_ttl_uses_utc_boundary_and_only_deletes_valid_expired_own_files(): void
    {
        foreach (['convex-evidence-2026-10-08.jsonl', 'convex-evidence-2026-10-09.jsonl', 'convex-evidence-2026-10-22.jsonl',
            'convex-evidence-2026-02-30.jsonl', 'convex_probe-2026-01-01.jsonl', 'convex_feature_matrix.csv'] as $name) {
            file_put_contents($this->dir.'/'.$name, "test\n");
        }
        symlink($this->dir.'/convex_feature_matrix.csv', $this->dir.'/convex-evidence-2026-01-01.jsonl');
        $recorder = new ConvexEvidence($this->cfg());
        $this->assertSame(5, $recorder->maintain()['expired_bytes']);
        $this->assertFileExists($this->dir.'/convex-evidence-2026-10-08.jsonl');
        $this->assertSame(5, $recorder->maintain(true)['removed_bytes']);
        $this->assertFileDoesNotExist($this->dir.'/convex-evidence-2026-10-08.jsonl');
        $this->assertCount(6, glob($this->dir.'/*'));
        $this->assertFileExists($this->dir.'/convex-evidence-2026-10-09.jsonl');
        $this->assertFileExists($this->dir.'/convex_feature_matrix.csv');
        $this->assertTrue(is_link($this->dir.'/convex-evidence-2026-01-01.jsonl'));
    }

    public function test_matrix_hash_matches_csv_readback_with_boolean_and_empty_cells(): void
    {
        $row = ['ticker' => 'SPY', 'dte' => 57, 'snapshot_ts' => now()->toIso8601ZuluString(),
            'verdict' => 'ADMIT', 'failed_signals' => '', 'pending_signals' => '', 'far_delta' => 0.2,
            'boolean' => true, 'outcome' => null, 'diagnostic' => 'comma,quote"slash/'];
        $path = $this->dir.'/matrix.csv';
        (new FeatureMatrixWriter($path))->append(array_keys($row), $row);
        $actual = (new ConvexMatrixSnapshot($path))->latest();
        $this->assertSame($actual['SPY:57']['hash'], FeatureMatrixWriter::rowHash($row));
    }
}
