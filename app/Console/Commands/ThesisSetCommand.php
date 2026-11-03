<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\ThesisIntent;
use Illuminate\Console\Command;

/**
 * Transition a thesis intent: arm it, park it, or close it with a reason.
 * Activation re-runs the playbook-discipline gate every time — a thesis
 * edited into incoherence cannot be re-armed by habit.
 */
class ThesisSetCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:thesis-set
        {id : Thesis intent id}
        {--activate : Arm the thesis (runs the activation gate)}
        {--park : Stand the thesis down without closing it}
        {--close= : Close the thesis with a reason, e.g. thesis_played_out}';

    protected $description = 'Activate, park, or close a thesis intent';

    public function handle(): int
    {
        $intent = ThesisIntent::query()->find((int) $this->argument('id'));
        if ($intent === null) {
            $this->error('blocker: thesis intent not found');

            return self::FAILURE;
        }

        $requested = array_filter([
            'activate' => (bool) $this->option('activate'),
            'park' => (bool) $this->option('park'),
            'close' => $this->option('close') !== null,
        ]);

        if (count($requested) !== 1) {
            $this->error('blocker: pass exactly one of --activate, --park, --close=reason');

            return self::FAILURE;
        }

        if ($this->option('activate')) {
            $laneCfg = (array) (($this->assembleConfig()['thesis'] ?? []));
            $blockers = $intent->activationBlockers($laneCfg);
            if ($blockers !== []) {
                $this->warn("thesis {$intent->id} activation blocked:");
                foreach ($blockers as $blocker) {
                    $this->line("  - {$blocker}");
                }

                return self::FAILURE;
            }

            $intent->status = ThesisIntent::STATUS_ACTIVE;
            $intent->activated_at = now();
            $intent->parked_at = null;
        } elseif ($this->option('park')) {
            $intent->status = ThesisIntent::STATUS_PARKED;
            $intent->parked_at = now();
        } else {
            $intent->status = ThesisIntent::STATUS_CLOSED;
            $intent->close_reason = trim((string) $this->option('close'));
            $intent->closed_at = now();
        }

        $intent->save();
        $this->info("thesis {$intent->id} -> {$intent->status}");

        return self::SUCCESS;
    }
}
