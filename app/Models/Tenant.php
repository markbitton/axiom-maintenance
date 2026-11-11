<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'location_id', 'rm_tenant_id', 'unit_id', 'name', 'phone', 'email', 'sms_consent', 'sms_opted_out_at', 'language',
    ];

    protected function casts(): array
    {
        return ['sms_consent' => 'boolean', 'sms_opted_out_at' => 'datetime'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function canText(): bool
    {
        return $this->phone && $this->sms_consent && ! $this->sms_opted_out_at;
    }
}
