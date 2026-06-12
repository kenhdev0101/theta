<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $intent_key
 * @property string $client_ref_id
 * @property string $symbol
 * @property string $side
 * @property string $status
 * @property array|null $order_payload
 * @property string|null $broker_order_id
 * @property Carbon|null $placed_at
 */
class HarnessOrderRef extends Model
{
    protected $table = 'harness_order_refs';

    protected $fillable = [
        'intent_key',
        'client_ref_id',
        'symbol',
        'side',
        'status',
        'order_payload',
        'broker_order_id',
        'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_payload' => 'array',
            'placed_at' => 'datetime',
        ];
    }
}
