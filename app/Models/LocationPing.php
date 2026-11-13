<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationPing extends Model
{
    protected $fillable = ['technician_id', 'lat', 'lng', 'accuracy_m', 'reason', 'recorded_at'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime', 'lat' => 'float', 'lng' => 'float'];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
