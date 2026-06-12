<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Execution\EquityExecutor;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Rails\OrderIntent;
use App\Robinhood\Rails\RailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

/**
 * Bounded live equity probe through the full spine: broker-truth context ->
 * review -> rails -> persisted ref -> place -> journal. The sandboxed
 * agentic cash account is the only reachable account; the caps in
 * config/harness.php bound the debit.
 */
class LiveEquityProbeCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:live-equity-probe
        {--symbol= : Equity symbol to probe}
        {--dollars= : Notional dollar amount (fractional/dollar order)}
        {--quantity= : Share quantity (requires --limit-price)}
        {--limit-price= : Limit price for share orders}
        {--side=buy : buy|sell}';

    protected $description = 'Run one bounded live equity order through review -> rails -> place -> journal';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        $symbol = strtoupper((string) $this->option('symbol'));
        if ($symbol === '') {
            $this->error('--symbol is required');

            return self::INVALID;
        }

        $side = (string) $this->option('side');
        if (! in_array($side, ['buy', 'sell'], true)) {
            $this->error('--side must be buy or sell');

            return self::INVALID;
        }

        $dollars = $this->option('dollars') !== null ? (float) $this->option('dollars') : null;
        $quantity = $this->option('quantity') !== null ? (float) $this->option('quantity') : null;
        $limitPrice = $this->option('limit-price') !== null ? (float) $this->option('limit-price') : null;

        if ($dollars === null && ($quantity === null || $limitPrice === null)) {
            $this->error('provide --dollars, or --quantity with --limit-price (deterministic debit bound required)');

            return self::INVALID;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent — complete the one-time desktop grant and store it via OAuthTokenStore (arch §13 #1)');

            return self::FAILURE;
        }

        $intent = new OrderIntent(
            symbol: $symbol,
            side: $side,
            orderType: $limitPrice !== null ? 'limit' : ($cfg['equity']['default_order_type'] ?? 'limit'),
            quantity: $quantity,
            dollarAmount: $dollars,
            limitPrice: $limitPrice,
            timeInForce: $cfg['equity']['default_time_in_force'] ?? 'gfd',
            marketHours: $cfg['equity']['default_market_hours'] ?? 'regular_hours',
            source: 'theta:live-equity-probe',
        );

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);

        try {
            $context = $this->brokerTruthContext($client, $symbol);
        } catch (\Throwable $e) {
            $this->error("broker-truth context mechanical blocker: {$e->getMessage()}");

            return self::FAILURE;
        }

        $executor = new EquityExecutor(
            client: $client,
            rails: new RailPipeline,
            refs: new OrderRefStore,
            telemetry: $recorder,
            cfg: $cfg,
        );

        try {
            $result = $executor->execute($intent, $context);
        } catch (\Throwable $e) {
            $this->error("placement mechanical failure (ref persisted; run theta:reconcile before retry): {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("classification: {$result['classification']}");
        $this->table(
            ['gate', 'ok', 'reason'],
            array_map(
                fn (array $r) => [$r['gate'], $r['ok'] ? 'ok' : 'BLOCK', (string) ($r['reason'] ?? '')],
                $result['verdict']->toArray()['ledger'],
            ),
        );
        $this->line("client_ref_id: {$result['ref']->client_ref_id}");
        $this->line('broker_order_id: '.($result['ref']->broker_order_id ?? '—'));
        $this->line("journal_id: {$result['journal_id']}");

        return self::SUCCESS;
    }

    /**
     * Assemble the broker/account truth snapshot the rails consume. Fields
     * that cannot be verified stay null/absent so the corresponding gate
     * blocks with a named reason instead of assuming safety.
     */
    private function brokerTruthContext(RobinhoodMcpClient $client, string $symbol): array
    {
        $portfolio = $client->getPortfolio();
        $openOrders = PortfolioReader::listFrom($client->getEquityOrders());
        $positions = PortfolioReader::listFrom($client->getEquityPositions());
        $tradability = $client->getEquityTradability($symbol);

        $today = now()->toDateString();
        $journalToday = RobinhoodTradeJournal::query()
            ->where('asset_class', 'equity')
            ->where('side', 'buy')
            ->whereIn('status', ['submitted', 'open', 'partially_filled', 'filled'])
            ->whereDate('created_at', $today)
            ->get(['symbol', 'dollar_amount', 'quantity', 'limit_price']);

        $spentToday = 0.0;
        $symbolOpenDebit = [];
        foreach ($journalToday as $row) {
            $debit = $row->dollar_amount !== null
                ? (float) $row->dollar_amount
                : (float) ($row->quantity ?? 0) * (float) ($row->limit_price ?? 0);
            $spentToday += $debit;
            $rowSymbol = strtoupper((string) $row->symbol);
            $symbolOpenDebit[$rowSymbol] = ($symbolOpenDebit[$rowSymbol] ?? 0.0) + $debit;
        }

        return [
            'settled_cash' => PortfolioReader::settledCash($portfolio),
            'buying_power' => PortfolioReader::buyingPower($portfolio),
            'spent_today_debit' => $spentToday,
            'symbol_open_debit' => $symbolOpenDebit,
            'open_orders' => $openOrders,
            'open_positions' => $positions,
            'session_open' => $tradability['session_open'] ?? $tradability['market_open'] ?? null,
            'tradability' => $tradability['tradability'] ?? (($tradability['tradable'] ?? null) === true ? 'tradable' : null),
            'portfolio' => $portfolio,
        ];
    }
}
