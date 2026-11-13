<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\Technician;
use App\Services\Geo\Geocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Billable rates: per kind of work, per tech, and per tech for each kind of work. Plus billing options and home offices. */
class RatesController extends Controller
{
    public function index()
    {
        return view('admin.rates', [
            'skills' => Skill::orderBy('name')->get(),
            'techs' => Technician::with('user', 'skills', 'location')->where('active', true)->orderBy('location_id')->orderBy('id')->get(),
            'settings' => collect(Setting::DEFAULTS)->mapWithKeys(fn ($v, $k) => [$k => Setting::get($k)]),
            'locations' => Location::orderBy('rm_location_id')->get(),
        ]);
    }

    public function update(Request $request, Geocoder $geocoder)
    {
        $money = 'nullable|numeric|min:0|max:100000';
        $data = $request->validate([
            'settings.default_hourly_rate' => 'required|numeric|min:0|max:10000',
            'settings.bill_travel' => 'nullable|boolean',
            'settings.travel_rate_percent' => 'required|numeric|min:0|max:200',
            'settings.parts_markup_percent' => 'required|numeric|min:0|max:500',
            'settings.minimum_billable_minutes' => 'required|integer|min:0|max:240',
            'skills' => 'array', 'skills.*.hourly_rate' => $money, 'skills.*.service_call_fee' => $money, 'skills.*.licensed' => 'nullable|boolean',
            'techs' => 'array', 'techs.*.hourly_rate' => $money, 'techs.*.trades' => 'array', 'techs.*.trades.*' => $money,
            'offices' => 'array', 'offices.*' => 'nullable|string|max:255',
        ]);

        $notes = [];
        DB::transaction(function () use ($data, $request, $geocoder, &$notes) {
            foreach (Setting::DEFAULTS as $key => $default) {
                Setting::put($key, (string) ($key === 'bill_travel' ? (int) $request->boolean('settings.bill_travel') : $data['settings'][$key]));
            }
            foreach ($data['skills'] ?? [] as $id => $row) {
                Skill::whereKey($id)->update([
                    'hourly_rate' => (float) ($row['hourly_rate'] ?? 0), 'service_call_fee' => (float) ($row['service_call_fee'] ?? 0),
                    'licensed' => ! empty($row['licensed']),
                ]);
            }
            foreach ($data['techs'] ?? [] as $id => $row) {
                $tech = Technician::find($id);
                if (! $tech) {
                    continue;
                }
                $tech->update(['hourly_rate' => ($row['hourly_rate'] ?? '') === '' ? null : (float) $row['hourly_rate']]);
                foreach ($row['trades'] ?? [] as $skillId => $rate) {
                    $rate = ($rate ?? '') === '' ? null : (float) $rate;
                    if ($tech->skills()->whereKey($skillId)->exists()) {
                        $tech->skills()->updateExistingPivot($skillId, ['hourly_rate' => $rate]);
                    } elseif ($rate !== null) {
                        $tech->skills()->attach($skillId, ['level' => 1, 'hourly_rate' => $rate]);
                    }
                }
            }
            foreach ($data['offices'] ?? [] as $id => $address) {
                $loc = Location::find($id);
                if (! $loc || trim((string) $address) === (string) $loc->office_address) {
                    continue;
                }
                $at = $address ? $geocoder->locate($address) : null;
                $loc->update(['office_address' => $address ?: null, 'office_lat' => $at[0] ?? null, 'office_lng' => $at[1] ?? null]);
                if ($address && ! $at) {
                    $notes[] = "Couldn't find \"{$address}\" on the map. Check the address.";
                }
            }
        });

        return redirect()->route('admin.rates')->with('status', trim('Rates saved. '.implode(' ', $notes)));
    }
}
