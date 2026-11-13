<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Technician;
use App\Services\Time\Efficiency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Tech efficiency: how much of each day on the employee clock went to work orders. */
class EfficiencyController extends Controller
{
    public function index(Request $request, Efficiency $efficiency)
    {
        $loc = Location::find($request->integer('location'))
            ?? Location::whereHas('technicians', fn ($q) => $q->where('active', true))->orderByDesc('enabled')->orderBy('rm_location_id')->firstOrFail();
        $tz = $loc->timezone;
        try {
            $end = CarbonImmutable::parse((string) $request->query('week', ''), $tz)->startOfDay();
        } catch (\Throwable) {
            $end = CarbonImmutable::now($tz)->startOfDay();
        }
        $from = $end->startOfWeek();

        $rows = Technician::with('user', 'location')->where('location_id', $loc->id)->where('active', true)->orderBy('id')->get()
            ->map(function (Technician $tech) use ($efficiency, $from) {
                $days = collect($efficiency->range($tech, $from, 7))->filter(fn ($d, $date) => $d['shift'] > 0 || CarbonImmutable::parse($date)->isWeekday());
                $total = $efficiency->summarize([]);
                foreach ($days as $d) {
                    foreach (['shift', 'work', 'travel_axiom', 'travel_other', 'break', 'idle'] as $k) {
                        $total[$k] += $d[$k];
                    }
                }
                $total['utilization'] = $total['shift'] > 0 ? round(min(1, ($total['work'] + $total['travel_axiom']) / $total['shift']), 3) : null;

                return ['tech' => $tech, 'days' => $days, 'total' => $total, 'lastPing' => $tech->pings()->latest('recorded_at')->first()];
            });

        return view('admin.efficiency', [
            'rows' => $rows, 'from' => $from, 'tz' => $tz, 'loc' => $loc,
            'locations' => Location::whereHas('technicians')->orderBy('rm_location_id')->get(),
        ]);
    }
}
