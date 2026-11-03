<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ThesisIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ThesisCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function addArgs(array $overrides = []): array
    {
        return array_merge([
            '--symbol' => 'C',
            '--direction' => 'bullish',
            '--dte-min' => '30',
            '--dte-max' => '70',
            '--max-debit' => '350',
            '--thesis' => 'NIM expansion under a steepening curve.',
            '--catalyst' => 'First FOMC under the new chair.',
            '--price-stop' => '125',
            '--time-stop' => Carbon::today()->addDays(45)->toDateString(),
            '--profit-target-bps' => '6000',
        ], $overrides);
    }

    public function test_add_creates_a_draft_with_derived_structure(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs())->assertSuccessful();

        $intent = ThesisIntent::query()->sole();
        $this->assertSame('C', $intent->underlying_symbol);
        $this->assertSame(ThesisIntent::STATUS_DRAFT, $intent->status);
        $this->assertSame(ThesisIntent::STRUCTURE_LONG_CALL, $intent->structure);
        $this->assertSame([], $intent->activationBlockers());
    }

    public function test_add_with_activate_arms_a_coherent_thesis(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs(['--activate' => true]))->assertSuccessful();

        $intent = ThesisIntent::query()->sole();
        $this->assertSame(ThesisIntent::STATUS_ACTIVE, $intent->status);
        $this->assertNotNull($intent->activated_at);
    }

    public function test_add_with_activate_refuses_missing_kill_triggers(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs([
            '--activate' => true,
            '--price-stop' => null,
        ]))->assertFailed();

        $intent = ThesisIntent::query()->sole();
        $this->assertSame(ThesisIntent::STATUS_DRAFT, $intent->status);
        $this->assertContains('price_stop_missing', $intent->activationBlockers());
    }

    public function test_add_refuses_debit_above_lane_per_play_cap(): void
    {
        config()->set('harness.thesis.per_play_max_debit', 100.0);

        $this->artisan('theta:thesis-add', $this->addArgs([
            '--activate' => true,
            '--max-debit' => '350',
        ]))->assertFailed();

        $this->assertSame(ThesisIntent::STATUS_DRAFT, ThesisIntent::query()->sole()->status);
    }

    public function test_bearish_direction_derives_long_put(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs([
            '--symbol' => 'lmt',
            '--direction' => 'bearish',
        ]))->assertSuccessful();

        $intent = ThesisIntent::query()->sole();
        $this->assertSame('LMT', $intent->underlying_symbol);
        $this->assertSame(ThesisIntent::STRUCTURE_LONG_PUT, $intent->structure);
    }

    public function test_set_activate_park_close_lifecycle(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs())->assertSuccessful();
        $intent = ThesisIntent::query()->sole();

        $this->artisan('theta:thesis-set', ['id' => $intent->id, '--activate' => true])->assertSuccessful();
        $this->assertSame(ThesisIntent::STATUS_ACTIVE, $intent->refresh()->status);

        $this->artisan('theta:thesis-set', ['id' => $intent->id, '--park' => true])->assertSuccessful();
        $this->assertSame(ThesisIntent::STATUS_PARKED, $intent->refresh()->status);
        $this->assertNotNull($intent->parked_at);

        $this->artisan('theta:thesis-set', ['id' => $intent->id, '--close' => 'thesis_played_out'])->assertSuccessful();
        $intent->refresh();
        $this->assertSame(ThesisIntent::STATUS_CLOSED, $intent->status);
        $this->assertSame('thesis_played_out', $intent->close_reason);
        $this->assertNotNull($intent->closed_at);
    }

    public function test_set_refuses_activation_of_incoherent_thesis(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs(['--profit-target-bps' => null]))->assertSuccessful();
        $intent = ThesisIntent::query()->sole();

        $this->artisan('theta:thesis-set', ['id' => $intent->id, '--activate' => true])->assertFailed();
        $this->assertSame(ThesisIntent::STATUS_DRAFT, $intent->refresh()->status);
    }

    public function test_set_requires_exactly_one_transition(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs())->assertSuccessful();
        $intent = ThesisIntent::query()->sole();

        $this->artisan('theta:thesis-set', ['id' => $intent->id, '--activate' => true, '--park' => true])->assertFailed();
        $this->artisan('theta:thesis-set', ['id' => $intent->id])->assertFailed();
    }

    public function test_list_shows_standing_book_and_blockers(): void
    {
        $this->artisan('theta:thesis-add', $this->addArgs(['--activate' => true]))->assertSuccessful();
        $this->artisan('theta:thesis-add', $this->addArgs([
            '--symbol' => 'KRE',
            '--price-stop' => null,
        ]))->assertSuccessful();

        $this->artisan('theta:thesis-list')
            ->expectsOutputToContain('price_stop_missing')
            ->assertSuccessful();
    }
}
