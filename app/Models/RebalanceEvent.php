<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One rebalance pass of the harness-as-ETF-wrapper (thesis §11.5, parent).
 *
 * Structured source of truth for the rebalance ledger. Any Markdown/CSV
 * readback is rendered from these rows — never hand-maintained (ledger law).
 *
 * @property int $id
 * @property string $owner
 * @property string $agent_id
 * @property string $trigger
 * @property Carbon $as_of
 * @property float|null $settled_cash_before
 * @property float|null $basket_mv_before
 * @property float|null $book_before
 * @property bool $deposit_detected
 * @property float|null $deposit_amount
 * @property string|null $thesis_spec_id
 * @property string|null $spec_version
 * @property string $weights_source
 * @property string $weights_payload_hash
 * @property float|null $total_equity
 * @property int $n_legs
 * @property float $gross_buys_usd
 * @property float $gross_sells_usd
 * @property float $cash_deployed
 * @property float|null $settled_cash_after
 * @property string $status
 * @property bool $dry_run
 * @property bool $kill_switch
 * @property bool $armed
 * @property array|null $notes
 */
class RebalanceEvent extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_PLACING = 'placing';

    public const STATUS_PLACED = 'placed';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_PARTIALLY_FILLED = 'partially_filled';

    public const STATUS_ABORTED = 'aborted';

    public const TRIGGERS = ['deposit', 'drift', 'score_change', 'manual', 'scheduled'];

    protected $table = 'rebalance_events';

    protected $fillable = [
        'owner',
        'agent_id',
        'trigger',
        'as_of',
        'settled_cash_before',
        'basket_mv_before',
        'book_before',
        'deposit_detected',
        'deposit_amount',
        'thesis_spec_id',
        'spec_version',
        'weights_source',
        'weights_payload_hash',
        'total_equity',
        'n_legs',
        'gross_buys_usd',
        'gross_sells_usd',
        'cash_deployed',
        'settled_cash_after',
        'status',
        'dry_run',
        'kill_switch',
        'armed',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'settled_cash_before' => 'decimal:2',
            'basket_mv_before' => 'decimal:2',
            'book_before' => 'decimal:2',
            'deposit_detected' => 'boolean',
            'deposit_amount' => 'decimal:2',
            'total_equity' => 'decimal:2',
            'n_legs' => 'integer',
            'gross_buys_usd' => 'decimal:2',
            'gross_sells_usd' => 'decimal:2',
            'cash_deployed' => 'decimal:2',
            'settled_cash_after' => 'decimal:2',
            'dry_run' => 'boolean',
            'kill_switch' => 'boolean',
            'armed' => 'boolean',
            'notes' => 'array',
        ];
    }

    /** @return HasMany<RebalanceLeg, $this> */
    public function legs(): HasMany
    {
        return $this->hasMany(RebalanceLeg::class);
    }
}
