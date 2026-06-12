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

class ReconcileCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:reconcile';

    protected $description = 'Reconcile local journal rows against broker truth (placed_agent=agentic) and classify every row';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent — complete the one-time desktop grant and store it via OAuthTokenStore (arch §13 #1)');

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);

        try {
            $brokerOrders = PortfolioReader::listFrom($client->getEquityOrders());
        } catch (\Throwable $e) {
            $this->error("reconciliation mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        $journalRows = RobinhoodTradeJournal::query()
            ->where('asset_class', 'equity')
            ->latest('id')->limit(1000)->get()
            ->map(fn (RobinhoodTradeJournal $row) => $row->only(['client_ref_id', 'broker_order_id', 'status', 'symbol', 'side']))
            ->all();

        $result = (new BrokerTruthReconciler)->reconcile($brokerOrders, $journalRows);

        $this->table(
            ['classification', 'count'],
            collect($result['summary'])->map(fn ($n, $c) => [$c, (string) $n])->values()->all(),
        );

        $conflicts = array_filter(
            $result['ledger'],
            fn (array $row) => $row['classification'] === BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED
                || $row['classification'] === BrokerTruthReconciler::UNKNOWN_EXECUTION_STATE,
        );

        foreach ($conflicts as $row) {
            $this->warn(sprintf(
                'TRACE PRODUCER: %s %s ref=%s broker_id=%s broker_status=%s qty=%s px=%s',
                $row['classification'],
                $row['symbol'],
                (string) $row['client_ref_id'],
                (string) $row['broker_order_id'],
                (string) $row['broker_status'],
                (string) $row['filled_qty'],
                (string) $row['fill_price'],
            ));
        }

        $recorder->record('reconciliation', [
            'event' => 'reconcile',
            'account' => $cfg['agentic_account'],
            'broker_rows' => count($brokerOrders),
            'journal_rows' => count($journalRows),
            'summary' => $result['summary'],
            'conflicts' => count($conflicts),
        ]);

        $this->info('reconciliation complete: '.count($result['ledger']).' ledger rows');

        return self::SUCCESS;
    }
}
