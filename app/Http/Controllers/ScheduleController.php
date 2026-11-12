<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Scheduling\Geo;
use App\Services\Scheduling\RoutePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Calendar (day and week, one column per tech) and the route map. */
class ScheduleController extends Controller
{
    public const COLORS = ['#2563eb', '#db2777', '#059669', '#d97706', '#7c3aed', '#0891b2', '#dc2626', '#4d7c0f'];

    public const DAY_START = 7;

    public const DAY_END = 19;

    public function index(Request $request)
    {
        $loc = $this->location($request);
        $tz = $loc->timezone;
        $view = $request->query('view') === 'week' ? 'week' : 'day';
        $date = $this->date($request, $tz);
        $from = $view === 'week' ? $date->startOfWeek() : $date;
        $to = $view === 'week' ? $from->addDays(7) : $from->addDay();

        $techs = $this->techs($loc);
        $jobs = WorkOrder::with('property', 'unit', 'tenant')
            ->where('location_id', $loc->id)
            ->whereNotNull('technician_id')->whereNotNull('scheduled_start')
            ->where('status', '!=', WorkOrder::STATUS_CANCELLED)
            ->whereBetween('scheduled_start', [$from->utc(), $to->utc()])
            ->orderBy('scheduled_start')->get();

        return view('schedule.index', [
            'view' => $view, 'date' => $date, 'from' => $from, 'tz' => $tz, 'loc' => $loc,
            'techs' => $techs, 'jobs' => $jobs->groupBy('technician_id'),
            'colors' => $this->colors($techs),
            'unscheduled' => WorkOrder::with('property', 'unit')->where('location_id', $loc->id)
                ->whereIn('status', [WorkOrder::STATUS_NEW, WorkOrder::STATUS_TRIAGED, WorkOrder::STATUS_OFFERED])
                ->orderByRaw("case urgency when 'emergency' then 0 when 'urgent' then 1 else 2 end")->oldest()->get(),
            'dayStart' => self::DAY_START, 'dayEnd' => self::DAY_END,
        ]);
    }

    /** Drag-and-drop target: book or move a job to a tech and start time (texts the tenant). */
    public function move(Request $request, AutoScheduler $scheduler)
    {
        $data = $request->validate([
            'work_order_id' => 'required|exists:work_orders,id',
            'technician_id' => 'required|exists:technicians,id',
            'start' => 'required|date',
        ]);
        $wo = WorkOrder::with('location')->findOrFail($data['work_order_id']);
        abort_unless(in_array($wo->status, WorkOrder::OPEN_STATUSES, true), 422, 'This job is already finished.');

        $tz = $wo->location->timezone;
        $start = CarbonImmutable::parse($data['start'], $tz)->utc();
        $length = ($wo->scheduled_start && $wo->scheduled_end)
            ? max(30, (int) $wo->scheduled_start->diffInMinutes($wo->scheduled_end))
            : (int) config('axiom.scheduling.window_minutes');
        $moved = $wo->scheduled_start !== null;

        $scheduler->book($wo, Technician::with('user')->findOrFail($data['technician_id']), $start, $start->addMinutes($length), by: $request->user());
        $wo->clearReview();

        return response()->json(['ok' => true, 'message' => ($moved ? 'Moved ' : 'Booked ').$wo->reference.' and texted the tenant.']);
    }

    public function map(Request $request, RoutePlanner $planner)
    {
        $loc = $this->location($request);
        $tz = $loc->timezone;
        $date = $this->date($request, $tz);
        $techs = $this->techs($loc);
        $colors = $this->colors($techs);

        $routes = $techs->map(function (Technician $tech) use ($planner, $date, $colors) {
            $stops = $planner->planDay($tech, $date);
            $start = $tech->start_lat !== null ? [(float) $tech->start_lat, (float) $tech->start_lng] : null;
            $km = 0.0;
            $here = $start;
            foreach ($stops as $s) {
                $km += Geo::km($here, $s->coordinates()) ?? 0;
                $here = $s->coordinates() ?? $here;
            }

            return [
                'tech' => $tech, 'color' => $colors[$tech->id], 'start' => $start, 'stops' => $stops,
                'km' => round($km * 1.3, 1), 'driveMinutes' => Geo::driveMinutes($km),
            ];
        })->values();

        $points = $routes->map(fn ($r) => [
            'name' => $r['tech']->name, 'color' => $r['color'], 'start' => $r['start'],
            'stops' => $r['stops']->filter(fn ($s) => $s->coordinates())->map(fn ($s) => [
                'lat' => (float) $s->coordinates()[0], 'lng' => (float) $s->coordinates()[1], 'n' => $s->route_position,
                'ref' => $s->reference, 'time' => $s->scheduled_start->setTimezone($s->location->timezone ?? 'UTC')->format('g:i A'),
                'title' => $s->tradeLabel().' · '.$s->displayAddress(), 'status' => $s->status,
                'url' => route('queue.show', $s),
            ])->values(),
        ]);

        $waiting = WorkOrder::with('property', 'unit')->where('location_id', $loc->id)
            ->whereIn('status', [WorkOrder::STATUS_NEW, WorkOrder::STATUS_TRIAGED, WorkOrder::STATUS_OFFERED])->get()
            ->filter(fn ($w) => $w->coordinates())
            ->map(fn ($w) => ['lat' => (float) $w->coordinates()[0], 'lng' => (float) $w->coordinates()[1], 'ref' => $w->reference,
                'title' => $w->tradeLabel().' · '.$w->displayAddress(), 'url' => route('queue.show', $w)])->values();

        return view('schedule.map', compact('date', 'tz', 'routes', 'points', 'waiting', 'loc'));
    }

    private function location(Request $request): Location
    {
        return $request->user()->location
            ?? Location::where('enabled', true)->orderBy('rm_location_id')->first()
            ?? Location::orderBy('rm_location_id')->firstOrFail();
    }

    private function date(Request $request, string $tz): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $request->query('date', ''), $tz)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::now($tz)->startOfDay();
        }
    }

    /** @return Collection<int, Technician> */
    private function techs(Location $loc): Collection
    {
        return Technician::with('user', 'location')->where('location_id', $loc->id)->where('active', true)->orderBy('id')->get();
    }

    /** @return array<int, string> technician id => color, stable across pages */
    private function colors(Collection $techs): array
    {
        return $techs->values()->mapWithKeys(fn ($t, $i) => [$t->id => self::COLORS[$i % count(self::COLORS)]])->all();
    }
}
