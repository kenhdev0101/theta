<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** A process-owned lock: MySQL releases it when a crashed worker disconnects. */
final class ConvexLoopLock
{
    public static function acquire(): ?Closure
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $result = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['theta:convex-auto-loop']);
            if ((int) ($result->acquired ?? 0) !== 1) {
                return null;
            }

            return static function (): void {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', ['theta:convex-auto-loop']);
            };
        }

        // SQLite test and local fallback. Production must use the MySQL
        // connection-owned lock so a crashed worker cannot strand stewardship.
        $lock = Cache::lock('theta:convex-auto-loop', 120);
        if (! $lock->get()) {
            return null;
        }

        return static function () use ($lock): void {
            $lock->release();
        };
    }
}
