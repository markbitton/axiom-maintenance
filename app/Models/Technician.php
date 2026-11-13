<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Technician extends Model
{
    protected $attributes = [
        'work_start' => '08:00', 'work_end' => '17:00', 'work_days' => '1,2,3,4,5', 'max_jobs_per_day' => 8,
        'on_call' => false, 'active' => true,
    ];

    protected $fillable = [
        'user_id', 'location_id', 'start_address', 'start_lat', 'start_lng', 'work_start', 'work_end',
        'work_days', 'max_jobs_per_day', 'hourly_rate', 'on_call', 'active',
    ];

    protected function casts(): array
    {
        return ['on_call' => 'boolean', 'active' => 'boolean', 'start_lat' => 'float', 'start_lng' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withPivot('level', 'hourly_rate');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function pings(): HasMany
    {
        return $this->hasMany(LocationPing::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function truck(): HasOne
    {
        return $this->hasOne(StockLocation::class)->where('type', 'truck');
    }

    public function skillLevel(string $slug): int
    {
        $skill = $this->skills->firstWhere('slug', $slug);

        return $skill ? (int) $skill->pivot->level : 0;
    }

    /** @return int[] ISO weekday numbers (1 = Monday). */
    public function workDays(): array
    {
        return array_map('intval', array_filter(explode(',', (string) $this->work_days)));
    }

    public function getNameAttribute(): string
    {
        return $this->user?->name ?? 'Tech #'.$this->id;
    }

    public function getFirstNameAttribute(): string
    {
        return explode(' ', $this->name)[0];
    }
}
