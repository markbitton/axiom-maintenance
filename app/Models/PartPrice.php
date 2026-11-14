<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One purchase of a part: what it cost and where. */
class PartPrice extends Model
{
    protected $fillable = ['part_id', 'vendor_id', 'unit_cost', 'purchased_on', 'receipt_line_id'];

    protected function casts(): array
    {
        return ['unit_cost' => 'float', 'purchased_on' => 'date'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
