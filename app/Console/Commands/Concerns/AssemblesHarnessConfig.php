<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Full-block harness config propagation for commands.
 *
 * The firm's known inert-patch failure mode is a command cherry-picking cfg
 * keys so a live config value never reaches the service path. Commands
 * propagate the ENTIRE harness block; services pull what they need from it.
 * The config-propagation regression test exercises this assembly directly.
 */
trait AssemblesHarnessConfig
{
    public function assembleConfig(): array
    {
        return (array) config('harness');
    }
}
