<?php

declare(strict_types=1);

namespace App\Robinhood\Rails;

use Illuminate\Support\Carbon;

/**
 * Deterministic equity session clock, pinned to the broker-published
 * schedule (trading://market-hours): regular 9:30 AM–4:00 PM ET, weekdays.
 * Market holidays are not modeled — on a holiday this reports open and the
 * broker review/placement path is the backstop; the assumption is stamped
 * so readbacks can attribute any such block correctly.
 */
final class SessionClock
{
    private const TZ = 'America/New_York';

    public function isRegularHoursOpen(?Carbon $now = null): bool
    {
        $et = ($now ?? now())->copy()->setTimezone(self::TZ);

        if ($et->isWeekend()) {
            return false;
        }

        $minutes = $et->hour * 60 + $et->minute;

        return $minutes >= (9 * 60 + 30) && $minutes < (16 * 60);
    }

    public function context(?Carbon $now = null): array
    {
        $et = ($now ?? now())->copy()->setTimezone(self::TZ);

        return [
            'session_open' => $this->isRegularHoursOpen($now),
            'session' => 'regular_hours',
            'as_of_et' => $et->toDateTimeString(),
            'schedule_source' => 'trading://market-hours (static schedule)',
            'holidays_modeled' => false,
        ];
    }
}
