<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'number', 'work_order_id', 'status', 'bill_to', 'labor_total', 'travel_total', 'parts_total', 'fees_total',
        'total', 'notes', 'created_by_user_id', 'sent_at', 'paid_at',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'labor_total' => 'decimal:2', 'travel_total' => 'decimal:2', 'parts_total' => 'decimal:2',
            'fees_total' => 'decimal:2', 'total' => 'decimal:2', 'sent_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
