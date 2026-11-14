<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptLine extends Model
{
    protected $fillable = ['receipt_id', 'description', 'sku', 'quantity', 'unit_price', 'amount', 'part_id', 'work_order_id', 'work_order_part_id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'float', 'amount' => 'float'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
