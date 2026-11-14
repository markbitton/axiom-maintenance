<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receipt extends Model
{
    protected $fillable = ['vendor_id', 'technician_id', 'uploaded_by_user_id', 'purchased_on', 'subtotal', 'tax', 'total',
        'photo_path', 'photo_type', 'read_by', 'raw'];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'raw' => 'array', 'subtotal' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class);
    }
}
