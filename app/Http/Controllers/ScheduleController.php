<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Scheduling\Geo;
use App\Services\Scheduling\Planner;
use App\Services\Scheduling\RoutePlanner;
use App\Services\Scheduling\Slot;
use App\Services\Scheduling\SlotFinder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
            'locations' => Location::orderBy('rm_location_id')->get(),
            'focus' => $request->integer('find') ?: null,
            'proposals' => WorkOrder::with('property', 'unit', 'proposedTechnician.user')->where('location_id', $loc->id)
                ->whereNotNull('proposed_start')->orderBy('proposed_start')->get(),
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

    /** Suggest tech + time for every unbooked job ("place"), or drive-saving moves for booked visits 24+ hours out ("move"). */
    public function propose(Request $request, Planner $planner)
    {
        $loc = $this->location($request);
        $date = $this->date($request, $loc->timezone);
        if ($request->input('kind') === 'move') {
            $r = $planner->proposeMoves($loc);
            $msg = $r['moves']
                ? "Suggested {$r['moves']} ".Str::plural('move', $r['moves'])." that save about {$r['saved']} min of driving. Nothing changes until you approve; tenants get at least 24 hours' notice."
                : 'No moves would save meaningful driving for visits 24+ hours away.';
        } else {
            $n = $planner->proposeUnbooked($loc);
            $msg = $n ? "Placed {$n} unbooked ".Str::plural('job', $n).' on the calendar as suggestions. Tick the ones you want and press Approve.'
                : 'No unbooked jobs could be placed in the next few days.';
            $first = WorkOrder::where('location_id', $loc->id)->where('proposed_kind', 'place')->min('proposed_start');
            if ($first) {
                $date = CarbonImmutable::parse($first)->setTimezone($loc->timezone);
            }
        }

        return redirect()->route('schedule', ['date' => $date->toDateString(), 'view' => $request->input('view', 'day')])->with('status', $msg);
    }

    public function approve(Request $request, Planner $planner)
    {
        $data = $request->validate(['ids' => 'array', 'ids.*' => 'integer', 'action' => 'required|in:approve,dismiss']);
        $ids = collect($data['ids'] ?? [])->unique();
        if ($ids->isEmpty()) {
            return back()->withErrors(['ids' => 'Tick at least one suggestion first.']);
        }
        if ($data['action'] === 'dismiss') {
            $planner->dismiss($ids);

            return back()->with('status', 'Dismissed '.$ids->count().' '.Str::plural('suggestion', $ids->count()).'.');
        }
        $n = $planner->approve($ids, $request->user());

        return back()->with('status', "Approved {$n}. Tenants were texted their times.");
    }

    public function map(Request $request, RoutePlanner $planner)
    {
        $loc = $this->location($request);
        $tz = $loc->timezone;
        $date = $this->date($request, $tz);
        $techs = $this->techs($loc);
        $colors = $this->colors($techs);

        $office = $loc->office();
        $routes = $techs->map(function (Technician $tech) use ($planner, $date, $colors, $office) {
            $stops = $planner->planDay($tech, $date);
            $start = $office ?? ($tech->start_lat !== null ? [(float) $tech->start_lat, (float) $tech->start_lng] : null);
            $km = 0.0;
            $here = $start;
            foreach ($stops as $s) {
                $km += Geo::km($here, $s->coordinates()) ?? 0;
                $here = $s->coordinates() ?? $here;
            }
            if ($office && $stops->isNotEmpty()) {
                $km += Geo::km($here, $office) ?? 0;
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

        $locations = Location::orderBy('rm_location_id')->get();

        $office = $office ? ['lat' => $office[0], 'lng' => $office[1], 'address' => $loc->office_address] : null;

        return view('schedule.map', compact('date', 'tz', 'routes', 'points', 'waiting', 'loc', 'locations', 'office'));
    }

    /** The location being viewed: picked in the page, else the last one picked, else one that has techs. */
    private function location(Request $request): Location
    {
        $picked = $request->integer('location') ?: (int) $request->session()->get('schedule_location');
        $loc = $picked ? Location::find($picked) : null;
        if ($loc && $request->filled('location')) {
            $request->session()->put('schedule_location', $loc->id);
        }

        $withTechs = fn () => Location::whereHas('technicians', fn ($q) => $q->where('active', true));

        return $loc
            ?? ($request->user()->location && $withTechs()->whereKey($request->user()->location_id)->exists() ? $request->user()->location : null)
            ?? $withTechs()->orderByDesc('enabled')->orderBy('rm_location_id')->first()
            ?? Location::orderBy('rm_location_id')->firstOrFail();
    }

    /** "Find best tech and time": techs with the right skill and room for the whole job, best first, with the reasons. */
    public function suggest(WorkOrder $workOrder, SlotFinder $finder)
    {
        $workOrder->loadMissing('location', 'property', 'unit');
        $tz = $workOrder->location->timezone;
        $techs = $this->techs($workOrder->location);
        $colors = $this->colors($techs);
        $levels = [1 => 'Basic', 2 => 'Solid', 3 => 'Expert'];
        $hours = fn (int $m) => $m < 60 ? $m.' min' : rtrim(rtrim(number_format($m / 60, 1), '0'), '.').' hr';

        $found = $finder->bestPerTech($workOrder, 1, 4);
        $slots = collect($found)->values()->map(function (Slot $slot, $i) use ($tz, $colors, $levels, $workOrder, $hours) {
            $start = $slot->start->setTimezone($tz);
            $w = $slot->why;
            $reasons = [];
            if ($workOrder->trade) {
                $reasons[] = ($levels[$w['skill_level'] ?? 2] ?? 'Solid').' at '.$workOrder->tradeLabel();
            }
            $reasons[] = $hours((int) ($w['open_minutes'] ?? 0)).' open that day, room for the whole '.$hours((int) ($w['job_minutes'] ?? 60)).' job';
            if (! empty($w['same_building'])) {
                $reasons[] = 'already at this building that day';
            } elseif (($w['nearest_km'] ?? null) !== null) {
                $reasons[] = Geo::driveMinutes($w['nearest_km']).' min drive from their nearest stop';
            }
            $reasons[] = ($w['jobs_that_day'] ?? 0) === 0 ? 'nothing else booked that day' : $w['jobs_that_day'].' other '.Str::plural('job', $w['jobs_that_day']).' that day';

            return [
                'best' => $i === 0,
                'technician_id' => $slot->technician->id,
                'tech' => $slot->technician->name,
                'color' => $colors[$slot->technician->id] ?? '#6b7280',
                'date' => $start->toDateString(),
                'start' => $start->format('Y-m-d H:i'),
                'minute_of_day' => $start->hour * 60 + $start->minute,
                'label' => $start->format('D M j').', '.$start->format('g:i').'–'.$slot->end->setTimezone($tz)->format('g:i A'),
                'reasons' => $reasons,
            ];
        });

        $qualified = $finder->qualifiedTechs($workOrder);
        $withRoom = $slots->pluck('technician_id')->unique();

        return response()->json([
            'job' => [
                'id' => $workOrder->id, 'ref' => $workOrder->reference, 'trade' => $workOrder->tradeLabel(),
                'urgency' => $workOrder->urgency, 'minutes' => (int) ($workOrder->estimated_minutes ?: 60),
                'where' => trim(($workOrder->property?->name ?? $workOrder->address_text).' '.($workOrder->unit?->name ?? '')),
            ],
            'slots' => $slots,
            'qualified' => $qualified->count(),
            'no_room' => $qualified->reject(fn ($t) => $withRoom->contains($t->id))->map->name->values(),
        ]);
    }

    private function date(Request $request, string $tz): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $request->input('date', ''), $tz)->startOfDay();
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
