<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stretch on a clock. shift = employee clock; travel, work and break sit inside a shift. */
class TimeEntry extends Model
{
    public const SHIFT = 'shift';

    public const TRAVEL = 'travel';

    public const WORK = 'work';

    public const BREAK = 'break';

    protected $fillable = [
        'technician_id', 'work_order_id', 'type', 'started_at', 'ended_at', 'start_lat', 'start_lng',
        'end_lat', 'end_lng', 'invoice_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('ended_at');
    }

    /** Whole minutes, counting an open entry up to now. */
    public function minutes(?CarbonImmutable $now = null): int
    {
        return (int) round($this->started_at->diffInSeconds($this->ended_at ?? $now ?? CarbonImmutable::now()) / 60);
    }
}
