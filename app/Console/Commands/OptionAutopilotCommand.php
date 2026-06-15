<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Options\OptionBrokerContext;
use App\Robinhood\Options\OptionContractResolver;
use App\Robinhood\Options\OptionExecutor;
use App\Robinhood\Options\OptionOrderIntent;
use App\Robinhood\Options\OptionRailPipeline;
use App\Robinhood\Rails\SessionClock;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

/**
 * Scheduled option autopilot. It consumes complete option intents from the
 * journal and sends them through the exact same resolver, rails, review, and
 * placement spine as manual live placement.
 */
class OptionAutopilotCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:option-autopilot
        {--limit=4 : Maximum journal intents to evaluate this run}
        {--status=reviewed : Comma-separated source statuses eligible for autonomous placement}';

    protected $description = 'Autonomously place complete journaled option intents when rails allow';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);

        if (($cfg['mode'] ?? 'approval') !== 'auto') {
            $this->warn('autopilot disarmed: HARNESS_MODE is not auto');

            return self::SUCCESS;
        }

        if (! (bool) ($cfg['options']['enabled'] ?? false)) {
            $this->warn('autopilot disarmed: options disabled');

            return self::SUCCESS;
        }

        if ((bool) ($cfg['kill_switch'] ?? true)) {
            $this->warn('autopilot disarmed: kill switch engaged');

            return self::SUCCESS;
        }

        if (! (new SessionClock)->isRegularHoursOpen()) {
            $this->info('autopilot idle: regular options session closed');

            return self::SUCCESS;
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $this->error('blocker: oauth token absent');

            return self::FAILURE;
        }

        $eligibleStatuses = $this->eligibleStatuses();
        $this->recoverStaleClaims();
        $rows = $this->eligibleRows($cfg, $eligibleStatuses);
        if ($rows->isEmpty()) {
            $this->info('no eligible option intents');

            return self::SUCCESS;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $resolver = new OptionContractResolver($client, $cfg);
        $executor = new OptionExecutor($client, new OptionRailPipeline, new OrderRefStore, $recorder, $cfg);
        $baseContext = null;

        $placed = 0;
        $blocked = 0;

        foreach ($rows as $row) {
            if (! $this->claimRow($row, $eligibleStatuses)) {
                $this->line("journal {$row->id}: skipped (already claimed)");

                continue;
            }

            $intent = $this->intentFromJournal($row, optionId: null, chainId: null, underlyingType: null);
            if (! $intent instanceof OptionOrderIntent) {
                $blocked++;
                $this->markAutopilotBlock($row, 'journal_intent_incomplete');
                $this->warn("journal {$row->id}: incomplete option intent");

                continue;
            }

            try {
                $resolution = $resolver->resolveExact(
                    $intent->underlyingSymbol,
                    $intent->optionType,
                    $intent->expirationDate,
                    $intent->strikePrice,
                );
            } catch (\Throwable $e) {
                $blocked++;
                $this->markAutopilotBlock($row, 'resolver_mechanical_blocker: '.$e->getMessage());
                $this->error("journal {$row->id}: resolver mechanical blocker: {$e->getMessage()}");

                continue;
            }

            if (($resolution['contract']['option_id'] ?? '') === '') {
                $blocked++;
                $this->markAutopilotBlock($row, 'contract_unresolved: '.implode('; ', (array) $resolution['blockers']));
                $this->warn("journal {$row->id}: contract unresolved");

                continue;
            }

            $contract = $resolution['contract'];
            $intent = $this->intentFromJournal(
                $row,
                optionId: (string) $contract['option_id'],
                chainId: $contract['chain_id'] ?? null,
                underlyingType: $contract['underlying_type'] ?? 'equity',
            );
            $intent = $this->repriceToFreshQuote($intent, $resolution['quote']);
            if ($intent === null) {
                $blocked++;
                $this->markAutopilotBlock($row, 'fresh_quote_unmarketable_or_incomplete');
                $this->warn("journal {$row->id}: fresh quote unmarketable or incomplete");

                continue;
            }
            if ((float) $row->limit_price !== (float) $intent->limitPrice) {
                $notes = (array) ($row->notes ?? []);
                $notes['autopilot_reprice'] = [
                    'from' => (float) $row->limit_price,
                    'to' => $intent->limitPrice,
                    'repriced_at' => now()->toISOString(),
                ];
                $row->forceFill([
                    'limit_price' => $intent->limitPrice,
                    'dollar_amount' => $intent->estimatedDebit(),
                    'notes' => $notes,
                ])->save();
                $row->refresh();
            }

            try {
                $baseContext ??= OptionBrokerContext::assemble($client, $cfg);
                $context = array_merge($baseContext, [
                    'contract' => $contract,
                    'quote' => $resolution['quote'],
                    'journal_id' => $row->id,
                ]);
            } catch (\Throwable $e) {
                $blocked++;
                $this->markAutopilotBlock($row, 'broker_truth_mechanical_blocker: '.$e->getMessage());
                $this->error("journal {$row->id}: broker-truth blocker: {$e->getMessage()}");

                continue;
            }

            try {
                $result = $executor->execute($intent, $context);
            } catch (\Throwable $e) {
                $blocked++;
                if ($row->refresh()->status === 'autopilot_claimed') {
                    $this->markAutopilotBlock($row, 'placement_mechanical_failure: '.$e->getMessage());
                }
                $this->error("journal {$row->id}: placement mechanical failure: {$e->getMessage()}");

                continue;
            }

            if ($result['classification'] === 'LIVE_POSTED_NOT_FILLED') {
                $placed++;
                $baseContext = $this->applySubmittedIntentToContext($baseContext ?? [], $intent, $result);
            } else {
                $blocked++;
                if ($row->refresh()->status === 'autopilot_claimed') {
                    $this->markAutopilotBlock($row, 'rail_block: '.$result['verdict']->verdict());
                }
            }

            $this->line("journal {$row->id}: {$result['classification']} ({$result['verdict']->verdict()})");
        }

        $this->info("autopilot complete: placed={$placed} blocked={$blocked}");

        return $blocked > 0 && $placed === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function applySubmittedIntentToContext(array $context, OptionOrderIntent $intent, array $result): array
    {
        $optionId = (string) ($intent->optionId ?? '');
        $debit = $intent->estimatedDebit() ?? 0.0;
        $symbol = strtoupper($intent->underlyingSymbol);

        $context['options_spent_today_debit'] = (float) ($context['options_spent_today_debit'] ?? 0.0) + $debit;
        if (array_key_exists('settled_cash', $context) && $context['settled_cash'] !== null) {
            $context['settled_cash'] = round(max(0.0, (float) $context['settled_cash'] - $debit), 2);
        }
        if (array_key_exists('buying_power', $context) && $context['buying_power'] !== null) {
            $context['buying_power'] = round(max(0.0, (float) $context['buying_power'] - $debit), 2);
        }

        $underlyingDebits = (array) ($context['underlying_open_debit'] ?? []);
        $underlyingDebits[$symbol] = (float) ($underlyingDebits[$symbol] ?? 0.0) + $debit;
        $context['underlying_open_debit'] = $underlyingDebits;
        $context['options_open_debit'] = (float) ($context['options_open_debit'] ?? 0.0) + $debit;

        $context['open_option_orders'] = array_merge((array) ($context['open_option_orders'] ?? []), [[
            'id' => $result['ref']->broker_order_id ?? null,
            'state' => 'queued',
            'legs' => [['option_id' => $optionId]],
        ]]);

        if ($intent->isOpening()) {
            $context['open_option_positions'] = array_merge((array) ($context['open_option_positions'] ?? []), [[
                'option_id' => $optionId,
                'quantity' => (string) $intent->quantity,
            ]]);
        }

        return $context;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, RobinhoodTradeJournal> */
    private function eligibleRows(array $cfg, array $statuses)
    {
        $limit = max(1, min((int) $this->option('limit'), (int) ($cfg['options']['max_open_positions'] ?? 4)));

        return RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->whereIn('status', $statuses)
            ->where(function ($query) use ($cfg): void {
                $query->whereNull('account_number')
                    ->orWhere('account_number', (string) ($cfg['agentic_account'] ?? ''));
            })
            ->whereNotNull('expiration_date')
            ->whereNotNull('strike_price')
            ->whereNotNull('option_type')
            ->whereNotNull('side')
            ->whereNotNull('quantity')
            ->whereNotNull('limit_price')
            ->oldest()
            ->limit($limit)
            ->get();
    }

    private function eligibleStatuses(): array
    {
        $statuses = array_values(array_filter(array_map(
            fn (string $status): string => trim($status),
            explode(',', (string) $this->option('status')),
        )));

        return $statuses === [] ? ['reviewed'] : $statuses;
    }

    private function repriceToFreshQuote(?OptionOrderIntent $intent, ?array $quote): ?OptionOrderIntent
    {
        if (! $intent instanceof OptionOrderIntent || ! is_array($quote)) {
            return null;
        }

        $price = $intent->side === 'sell' ? ($quote['bid'] ?? null) : ($quote['ask'] ?? null);
        if (! is_numeric($price) || (float) $price <= 0.0) {
            return null;
        }

        return new OptionOrderIntent(
            underlyingSymbol: $intent->underlyingSymbol,
            optionType: $intent->optionType,
            expirationDate: $intent->expirationDate,
            strikePrice: $intent->strikePrice,
            side: $intent->side,
            positionEffect: $intent->positionEffect,
            quantity: $intent->quantity,
            limitPrice: round((float) $price, 2),
            optionId: $intent->optionId,
            chainId: $intent->chainId,
            underlyingType: $intent->underlyingType,
            orderType: $intent->orderType,
            timeInForce: $intent->timeInForce,
            marketHours: $intent->marketHours,
            contractMultiplier: $intent->contractMultiplier,
            source: $intent->source,
        );
    }

    private function recoverStaleClaims(): void
    {
        RobinhoodTradeJournal::query()
            ->where('asset_class', 'option')
            ->where('status', 'autopilot_claimed')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update([
                'status' => 'reviewed',
                'updated_at' => now(),
            ]);
    }

    private function claimRow(RobinhoodTradeJournal $row, array $eligibleStatuses): bool
    {
        $notes = (array) ($row->notes ?? []);
        $notes['autopilot_claim'] = [
            'claimed_at' => now()->toISOString(),
        ];

        $claimed = RobinhoodTradeJournal::query()
            ->whereKey($row->id)
            ->whereIn('status', $eligibleStatuses)
            ->update([
                'status' => 'autopilot_claimed',
                'notes' => $notes,
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $row->refresh();
        }

        return $claimed === 1;
    }

    private function intentFromJournal(RobinhoodTradeJournal $row, ?string $optionId, ?string $chainId, ?string $underlyingType): ?OptionOrderIntent
    {
        $symbol = strtoupper((string) ($row->underlying_symbol ?: $row->symbol));
        $optionType = strtolower((string) $row->option_type);
        $side = strtolower((string) $row->side);
        $positionEffect = (string) ($row->position_effect ?: ($side === 'sell' ? 'close' : 'open'));
        $expiration = $row->expiration_date?->toDateString();

        if (
            $symbol === ''
            || ! in_array($optionType, ['call', 'put'], true)
            || ! in_array($side, ['buy', 'sell'], true)
            || $expiration === null
            || $row->strike_price === null
            || $row->quantity === null
            || $row->limit_price === null
        ) {
            return null;
        }

        return new OptionOrderIntent(
            underlyingSymbol: $symbol,
            optionType: $optionType,
            expirationDate: $expiration,
            strikePrice: (float) $row->strike_price,
            side: $side,
            positionEffect: $positionEffect,
            quantity: max(1, (int) $row->quantity),
            limitPrice: (float) $row->limit_price,
            optionId: $optionId,
            chainId: $chainId,
            underlyingType: $underlyingType ?? 'equity',
            orderType: (string) ($row->order_type ?: 'limit'),
            timeInForce: (string) ($row->time_in_force ?: 'gfd'),
            marketHours: (string) ($row->market_hours ?: 'regular_hours'),
            source: "theta:option-autopilot:journal:{$row->id}",
        );
    }

    private function markAutopilotBlock(RobinhoodTradeJournal $row, string $reason): void
    {
        $notes = (array) ($row->notes ?? []);
        $notes['autopilot_block'] = [
            'reason' => $reason,
            'blocked_at' => now()->toISOString(),
        ];

        $row->forceFill([
            'status' => 'risk_aborted',
            'notes' => $notes,
        ])->save();
    }
}
