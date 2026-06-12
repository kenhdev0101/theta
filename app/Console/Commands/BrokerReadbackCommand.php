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

class BrokerReadbackCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:broker-readback';

    protected $description = 'Broker/account truth readback: portfolio, positions, agentic orders, classified';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent — complete the one-time desktop grant and store it via OAuthTokenStore (arch §13 #1); set HARNESS_OAUTH_TOKEN or storage/harness/oauth_token.enc');

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);

        try {
            $portfolio = $client->getPortfolio();
            $positions = $client->getEquityPositions();
            $orders = $client->getEquityOrders();
        } catch (\Throwable $e) {
            $this->error("broker readback mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        $settledCash = PortfolioReader::settledCash($portfolio);
        $buyingPower = PortfolioReader::buyingPower($portfolio);

        $this->info("account (pinned): {$cfg['agentic_account']}");
        $this->table(['field', 'value'], [
            ['settled_cash', $settledCash === null ? 'UNVERIFIED — patch/read the account source' : (string) $settledCash],
            ['buying_power', $buyingPower === null ? 'unverified' : (string) $buyingPower],
            ['open_positions', (string) count(PortfolioReader::listFrom($positions))],
        ]);

        $reconciler = new BrokerTruthReconciler;
        $journalRows = RobinhoodTradeJournal::query()
            ->where('asset_class', 'equity')
            ->latest('id')->limit(500)->get()
            ->map(fn (RobinhoodTradeJournal $row) => $row->only(['client_ref_id', 'broker_order_id', 'status', 'symbol', 'side']))
            ->all();

        $result = $reconciler->reconcile(PortfolioReader::listFrom($orders), $journalRows);

        $this->table(
            ['classification', 'count'],
            collect($result['summary'])->map(fn ($n, $c) => [$c, (string) $n])->values()->all(),
        );

        foreach ($result['ledger'] as $row) {
            $this->line(sprintf(
                '%-36s %-8s %-6s qty=%s px=%s journal=%s',
                $row['classification'],
                $row['symbol'],
                (string) $row['side'],
                (string) $row['filled_qty'],
                (string) $row['fill_price'],
                $row['journal_matched'] ? ($row['journal_status'] ?? 'matched') : 'UNMATCHED',
            ));
        }

        $recorder->record('broker_readback', [
            'event' => 'readback',
            'account' => $cfg['agentic_account'],
            'settled_cash' => $settledCash,
            'buying_power' => $buyingPower,
            'summary' => $result['summary'],
            'ledger_rows' => count($result['ledger']),
        ]);

        return self::SUCCESS;
    }
}
