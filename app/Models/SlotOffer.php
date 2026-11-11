<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotOffer extends Model
{
    protected $fillable = [
        'work_order_id', 'technician_id', 'option_number', 'starts_at', 'ends_at', 'score', 'sent_at',
        'reminded_at', 'expires_at', 'accepted_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sent_at' => 'datetime', 'reminded_at' => 'datetime',
            'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'withdrawn_at' => 'datetime', 'score' => 'float',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('accepted_at')->whereNull('withdrawn_at');
    }
}
