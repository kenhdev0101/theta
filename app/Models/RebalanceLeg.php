<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One basket name a rebalance pass touched (thesis §11.5, line item).
 *
 * Order/fill truth is BY REFERENCE: client_ref_id points at the
 * RobinhoodTradeJournal row that owns filled_qty / fill_price / filled_at /
 * status. This leg carries basket context only — no duplicated fill columns.
 *
 * @property int $id
 * @property int $rebalance_event_id
 * @property string $ticker
 * @property string $logical_order_id
 * @property float $weight_target
 * @property float $notional_target
 * @property float $position_before_shares
 * @property float $position_before_mv
 * @property float $drift_usd
 * @property float $planned_buy_usd
 * @property string $action
 * @property string $reason
 * @property bool|null $fractionable
 * @property string|null $client_ref_id
 * @property string|null $broker_order_id
 * @property array|null $plan_notes
 */
class RebalanceLeg extends Model
{
    public const ACTION_BUY = 'buy';

    public const ACTION_SELL = 'sell';

    public const ACTION_HOLD = 'hold';

    public const ACTION_DROP = 'drop';

    public const REASON_DEPOSIT_FILL = 'deposit_fill';

    public const REASON_DRIFT_TRIM = 'drift_trim';

    public const REASON_NEW_ADMIT = 'new_admit';

    public const REASON_DROPPED_UNTRADABLE = 'dropped_untradable';

    public const REASON_AT_TARGET = 'at_target';

    protected $table = 'rebalance_legs';

    protected $fillable = [
        'rebalance_event_id',
        'ticker',
        'logical_order_id',
        'weight_target',
        'notional_target',
        'position_before_shares',
        'position_before_mv',
        'drift_usd',
        'planned_buy_usd',
        'action',
        'reason',
        'fractionable',
        'client_ref_id',
        'broker_order_id',
        'plan_notes',
    ];

    protected function casts(): array
    {
        return [
            'weight_target' => 'decimal:10',
            'notional_target' => 'decimal:2',
            'position_before_shares' => 'decimal:6',
            'position_before_mv' => 'decimal:2',
            'drift_usd' => 'decimal:2',
            'planned_buy_usd' => 'decimal:2',
            'fractionable' => 'boolean',
            'plan_notes' => 'array',
        ];
    }

    /** @return BelongsTo<RebalanceEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(RebalanceEvent::class, 'rebalance_event_id');
    }
}
