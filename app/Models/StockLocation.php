<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLocation extends Model
{
    protected $fillable = ['location_id', 'type', 'technician_id', 'name'];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
