<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $fillable = ['location_id', 'rm_property_id', 'name', 'address', 'city', 'state', 'zip', 'lat', 'lng', 'managed_by_axiom', 'bill_to'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'managed_by_axiom' => 'boolean'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function fullAddress(): string
    {
        return trim(implode(', ', array_filter([$this->address, $this->city, trim($this->state.' '.$this->zip)])));
    }
}
