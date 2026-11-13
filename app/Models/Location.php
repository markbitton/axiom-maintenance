<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = ['rm_location_id', 'name', 'timezone', 'office_address', 'office_lat', 'office_lng', 'enabled', 'last_issue_sync_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'last_issue_sync_at' => 'datetime', 'office_lat' => 'float', 'office_lng' => 'float'];
    }

    /** Home office coordinates, where techs start and end the day. */
    public function office(): ?array
    {
        return $this->office_lat !== null && $this->office_lng !== null ? [(float) $this->office_lat, (float) $this->office_lng] : null;
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
