<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class ThesisValidateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/theta-spec-'.uniqid();
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeSpec(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function coherentJson(): string
    {
        $future = Carbon::today()->addDays(30)->format('Y-m-d');

        return json_encode([
            'spec_version' => '1',
            'thesis_id' => 'cmd-test',
            'thesis' => 'a coherent reason',
            'legs' => [[
                'leg_id' => 'qqq', 'asset_class' => 'equity', 'symbol' => 'QQQ', 'direction' => 'long',
                'sizing' => ['mode' => 'notional', 'target_usd' => 5.0, 'max_usd' => 6.0],
                'entry' => ['type' => 'marketable_limit', 'offset_bps' => 10, 'max_chase_bps' => 50],
                'invalidation' => ['price_stop' => 690.0, 'time_stop' => $future, 'target_pct' => 15.0],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    public function test_coherent_spec_validates_successfully(): void
    {
        $path = $this->writeSpec('good.json', $this->coherentJson());

        $this->artisan('theta:thesis-validate', ['path' => $path])
            ->expectsOutputToContain('VALID')
            ->assertExitCode(0);
    }

    public function test_incoherent_spec_fails_with_named_blockers(): void
    {
        $path = $this->writeSpec('bad.json', json_encode([
            'spec_version' => '1',
            'thesis_id' => 'bad',
            'thesis' => '',
            'legs' => [[
                'asset_class' => 'equity', 'symbol' => 'QQQ', 'direction' => 'short',
                'sizing' => ['mode' => 'notional', 'target_usd' => 5.0],
                'invalidation' => [],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('theta:thesis-validate', ['path' => $path])
            ->expectsOutputToContain('INVALID')
            ->assertExitCode(1);
    }

    public function test_unparseable_json_is_reported_not_fatal(): void
    {
        $path = $this->writeSpec('broken.json', '{ "spec_version": "1", ');

        $this->artisan('theta:thesis-validate', ['path' => $path])
            ->expectsOutputToContain('not parseable JSON')
            ->assertExitCode(1);
    }

    public function test_missing_file_is_reported(): void
    {
        $this->artisan('theta:thesis-validate', ['path' => $this->dir.'/nope.json'])
            ->expectsOutputToContain('not readable')
            ->assertExitCode(1);
    }

    public function test_shipped_example_spec_stays_coherent(): void
    {
        $example = base_path('specs/ai-midterm-2026.json');
        $this->assertFileExists($example, 'the shipped example spec must exist');

        $this->artisan('theta:thesis-validate', ['path' => $example])
            ->assertExitCode(0);
    }
}
