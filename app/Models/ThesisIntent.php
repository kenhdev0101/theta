<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A standing operator thesis — the durable unit of the thesis-position lane
 * (docs/thesis-position-lane.md).
 *
 * This row precommits structure, sizing bounds, and kill triggers while the
 * operator is calm; the pre-open assembly derives the concrete contract
 * against live spot and the executor works the fill. The row never stores a
 * strike or an option id — those belong to the slate play (journal row) it
 * produces, linked back via robinhood_trade_journal.thesis_intent_id.
 *
 * @property int $id
 * @property string $underlying_symbol
 * @property string $direction
 * @property string $structure
 * @property int $dte_min
 * @property int $dte_max
 * @property string $max_debit
 * @property string $entry_limit_mode
 * @property string $entry_limit_offset_bps
 * @property string $thesis
 * @property string|null $catalyst
 * @property string|null $price_stop
 * @property Carbon|null $time_stop_date
 * @property string|null $profit_target_bps
 * @property string $status
 * @property string|null $close_reason
 * @property Carbon|null $activated_at
 * @property Carbon|null $parked_at
 * @property Carbon|null $closed_at
 * @property array|null $notes
 */
class ThesisIntent extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PARKED = 'parked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CLOSED = 'closed';

    public const DIRECTION_BULLISH = 'bullish';

    public const DIRECTION_BEARISH = 'bearish';

    public const STRUCTURE_LONG_CALL = 'single_leg_long_call';

    public const STRUCTURE_LONG_PUT = 'single_leg_long_put';

    /** Non-terminal statuses — the operator's standing book. */
    public const STANDING_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PARKED,
    ];

    protected $table = 'thesis_intents';

    protected $fillable = [
        'underlying_symbol',
        'direction',
        'structure',
        'dte_min',
        'dte_max',
        'max_debit',
        'entry_limit_mode',
        'entry_limit_offset_bps',
        'thesis',
        'catalyst',
        'price_stop',
        'time_stop_date',
        'profit_target_bps',
        'status',
        'close_reason',
        'activated_at',
        'parked_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'dte_min' => 'integer',
            'dte_max' => 'integer',
            'max_debit' => 'decimal:2',
            'entry_limit_offset_bps' => 'decimal:2',
            'price_stop' => 'decimal:4',
            'time_stop_date' => 'date',
            'profit_target_bps' => 'decimal:2',
            'activated_at' => 'datetime',
            'parked_at' => 'datetime',
            'closed_at' => 'datetime',
            'notes' => 'array',
        ];
    }

    /** The Level 2 long-only structure implied by a direction. */
    public static function structureForDirection(string $direction): string
    {
        return $direction === self::DIRECTION_BEARISH
            ? self::STRUCTURE_LONG_PUT
            : self::STRUCTURE_LONG_CALL;
    }

    /** @param Builder<ThesisIntent> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** @param Builder<ThesisIntent> $query */
    public function scopeStanding(Builder $query): Builder
    {
        return $query->whereIn('status', self::STANDING_STATUSES);
    }

    /**
     * Playbook-discipline activation gate: every machine-evaluated kill
     * trigger and the thesis itself must precommit before the row can arm.
     * Returns named blockers; empty array means activatable.
     *
     * @param  array{dte_floor?: int, dte_ceiling?: int}  $laneCfg
     * @return list<string>
     */
    public function activationBlockers(array $laneCfg = []): array
    {
        $blockers = [];

        if (trim($this->thesis) === '') {
            $blockers[] = 'thesis_text_missing';
        }

        if ($this->price_stop === null || (float) $this->price_stop <= 0.0) {
            $blockers[] = 'price_stop_missing';
        }

        if ($this->time_stop_date === null) {
            $blockers[] = 'time_stop_missing';
        } elseif ($this->time_stop_date->isPast() && ! $this->time_stop_date->isToday()) {
            $blockers[] = 'time_stop_in_past';
        }

        if ($this->profit_target_bps === null || (float) $this->profit_target_bps <= 0.0) {
            $blockers[] = 'profit_target_missing';
        }

        if ($this->dte_min < 1 || $this->dte_max < $this->dte_min) {
            $blockers[] = 'dte_band_invalid';
        }

        $floor = (int) ($laneCfg['dte_floor'] ?? 0);
        $ceiling = (int) ($laneCfg['dte_ceiling'] ?? 0);
        if ($floor > 0 && $this->dte_min < $floor) {
            $blockers[] = 'dte_below_lane_floor';
        }
        if ($ceiling > 0 && $this->dte_max > $ceiling) {
            $blockers[] = 'dte_above_lane_ceiling';
        }

        if ((float) $this->max_debit <= 0.0) {
            $blockers[] = 'max_debit_invalid';
        }

        if ($this->structure !== self::structureForDirection($this->direction)) {
            $blockers[] = 'structure_direction_mismatch';
        }

        return $blockers;
    }

    /** Active and inside its time stop — eligible for pre-open assembly. */
    public function isEnterable(?Carbon $today = null): bool
    {
        if ($this->status !== self::STATUS_ACTIVE || $this->time_stop_date === null) {
            return false;
        }

        return ! $this->time_stop_date->isBefore(($today ?? Carbon::today())->startOfDay());
    }
}
