<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    public const SLUGS = ['plumbing', 'electrical', 'hvac', 'appliance', 'locks', 'pest', 'general'];

    protected $fillable = ['slug', 'name'];

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class)->withPivot('level');
    }
}
