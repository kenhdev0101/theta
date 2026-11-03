<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IntegratedScheduleTest extends TestCase
{
    #[DataProvider('thesisPostures')]
    public function test_thesis_registration_preserves_convex_stewardship(bool $enabled): void
    {
        config()->set('harness.thesis.enabled', $enabled);
        $schedule = new Scheduler('UTC');
        Schedule::swap($schedule);
        require base_path('routes/console.php');

        $commands = array_map(static fn ($event): string => (string) $event->command, $schedule->events());
        $count = static fn (string $needle): int => count(array_filter(
            $commands, static fn (string $command): bool => str_contains($command, $needle),
        ));

        foreach (['theta:convex-auto', 'theta:convex-reconcile', 'theta:convex-ingest', 'theta:convex-evidence --prune'] as $command) {
            $this->assertSame(1, $count($command), $command.' must retain exactly one scheduler registration');
        }
        $this->assertSame($enabled ? 2 : 0, $count('theta:thesis-assemble'));
        $this->assertSame($enabled ? 1 : 0, $count('theta:thesis-execute'));
        $this->assertSame($enabled ? 1 : 0, $count('theta:option-reconcile --strategy=thesis_position'));
        $this->assertSame($enabled ? 1 : 0, $count('theta:option-reconcile'));
        foreach (['theta:convex-probe tick', 'theta:option-autopilot', 'theta:option-intent-producer', 'theta:cockpit-snapshot', 'theta:cockpit-monitor'] as $command) {
            $this->assertSame(0, $count($command), $command.' must not be activated by branch integration');
        }
    }

    public function test_basket_schedule_is_separate_from_options_thesis(): void
    {
        config()->set('harness.thesis.enabled', false);
        config()->set('harness.basket.enabled', true);
        $schedule = new Scheduler('UTC');
        Schedule::swap($schedule);
        require base_path('routes/console.php');
        $commands = array_map(static fn ($event): string => (string) $event->command, $schedule->events());
        $this->assertCount(5, $commands);
        $this->assertCount(1, array_filter($commands, static fn ($command): bool => str_contains($command, 'theta:basket-rebalance --arm --trigger=scheduled')));
        $this->assertCount(0, array_filter($commands, static fn ($command): bool => str_contains($command, 'theta:thesis-')));
    }

    public static function thesisPostures(): array
    {
        return ['production posture' => [false], 'explicit thesis opt-in' => [true]];
    }
}
