<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Thesis\ThesisSpec;
use App\Robinhood\Thesis\ThesisSpecLeg;
use App\Robinhood\Thesis\ThesisSpecValidator;
use Illuminate\Console\Command;

/**
 * Validate a JSON thesis spec against the lane's coherence discipline
 * (docs/thesis-spec.md, slice E1).
 *
 * Pure and offline BY CONSTRUCTION: this command reads a file, decodes JSON,
 * and runs {@see ThesisSpecValidator}. It touches no broker and writes no
 * journal or spec row — persistence and execution are later slices. It exists
 * so a spec can be proven coherent (in CI, or before authoring) and so the
 * named-blocker vocabulary is exercised the way the options lane's
 * `thesis-add --activate` exercises ThesisIntent::activationBlockers().
 *
 * The cap check (--caps) is an authoring-time sanity guard only. The caps are
 * read from the propagated harness block, never cherry-picked, and the real
 * money-moving enforcement remains the execution rails against live broker
 * truth. Until the dedicated equity-thesis lane block lands (slice E2), the
 * provisional bounds are the allocation per-play/per-day caps and the thesis
 * lane's max-open count; the readback labels them provisional.
 */
class ThesisValidateCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:thesis-validate
        {path : Path to the JSON thesis spec}
        {--caps : Also run the authoring-time lane cap sanity check (provisional bounds until slice E2)}';

    protected $description = 'Validate a JSON thesis spec against the lane coherence discipline (offline; no broker, no writes)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("blocker: spec file not readable: {$path}");

            return self::FAILURE;
        }

        $json = (string) file_get_contents($path);

        try {
            $spec = ThesisSpec::fromJson($json);
        } catch (\JsonException $e) {
            $this->error('blocker: spec is not parseable JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        $caps = [];
        if ((bool) $this->option('caps')) {
            $cfg = $this->assembleConfig();
            $allocation = (array) ($cfg['allocation'] ?? []);
            $thesis = (array) ($cfg['thesis'] ?? []);
            $caps = [
                'per_play_max_usd' => (float) ($allocation['per_play_max_debit'] ?? 0.0),
                'per_day_max_usd' => (float) ($allocation['per_day_max_debit'] ?? 0.0),
                'max_legs' => (int) ($thesis['max_open_positions'] ?? 0),
            ];
        }

        $result = (new ThesisSpecValidator)->validate($spec, $caps);

        $this->line(sprintf(
            'spec %s v%s — %d leg(s): %s',
            $spec->thesisId() === '' ? '(unnamed)' : $spec->thesisId(),
            $spec->specVersion() === '' ? '?' : $spec->specVersion(),
            count($spec->legs()),
            collect($spec->legs())
                ->map(static fn (ThesisSpecLeg $leg): string => $leg->symbol().' '.$leg->assetClass().'/'.$leg->direction())
                ->implode(', '),
        ));

        foreach ($result['warnings'] as $warning) {
            $this->warn("  warning: {$warning}");
        }

        if ($result['blockers'] !== []) {
            $this->error('INVALID — '.count($result['blockers']).' blocker(s):');
            foreach ($result['blockers'] as $blocker) {
                $this->line("  - {$blocker}");
            }

            return self::FAILURE;
        }

        $this->info('VALID — spec is coherent'.($caps === [] ? '' : ' and inside provisional lane caps').'.');

        return self::SUCCESS;
    }
}
