<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = ['rm_location_id', 'name', 'timezone', 'enabled', 'last_issue_sync_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'last_issue_sync_at' => 'datetime'];
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
