<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Rails\SessionClock;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SessionClockTest extends TestCase
{
    private SessionClock $clock;

    protected function setUp(): void
    {
        $this->clock = new SessionClock;
    }

    private function et(string $time): Carbon
    {
        return Carbon::parse($time, 'America/New_York');
    }

    public function test_regular_hours_boundaries(): void
    {
        // 2026-06-12 is a Friday.
        $this->assertFalse($this->clock->isRegularHoursOpen($this->et('2026-06-12 09:29')));
        $this->assertTrue($this->clock->isRegularHoursOpen($this->et('2026-06-12 09:30')));
        $this->assertTrue($this->clock->isRegularHoursOpen($this->et('2026-06-12 12:00')));
        $this->assertTrue($this->clock->isRegularHoursOpen($this->et('2026-06-12 15:59')));
        $this->assertFalse($this->clock->isRegularHoursOpen($this->et('2026-06-12 16:00')));
    }

    public function test_premarket_is_closed(): void
    {
        $this->assertFalse($this->clock->isRegularHoursOpen($this->et('2026-06-12 05:30')));
    }

    public function test_weekend_is_closed(): void
    {
        $this->assertFalse($this->clock->isRegularHoursOpen($this->et('2026-06-13 12:00')));
        $this->assertFalse($this->clock->isRegularHoursOpen($this->et('2026-06-14 12:00')));
    }

    public function test_utc_input_is_converted_to_et(): void
    {
        // 14:00 UTC = 10:00 ET on 2026-06-12 (EDT).
        $this->assertTrue($this->clock->isRegularHoursOpen(Carbon::parse('2026-06-12 14:00', 'UTC')));
        // 09:30 UTC = 05:30 ET — premarket.
        $this->assertFalse($this->clock->isRegularHoursOpen(Carbon::parse('2026-06-12 09:30', 'UTC')));
    }

    public function test_context_stamps_assumptions(): void
    {
        $context = $this->clock->context($this->et('2026-06-12 05:30'));

        $this->assertFalse($context['session_open']);
        $this->assertFalse($context['holidays_modeled']);
        $this->assertSame('2026-06-12 05:30:00', $context['as_of_et']);
    }
}
