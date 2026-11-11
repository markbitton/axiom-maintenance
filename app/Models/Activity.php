<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activity_log';

    protected $fillable = ['work_order_id', 'user_id', 'event', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
