<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Reconciliation\BrokerTruthReconciler;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

class OptionReconcileCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:option-reconcile';

    protected $description = 'Reconcile option journal rows against broker truth (placed_agent=agentic) and classify every row';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);

        try {
            $brokerOrders = PortfolioReader::listFrom($client->getOptionOrders());
            $positions = PortfolioReader::listFrom($client->getOptionPositions(nonzero: true));
        } catch (\Throwable $e) {
            $this->error("option reconciliation mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        $journalRows = RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->latest('id')->limit(1000)->get()
            ->map(fn (RobinhoodTradeJournal $row) => $row->only([
                'client_ref_id', 'broker_order_id', 'status', 'symbol', 'underlying_symbol', 'option_id', 'side', 'position_effect',
            ]))
            ->all();

        $result = (new BrokerTruthReconciler)->reconcileOptions($brokerOrders, $journalRows);

        $this->line('open option positions (broker): '.count($positions));
        $this->table(
            ['classification', 'count'],
            collect($result['summary'])->map(fn ($n, $c) => [$c, (string) $n])->values()->all(),
        );

        foreach ($result['ledger'] as $row) {
            if ($row['classification'] === BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED
                || $row['classification'] === BrokerTruthReconciler::UNKNOWN_EXECUTION_STATE) {
                $this->warn(sprintf(
                    'TRACE PRODUCER: %s %s option_id=%s ref=%s broker_status=%s qty=%s px=%s',
                    $row['classification'],
                    $row['symbol'],
                    (string) ($row['option_id'] ?? '—'),
                    (string) ($row['client_ref_id'] ?? '—'),
                    (string) ($row['broker_status'] ?? '—'),
                    (string) $row['filled_qty'],
                    (string) $row['fill_price'],
                ));
            }
        }

        $recorder->record('options_reconciliation', [
            'event' => 'option_reconcile',
            'account' => $cfg['agentic_account'],
            'broker_rows' => count($brokerOrders),
            'journal_rows' => count($journalRows),
            'open_positions' => count($positions),
            'summary' => $result['summary'],
        ]);

        $this->info('option reconciliation complete: '.count($result['ledger']).' ledger rows');

        return self::SUCCESS;
    }
}
