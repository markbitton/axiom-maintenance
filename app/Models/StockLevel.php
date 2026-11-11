<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    protected $fillable = ['stock_location_id', 'part_id', 'quantity', 'reorder_point', 'par_level'];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
