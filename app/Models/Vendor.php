<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A store parts are bought from. Saved once; receipts with any known spelling find it again. */
class Vendor extends Model
{
    protected $fillable = ['name', 'aliases', 'address'];

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PartPrice::class);
    }

    /** Find a saved store from whatever is typed or printed on the receipt ("THE HOME DEPOT #1802" → Home Depot), or save a new one. */
    public static function resolve(?string $name): ?self
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $key = self::key($name);
        foreach (static::all() as $v) {
            $keys = array_map([self::class, 'key'], array_merge([$v->name], $v->aliases ?? []));
            if (in_array($key, $keys, true) || collect($keys)->contains(fn ($k) => strlen($k) >= 4 && strlen($key) >= 4 && (str_starts_with($key, $k) || str_starts_with($k, $key)))) {
                if (! in_array($name, array_merge([$v->name], $v->aliases ?? []), true)) {
                    $v->update(['aliases' => array_values(array_unique(array_merge($v->aliases ?? [], [$name])))]);
                }

                return $v;
            }
        }

        return static::create(['name' => self::tidy($name)]);
    }

    /** "THE HOME DEPOT #1802" → "homedepot" */
    public static function key(string $name): string
    {
        $s = strtolower($name);
        $s = preg_replace('/#\s*\d+|store\s*\d+|\b(the|inc|llc|co|corp|company|stores?)\b/', ' ', $s);

        return preg_replace('/[^a-z0-9]/', '', $s);
    }

    private static function tidy(string $name): string
    {
        $clean = trim(preg_replace('/\s*#\s*\d+.*$/', '', $name));
        $clean = preg_replace('/^the\s+/i', '', $clean);

        return $clean === strtoupper($clean) ? ucwords(strtolower($clean)) : $clean;
    }
}
