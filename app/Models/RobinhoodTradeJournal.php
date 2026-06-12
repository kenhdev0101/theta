<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Durable Robinhood portfolio-manager journal.
 *
 * This is an audit/tracking model. Actual broker actions still happen through
 * Robinhood review/place/cancel tools with explicit operator confirmation.
 *
 * @property int $id
 * @property string|null $client_ref_id
 * @property string|null $broker_order_id
 * @property string $asset_class
 * @property string $symbol
 * @property string $status
 * @property string|null $side
 * @property string|null $position_effect
 * @property string|null $chain_id
 * @property string|null $option_id
 * @property string|null $dollar_amount
 * @property string|null $quantity
 * @property string|null $limit_price
 * @property array|null $broker_payload
 * @property array|null $notes
 */
class RobinhoodTradeJournal extends Model
{
    protected $table = 'robinhood_trade_journal';

    protected $fillable = [
        'account_number',
        'asset_class',
        'symbol',
        'underlying_symbol',
        'option_id',
        'chain_id',
        'option_type',
        'expiration_date',
        'strike_price',
        'status',
        'intent',
        'side',
        'position_effect',
        'order_type',
        'time_in_force',
        'market_hours',
        'client_ref_id',
        'broker_order_id',
        'quantity',
        'dollar_amount',
        'limit_price',
        'stop_price',
        'review_estimated_cost',
        'average_fill_price',
        'filled_quantity',
        'strategy_label',
        'structure_class',
        'relative_strength_state',
        'gap_classification',
        'trend_geometry_state',
        'liquidity_tier',
        'days_until_earnings',
        'days_since_earnings',
        'thesis',
        'invalidation',
        'scanner_payload',
        'option_math_payload',
        'review_payload',
        'broker_payload',
        'notes',
        'watchlisted_at',
        'reviewed_at',
        'submitted_at',
        'filled_at',
        'closed_at',
        'realized_pnl',
        'unrealized_pnl',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date' => 'date',
            'strike_price' => 'decimal:8',
            'quantity' => 'decimal:8',
            'dollar_amount' => 'decimal:2',
            'limit_price' => 'decimal:8',
            'stop_price' => 'decimal:8',
            'review_estimated_cost' => 'decimal:2',
            'average_fill_price' => 'decimal:8',
            'filled_quantity' => 'decimal:8',
            'days_until_earnings' => 'integer',
            'days_since_earnings' => 'integer',
            'scanner_payload' => 'array',
            'option_math_payload' => 'array',
            'review_payload' => 'array',
            'broker_payload' => 'array',
            'notes' => 'array',
            'watchlisted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'filled_at' => 'datetime',
            'closed_at' => 'datetime',
            'realized_pnl' => 'decimal:2',
            'unrealized_pnl' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RobinhoodTradeJournal $row): void {
            if (empty($row->client_ref_id)) {
                $row->client_ref_id = (string) Str::uuid();
            }
        });
    }

    /** @param Builder<RobinhoodTradeJournal> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['candidate', 'watchlisted', 'reviewed', 'submitted', 'open', 'partially_filled']);
    }

    /** @param Builder<RobinhoodTradeJournal> $query */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', ['closed', 'filled', 'cancelled', 'rejected', 'expired']);
    }

    /** @param Builder<RobinhoodTradeJournal> $query */
    public function scopeForSymbol(Builder $query, string $symbol): Builder
    {
        return $query->where('symbol', strtoupper($symbol));
    }

    public function isOption(): bool
    {
        return $this->asset_class === 'option';
    }

    public function isEquity(): bool
    {
        return $this->asset_class === 'equity';
    }
}
