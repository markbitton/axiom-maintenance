<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Part extends Model
{
    protected $fillable = ['sku', 'name', 'trade', 'keywords', 'unit_cost'];

    protected function casts(): array
    {
        return ['keywords' => 'array', 'unit_cost' => 'float'];
    }
}
