<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Durable ownership for one thesis-spec convexity probe and its two broker orders.
 *
 * @property int $id
 * @property string $thesis_id
 * @property string $leg_id
 * @property string $account_number
 * @property string $status
 * @property array $spec
 * @property string $spec_hash
 * @property string|null $option_id
 * @property string|null $chain_id
 * @property string $underlying_symbol
 * @property string $option_type
 * @property Carbon|null $expiration_date
 * @property string|null $strike_price
 * @property string|null $entry_intent_key
 * @property string|null $entry_client_ref_id
 * @property string|null $entry_order_id
 * @property Carbon|null $entry_submitted_at
 * @property string|null $entry_limit_price
 * @property string $entry_filled_qty
 * @property string|null $entry_average_price
 * @property string|null $exit_intent_key
 * @property string|null $exit_client_ref_id
 * @property string|null $exit_order_id
 * @property int $exit_attempt
 * @property Carbon|null $exit_submitted_at
 * @property string $exit_filled_qty
 * @property string|null $exit_average_price
 * @property Carbon|null $opened_at
 * @property Carbon|null $exit_due_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $last_reconciled_at
 * @property string|null $block_reason
 * @property Carbon|null $thesis_expired_at
 * @property string|null $thesis_expiry_event_id
 * @property string|null $thesis_expiry_source
 * @property string|null $thesis_expiry_reason
 * @property string|null $exit_reason
 */
final class ConvexProbeRun extends Model
{
    public function stewardshipLabel(): string
    {
        if ($this->thesis_expired_at !== null) {
            return 'THESIS_EXPIRED';
        }

        return match ($this->status) {
            'staged' => 'STAGED',
            'closed' => 'CLOSED',
            'ambiguous' => 'ATTENTION_REQUIRED',
            default => 'THESIS_ACTIVE',
        };
    }

    protected $fillable = [
        'thesis_id', 'leg_id', 'account_number', 'status', 'spec', 'spec_hash',
        'option_id', 'chain_id', 'underlying_symbol', 'option_type', 'expiration_date', 'strike_price',
        'entry_intent_key', 'entry_client_ref_id', 'entry_order_id', 'entry_submitted_at', 'entry_limit_price',
        'entry_filled_qty', 'entry_average_price', 'exit_intent_key', 'exit_client_ref_id',
        'exit_order_id', 'exit_attempt', 'exit_submitted_at', 'exit_filled_qty', 'exit_average_price', 'opened_at', 'exit_due_at',
        'closed_at', 'last_reconciled_at', 'block_reason',
        'thesis_expired_at', 'thesis_expiry_event_id', 'thesis_expiry_source', 'thesis_expiry_reason', 'exit_reason',
    ];

    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'expiration_date' => 'date',
            'strike_price' => 'decimal:4',
            'entry_limit_price' => 'decimal:4',
            'entry_filled_qty' => 'decimal:4',
            'entry_average_price' => 'decimal:4',
            'exit_filled_qty' => 'decimal:4',
            'exit_average_price' => 'decimal:4',
            'opened_at' => 'datetime',
            'entry_submitted_at' => 'datetime',
            'exit_due_at' => 'datetime',
            'exit_attempt' => 'integer',
            'exit_submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'thesis_expired_at' => 'datetime',
        ];
    }
}
