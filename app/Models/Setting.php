<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Small admin-editable settings (billing options). */
class Setting extends Model
{
    public const DEFAULTS = [
        'default_hourly_rate' => '75',
        'bill_travel' => '0',
        'travel_rate_percent' => '100',
        'parts_markup_percent' => '0',
        'minimum_billable_minutes' => '15',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        static $cache = [];
        if (! array_key_exists($key, $cache) || app()->runningUnitTests()) {
            $cache[$key] = static::query()->whereKey($key)->value('value');
        }

        return $cache[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
