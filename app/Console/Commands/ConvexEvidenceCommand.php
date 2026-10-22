<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Robinhood\Convex\ConvexEvidence;
use Illuminate\Console\Command;

final class ConvexEvidenceCommand extends Command
{
    protected $signature = 'theta:convex-evidence {--prune : Remove only expired evidence day files; otherwise read-only}';

    protected $description = 'Report convex evidence storage/drop health and optionally apply its UTC-day TTL';

    public function handle(): int
    {
        $result = (new ConvexEvidence((array) config('harness')))->maintain((bool) $this->option('prune'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->option('prune') && $result['expired_bytes'] !== $result['removed_bytes'] ? self::FAILURE : self::SUCCESS;
    }
}
