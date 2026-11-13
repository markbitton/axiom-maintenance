<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    public const SLUGS = ['plumbing', 'electrical', 'hvac', 'appliance', 'locks', 'pest', 'general'];

    protected $fillable = ['slug', 'name', 'hourly_rate', 'service_call_fee', 'licensed'];

    protected function casts(): array
    {
        return ['hourly_rate' => 'float', 'service_call_fee' => 'float', 'licensed' => 'boolean'];
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class)->withPivot('level');
    }
}
