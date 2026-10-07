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

        // A broker response may list the close before its opening fill. Apply
        // opening truth first so a single pass can book the close's realized
        // P&L without waiting for a second reconciliation run.
        $ledger = $result['ledger'];
        usort($ledger, static function (array $a, array $b): int {
            $aClose = ($a['side'] ?? null) === 'sell' && ($a['position_effect'] ?? null) === 'close';
            $bClose = ($b['side'] ?? null) === 'sell' && ($b['position_effect'] ?? null) === 'close';

            return (int) $aClose <=> (int) $bClose;
        });
        foreach ($ledger as $row) {
            $this->applyBrokerTruthToJournal($row);

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

    private function applyBrokerTruthToJournal(array $ledgerRow): void
    {
        match ($ledgerRow['classification']) {
            BrokerTruthReconciler::LIVE_FILLED => $this->markJournalFilled($ledgerRow),
            BrokerTruthReconciler::LIVE_POSTED_NOT_FILLED => $this->markJournalStatus($ledgerRow, 'open'),
            BrokerTruthReconciler::LIVE_CANCELLED => $this->markJournalStatus($ledgerRow, 'cancelled', terminal: true),
            BrokerTruthReconciler::LIVE_REJECTED => $this->markJournalStatus($ledgerRow, 'rejected', terminal: true),
            default => null,
        };
    }

    private function findJournal(array $ledgerRow): ?RobinhoodTradeJournal
    {
        $query = RobinhoodTradeJournal::query()->where('asset_class', 'option');
        if (! empty($ledgerRow['broker_order_id'])) {
            $query->where('broker_order_id', (string) $ledgerRow['broker_order_id']);
        } elseif (! empty($ledgerRow['client_ref_id'])) {
            $query->where('client_ref_id', (string) $ledgerRow['client_ref_id']);
        } else {
            return null;
        }

        $journal = $query->first();
        if (! $journal instanceof RobinhoodTradeJournal) {
            return null;
        }

        return $journal;
    }

    private function markJournalFilled(array $ledgerRow): void
    {
        $journal = $this->findJournal($ledgerRow);
        if (! $journal instanceof RobinhoodTradeJournal) {
            return;
        }

        $journal->forceFill([
            'status' => 'filled',
            'filled_quantity' => (float) ($ledgerRow['filled_qty'] ?? 0.0),
            'average_fill_price' => (float) ($ledgerRow['fill_price'] ?? 0.0),
            'filled_at' => $journal->filled_at ?? now(),
            'closed_at' => $journal->position_effect === 'close' ? ($journal->closed_at ?? now()) : $journal->closed_at,
            'realized_pnl' => $journal->position_effect === 'close'
                ? $this->realizedPnlForClose($journal, $ledgerRow)
                : $journal->realized_pnl,
        ])->save();
    }

    private function realizedPnlForClose(RobinhoodTradeJournal $close, array $ledgerRow): ?float
    {
        if ($close->side !== 'sell' || $close->position_effect !== 'close' || empty($close->option_id)) {
            return null;
        }

        $exitQuantity = (float) ($ledgerRow['filled_qty'] ?? $close->filled_quantity ?? $close->quantity ?? 0.0);
        $exitPrice = (float) ($ledgerRow['fill_price'] ?? $close->average_fill_price ?? $close->limit_price ?? 0.0);
        if ($exitQuantity <= 0.0 || $exitPrice <= 0.0) {
            return null;
        }

        $entry = RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('option_id', (string) $close->option_id)
            ->where('side', 'buy')
            ->where('position_effect', 'open')
            ->where('status', 'filled')
            // Reconciliation time is not broker fill time. On a first pass
            // both rows may be stamped filled now, after the close journal
            // was created. Use durable order-intent sequence for pairing.
            ->where('created_at', '<=', $close->created_at)
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $entry instanceof RobinhoodTradeJournal) {
            return null;
        }

        $entryPrice = (float) ($entry->average_fill_price ?? $entry->limit_price ?? 0.0);
        if ($entryPrice <= 0.0) {
            return null;
        }

        $entryQuantity = (float) ($entry->filled_quantity ?? $entry->quantity ?? $exitQuantity);
        $quantity = min($exitQuantity, max(0.0, $entryQuantity));
        if ($quantity <= 0.0) {
            return null;
        }

        return round(($exitPrice - $entryPrice) * $quantity * 100.0, 2);
    }

    private function markJournalStatus(array $ledgerRow, string $status, bool $terminal = false): void
    {
        $journal = $this->findJournal($ledgerRow);
        if (! $journal instanceof RobinhoodTradeJournal) {
            return;
        }

        if ($journal->status === 'filled') {
            return;
        }

        $updates = ['status' => $status];
        if ($terminal) {
            $updates['closed_at'] = $journal->closed_at ?? now();
        }

        $journal->forceFill($updates)->save();
    }
}
