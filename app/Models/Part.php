<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    protected $fillable = ['sku', 'vendor_sku', 'name', 'trade', 'keywords', 'unit_cost'];

    protected function casts(): array
    {
        return ['keywords' => 'array', 'unit_cost' => 'float'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PartPrice::class);
    }
}
