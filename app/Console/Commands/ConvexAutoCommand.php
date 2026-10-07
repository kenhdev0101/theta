<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Convex\ConvexAutoLoop;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;
use Throwable;

final class ConvexAutoCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:convex-auto';

    protected $description = 'Monitor convex matrix transitions and steward every open probe';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);
        try {
            $events = (new ConvexAutoLoop(RobinhoodMcpClient::fromConfig($cfg, $recorder), $cfg, $recorder))->run();
            $failed = false;
            foreach ($events as $event) {
                $this->line(json_encode($event, JSON_UNESCAPED_SLASHES));
                $failed = $failed || str_ends_with($event['event'], '_blocked');
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('convex auto loop blocked: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
