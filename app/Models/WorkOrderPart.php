<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPart extends Model
{
    protected $fillable = [
        'work_order_id', 'part_id', 'part_name', 'quantity', 'suggested', 'suggestion_confidence', 'used', 'stock_location_id', 'invoice_id',
    ];

    protected function casts(): array
    {
        return ['suggested' => 'boolean', 'used' => 'boolean', 'suggestion_confidence' => 'float'];
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
