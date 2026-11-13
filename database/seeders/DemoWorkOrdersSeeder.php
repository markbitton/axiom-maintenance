<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Location;
use App\Models\LocationPing;
use App\Models\Message;
use App\Models\Part;
use App\Models\Property;
use App\Models\Skill;
use App\Models\SlotOffer;
use App\Models\StockLocation;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Billing\Invoicer;
use App\Services\Geo\Geocoder;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Scheduling\Geo;
use App\Services\Scheduling\RouteOptimizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * About two weeks of made-up work orders around today (last week done, this week booked, a few
 * needing a person), so the schedule, map and queue have something to show. Beta only.
 */
class DemoWorkOrdersSeeder extends Seeder
{
    /** Home office used for testing. Geocoded on the server; the fallback is central Pocatello. */
    public const OFFICE = ['477 Taft Ave, Pocatello, ID 83201', 42.8713, -112.4455];

    /** [name, street, city, lat, lng, units, managed by Axiom PM?, bill to] — real Pocatello-area places, made-up addresses. */
    public const PROPERTIES = [
        ['Maple Court', '1200 N Maple St', 'Pocatello', 42.8790, -112.4470, ['101', '102', '201']],
        ['Riverside Apartments', '450 S 2nd Ave', 'Pocatello', 42.8560, -112.4430, ['A1', 'A2']],
        ['Canyon View', '88 Satterfield Dr', 'Pocatello', 42.8840, -112.4140, ['1', '2']],
        ['Yellowstone Townhomes', '4800 Yellowstone Ave', 'Chubbuck', 42.9150, -112.4640, ['3', '7', '12']],
        ['Bench Duplexes', '1740 S 5th Ave', 'Pocatello', 42.8440, -112.4270, ['A', 'B']],
        ['Center Street Fourplex', '615 E Center St', 'Pocatello', 42.8650, -112.4400, ['1', '2', '3', '4']],
        ['Alameda Commons', '1300 E Alameda Rd', 'Pocatello', 42.8930, -112.4350, ['110', '214', '305']],
        ['Inkom Meadows', '120 N Rapid St', 'Inkom', 42.7960, -112.2480, ['A', 'B']],
        ['Highland Cottages', '2300 Hiline Rd', 'Pocatello', 42.8990, -112.4120, ['5', '9']],
        ['Chubbuck Crossing', '5100 Hawthorne Rd', 'Chubbuck', 42.9300, -112.4800, ['1', '2']],
        ['Quinn Road Flats', '900 W Quinn Rd', 'Pocatello', 42.9010, -112.4690, ['101', '102', '103']],
        ['Old Town Bungalow', '514 N Arthur Ave', 'Pocatello', 42.8650, -112.4520, ['Main']],
        ['Broadway Rentals', '300 N Broadway St', 'Blackfoot', 43.1910, -112.3450, ['1', '2'], false, 'Hansen Family Trust (owner)'],
        ['Ross Park Duplex', '2900 S 2nd Ave', 'Pocatello', 42.8320, -112.4230, ['A', 'B'], false, 'R. Ortiz (owner)'],
    ];

    /** Demo billing: [hourly, service call fee, licensed] by kind of work. Only filled in where nobody has set a rate yet. */
    private const RATES = [
        'plumbing' => [95, 75, true], 'electrical' => [105, 75, true], 'hvac' => [110, 89, true], 'appliance' => [85, 59, false],
        'locks' => [75, 49, false], 'pest' => [70, 49, false], 'general' => [70, 49, false],
    ];

    private const PART_COSTS = [
        'PLB-WAX' => 6.5, 'PLB-FILL' => 14.0, 'PLB-FLAP' => 7.5, 'PLB-SUPPLY' => 9.0, 'PLB-PTRAP' => 12.0, 'PLB-CART' => 22.0,
        'ELC-GFCI' => 19.0, 'ELC-OUT' => 3.5, 'ELC-SW' => 4.0, 'ELC-SMOKE' => 24.0, 'ELC-BREAKER' => 11.0, 'HVAC-FLT' => 9.5,
        'HVAC-IGN' => 38.0, 'HVAC-TSTAT' => 65.0, 'HVAC-CAP' => 21.0, 'APP-DWHOSE' => 16.0, 'APP-ELEM' => 42.0, 'LCK-DB' => 34.0,
        'LCK-KNOB' => 28.0, 'LCK-REKEY' => 15.0,
    ];

    /** [trade, urgency, minutes, description] */
    private const JOBS = [
        ['plumbing', 'routine', 60, 'Kitchen faucet drips constantly, even when fully off.'],
        ['plumbing', 'routine', 45, 'Toilet keeps running after flushing.'],
        ['plumbing', 'urgent', 90, 'Bathroom sink drain is completely clogged and backing up.'],
        ['plumbing', 'routine', 60, 'Low water pressure in the shower.'],
        ['plumbing', 'urgent', 120, 'Water heater is leaking a little from the bottom.'],
        ['electrical', 'routine', 45, 'Outlet in the living room stopped working.'],
        ['electrical', 'routine', 30, 'Smoke detector chirping in the hallway.'],
        ['electrical', 'urgent', 60, 'Breaker for the kitchen keeps tripping.'],
        ['electrical', 'routine', 45, 'Bathroom fan is very loud and rattles.'],
        ['hvac', 'urgent', 90, 'Furnace is running but blowing cold air.'],
        ['hvac', 'routine', 45, 'Thermostat screen is blank.'],
        ['hvac', 'routine', 60, 'Furnace filter needs replacing and it smells dusty.'],
        ['appliance', 'routine', 60, 'Dishwasher leaves standing water at the bottom.'],
        ['appliance', 'routine', 60, 'Oven does not heat up, stovetop works.'],
        ['appliance', 'routine', 45, 'Garbage disposal hums but does not spin.'],
        ['appliance', 'urgent', 60, 'Refrigerator is not cooling, food getting warm.'],
        ['locks', 'routine', 30, 'Front door deadbolt is very hard to turn.'],
        ['locks', 'urgent', 45, 'Sliding door lock is broken and will not latch.'],
        ['general', 'routine', 60, 'Closet door came off its track.'],
        ['general', 'routine', 90, 'Several window blinds are broken in the bedroom.'],
        ['general', 'routine', 60, 'Bathroom caulk around the tub is moldy and peeling.'],
        ['general', 'routine', 45, 'Cabinet door hinge is broken in the kitchen.'],
        ['pest', 'routine', 45, 'Seeing ants in the kitchen near the window.'],
        ['pest', 'routine', 60, 'Mice droppings in the garage.'],
    ];

    private const WINDOWS = [8, 10, 13, 15];

    /** Bump when the demo set changes; beta's deploy then rebuilds it. */
    public const VERSION = 4;

    public function run(): void
    {
        mt_srand(20261113);
        $idaho = Location::where('rm_location_id', 1)->firstOrFail();
        $tz = $idaho->timezone;
        $office = $this->office($idaho);
        $this->moreTechs($idaho);
        $this->rates();
        $techs = Technician::with('skills', 'user', 'location')->where('location_id', $idaho->id)->where('active', true)->get();
        if ($techs->isEmpty()) {
            return;
        }
        // Everyone starts the day at the home office.
        Technician::whereIn('id', $techs->pluck('id'))->update(['start_lat' => $office[0], 'start_lng' => $office[1], 'start_address' => $idaho->office_address]);
        $techs->each->refresh();

        $tenants = $this->properties($idaho);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $days = collect(range(-7, 9))->map(fn ($d) => $today->addDays($d))->filter(fn ($d) => $d->isWeekday())->values();

        // History: last week and earlier today, already worked, placed directly.
        $now = CarbonImmutable::now($tz);
        foreach ($days->filter(fn ($d) => $d->lte($today)) as $day) {
            foreach ($techs as $tech) {
                $windows = collect(self::WINDOWS)->shuffle()->take(mt_rand(3, 4))->sort()->values();
                $worked = collect();
                foreach ($windows as $hour) {
                    $start = $day->setTime($hour, [0, 0, 30][mt_rand(0, 2)]);
                    if ($start->gte($now)) {
                        continue;
                    }
                    $worked->push($this->scheduled($idaho, $this->nearbyTenant($tenants, $tech), $tech, $this->jobFor($tech), $start, $today));
                }
                $this->clockDay($tech, $day, $worked, $office, $now);
            }
        }
        $this->twoVisitJob($idaho, $tenants, $techs->first(), $days->filter(fn ($d) => $d->lt($today))->values(), $office);
        $this->invoices();

        // From now on: plain requests, each run through the real auto-scheduler, which picks the
        // tech, day and time (or texts the tenant choices when there's no permission to enter).
        $scheduler = app(AutoScheduler::class);
        foreach (range(1, 12 * $techs->count()) as $n) {
            $job = self::JOBS[mt_rand(0, count(self::JOBS) - 1)];
            $tenant = $tenants[mt_rand(0, count($tenants) - 1)];
            $wo = WorkOrder::create(['status' => WorkOrder::STATUS_TRIAGED, 'permission_to_enter' => mt_rand(0, 9) > 0] + $this->base($idaho, $tenant, $job));
            $wo->forceFill(['created_at' => now()->subMinutes(mt_rand(5, 600))])->save();
            $scheduler->schedule($wo);
        }

        $this->needsAPerson($idaho, $tenants, $techs, $today);

        // Put each tech's booked days in the shortest driving order from the office (no texts for demo data).
        $optimizer = app(RouteOptimizer::class);
        foreach ($techs as $tech) {
            foreach ($days->filter(fn ($d) => $d->gte($today)) as $day) {
                $optimizer->optimizeDay($tech, $day, notify: false);
            }
        }
    }

    /** Wipe every work order and its history, then make a fresh demo set dated around today. */
    public static function reset(): void
    {
        DB::transaction(function () {
            InvoiceLine::query()->delete();
            TimeEntry::query()->update(['invoice_id' => null]);
            WorkOrderPart::query()->update(['invoice_id' => null]);
            Invoice::query()->delete();
            TimeEntry::query()->delete();
            LocationPing::query()->delete();
            Message::query()->delete();
            Activity::query()->delete();
            SlotOffer::query()->delete();
            WorkOrder::query()->delete();
            (new self)->run();
        });
    }

    /** True when the database holds the current version of the demo set. */
    public static function isCurrent(): bool
    {
        return WorkOrder::where('triage->demo', self::VERSION)->exists();
    }

    /** Two more techs with overlapping skills, so a week of demand fits like it would for a real crew. */
    private function moreTechs(Location $idaho): void
    {
        $skills = Skill::pluck('id', 'slug');
        foreach ([
            ['Chris Handy', 'chris@example.test', ['general' => 3, 'plumbing' => 2, 'appliance' => 2, 'locks' => 2], 43.6066, -116.3920],
            ['Pat Volt', 'pat@example.test', ['electrical' => 2, 'hvac' => 3, 'appliance' => 1], 43.6950, -116.3540],
        ] as [$name, $email, $levels, $lat, $lng]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => env('DEMO_PASSWORD', 'demo-password'),
                'role' => 'tech', 'location_id' => $idaho->id]);
            $tech = Technician::firstOrCreate(['user_id' => $user->id], ['location_id' => $idaho->id, 'start_lat' => $lat, 'start_lng' => $lng]);
            $tech->skills()->sync(collect($levels)->mapWithKeys(fn ($l, $s) => [$skills[$s] => ['level' => $l]])->all());
            StockLocation::firstOrCreate(['technician_id' => $tech->id, 'type' => 'truck'], ['location_id' => $idaho->id, 'name' => "{$name}'s truck"]);
        }
    }

    /** @return list<Tenant> */
    private function properties(Location $idaho): array
    {
        $tenants = [];
        foreach (self::PROPERTIES as $i => $row) {
            [$name, $street, $city, $lat, $lng, $units] = $row;
            $prop = Property::updateOrCreate(['location_id' => $idaho->id, 'rm_property_id' => 9000 + $i], [
                'name' => $name, 'address' => $street, 'city' => $city, 'state' => 'ID', 'lat' => $lat, 'lng' => $lng,
                'managed_by_axiom' => $row[6] ?? true, 'bill_to' => $row[7] ?? null,
            ]);
            foreach ($units as $j => $u) {
                $unit = Unit::firstOrCreate(['property_id' => $prop->id, 'rm_unit_id' => 90000 + $i * 10 + $j], ['name' => $u]);
                $tenants[] = Tenant::with('unit.property')->firstOrCreate(['location_id' => $idaho->id, 'rm_tenant_id' => 900000 + $i * 10 + $j], [
                    'unit_id' => $unit->id, 'name' => $this->tenantName($i * 10 + $j),
                    'phone' => sprintf('+1208555%02d%02d', $i, $j), 'sms_consent' => true,
                ]);
            }
        }

        return $tenants;
    }

    /** Mostly jobs close to the tech's start point, like a real dispatcher would hand out. */
    private function nearbyTenant(array $tenants, Technician $tech): Tenant
    {
        $home = [(float) $tech->start_lat, (float) $tech->start_lng];
        $near = collect($tenants)->sortBy(fn (Tenant $t) => Geo::km($home, [(float) $t->unit->property->lat, (float) $t->unit->property->lng]))->values();
        $pool = mt_rand(0, 4) ? $near->take(9) : $near;

        return $pool[mt_rand(0, $pool->count() - 1)];
    }

    private function tenantName(int $n): string
    {
        $first = ['Alex', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Jamie', 'Avery', 'Quinn', 'Drew', 'Reese', 'Skyler'];
        $last = ['Nguyen', 'Garcia', 'Smith', 'Johnson', 'Martinez', 'Lee', 'Brown', 'Davis', 'Lopez', 'Wilson', 'Clark', 'Young'];

        return $first[$n % 12].' '.$last[intdiv($n, 3) % 12];
    }

    private function jobFor(Technician $tech): array
    {
        $trades = $tech->skills->pluck('slug')->all();
        $fits = array_values(array_filter(self::JOBS, fn ($j) => in_array($j[0], $trades, true)));

        return $fits[mt_rand(0, count($fits) - 1)];
    }

    private function base(Location $loc, Tenant $tenant, array $job): array
    {
        [$trade, $urgency, $minutes, $desc] = $job;
        $unit = $tenant->unit()->with('property')->first();

        return [
            'location_id' => $loc->id, 'property_id' => $unit->property_id, 'unit_id' => $unit->id, 'tenant_id' => $tenant->id,
            'source' => mt_rand(0, 3) ? 'website' : 'rent_manager', 'category' => $trade, 'trade' => $trade, 'urgency' => $urgency,
            'description' => $desc, 'estimated_minutes' => $minutes, 'permission_to_enter' => (bool) mt_rand(0, 1),
            'contact_name' => $tenant->name, 'contact_phone' => $tenant->phone, 'triage_confidence' => mt_rand(78, 97) / 100,
            'triage' => ['trade' => $trade, 'urgency' => $urgency, 'estimated_minutes' => $minutes, 'demo' => self::VERSION],
        ];
    }

    private function scheduled(Location $loc, Tenant $tenant, Technician $tech, array $job, CarbonImmutable $start, CarbonImmutable $today): WorkOrder
    {
        $end = $start->addMinutes(120);
        $attrs = $this->base($loc, $tenant, $job) + [
            'technician_id' => $tech->id, 'scheduled_start' => $start->utc(), 'scheduled_end' => $end->utc(),
            'status' => WorkOrder::STATUS_SCHEDULED,
        ];
        $now = CarbonImmutable::now($loc->timezone);

        if ($start->lt($today)) {
            $done = $start->addMinutes(20 + $job[2]);
            $attrs = ['status' => mt_rand(0, 2) ? WorkOrder::STATUS_CLOSED : WorkOrder::STATUS_COMPLETED,
                'en_route_at' => $start->subMinutes(15)->utc(), 'started_at' => $start->addMinutes(10)->utc(),
                'completed_at' => $done->utc(), 'labor_minutes' => $job[2], 'tenant_rating' => [null, 4, 5, 5][mt_rand(0, 3)],
                'completion_notes' => 'Fixed. Tested and working.'] + $attrs;
            $attrs['closed_at'] = $attrs['status'] === WorkOrder::STATUS_CLOSED ? $done->addHours(2)->utc() : null;
        } elseif ($start->isSameDay($now) && $start->lt($now)) {
            $finished = $start->addMinutes($job[2] + 20)->lt($now);
            $attrs = ['status' => $finished ? WorkOrder::STATUS_COMPLETED : WorkOrder::STATUS_IN_PROGRESS,
                'en_route_at' => $start->subMinutes(15)->utc(), 'started_at' => $start->addMinutes(10)->utc(),
                'completed_at' => $finished ? $start->addMinutes($job[2] + 20)->utc() : null] + $attrs;
        }

        $wo = WorkOrder::create($attrs);
        $wo->forceFill(['created_at' => $start->subDays(mt_rand(1, 4))->utc()])->save();
        $wo->log(mt_rand(0, 1) ? 'auto_booked' : 'booked', ['technician_id' => $tech->id, 'demo' => true]);

        // Most finished jobs used a part or two from the truck.
        if ($wo->completed_at && mt_rand(0, 2)) {
            $part = Part::where('trade', $job[0])->inRandomOrder()->first();
            if ($part) {
                $wo->parts()->create(['part_id' => $part->id, 'part_name' => $part->name, 'quantity' => mt_rand(1, 2), 'used' => true]);
            }
        }

        return $wo;
    }

    /** Home office for the Idaho location: geocoded on the server when possible, else central Pocatello. */
    private function office(Location $idaho): array
    {
        [$address, $lat, $lng] = self::OFFICE;
        if ($idaho->office_address !== $address || ! $idaho->office()) {
            $at = app(Geocoder::class)->locate($address) ?? [$lat, $lng];
            $idaho->update(['office_address' => $address, 'office_lat' => $at[0], 'office_lng' => $at[1]]);
        }

        return $idaho->office();
    }

    private function rates(): void
    {
        foreach (self::RATES as $slug => [$rate, $fee, $licensed]) {
            Skill::where('slug', $slug)->where('hourly_rate', 0)->update(['hourly_rate' => $rate, 'service_call_fee' => $fee, 'licensed' => $licensed]);
        }
        foreach (self::PART_COSTS as $sku => $cost) {
            Part::where('sku', $sku)->where('unit_cost', 0)->update(['unit_cost' => $cost]);
        }
        // A couple of tech-specific rates so the rate card shows how overrides work.
        $mike = Technician::whereHas('user', fn ($q) => $q->where('email', 'mike@example.test'))->first();
        if ($mike && $mike->hourly_rate === null) {
            $mike->update(['hourly_rate' => 90]);
        }
        $dana = Technician::whereHas('user', fn ($q) => $q->where('email', 'dana@example.test'))->first();
        $electrical = Skill::where('slug', 'electrical')->value('id');
        if ($dana && $electrical && $dana->skills()->whereKey($electrical)->exists() && $dana->skills()->whereKey($electrical)->first()->pivot->hourly_rate === null) {
            $dana->skills()->updateExistingPivot($electrical, ['hourly_rate' => 120]);
        }
    }

    /**
     * A believable day on the clocks: clock in at the office, drive to each job, work it, lunch,
     * drive back, clock out. GPS check-ins every 15 minutes along the way.
     */
    private function clockDay(Technician $tech, CarbonImmutable $day, $jobs, array $office, CarbonImmutable $now): void
    {
        $jobs = $jobs->filter(fn (WorkOrder $w) => $w->started_at)->sortBy('scheduled_start')->values();
        if ($jobs->isEmpty()) {
            return;
        }
        $tz = $day->getTimezone();
        $clockIn = $day->setTime(7, 40 + mt_rand(0, 15));
        $entries = [['shift', null, $clockIn, null, $office]];
        $here = $office;
        $cursor = $clockIn->addMinutes(mt_rand(5, 20));
        $lunch = false;

        foreach ($jobs as $wo) {
            $drive = Geo::driveMinutes(Geo::km($here, $wo->coordinates())) + mt_rand(0, 6);
            $arrive = $wo->started_at->toImmutable()->setTimezone($tz);
            $leave = $arrive->subMinutes($drive);
            if ($leave->lt($cursor)) {
                $leave = $cursor;
                $arrive = $cursor->addMinutes($drive);
            }
            if (! $lunch && $leave->hour >= 12) {
                $entries[] = ['break', null, $cursor, $cursor->addMinutes(30), $here];
                $lunch = true;
                $leave = $leave->max($cursor->addMinutes(30));
                $arrive = $leave->addMinutes($drive);
            }
            if ($arrive->gte($now)) {
                break;
            }
            $entries[] = ['travel', $wo, $leave, $arrive, $here];
            $done = $wo->completed_at?->toImmutable()->setTimezone($tz);
            // On-site time: the job itself plus setup, cleanup and talking with the tenant.
            $finish = $done ? $arrive->addMinutes((int) $wo->estimated_minutes + mt_rand(15, 45)) : null;
            $entries[] = ['work', $wo, $arrive, $finish && $finish->lt($now) ? $finish : null, $wo->coordinates() ?? $here];
            $over = $finish && $finish->lt($now);
            $wo->forceFill(['en_route_at' => $leave->utc(), 'started_at' => $arrive->utc(), 'completed_at' => $over ? $finish->utc() : null]
                + ($over ? [] : ['status' => WorkOrder::STATUS_IN_PROGRESS, 'closed_at' => null]))->save();
            $here = $wo->coordinates() ?? $here;
            if (! $finish || $finish->gte($now)) {
                $cursor = null;
                break;
            }
            $cursor = $finish->addMinutes(mt_rand(5, 25)); // paperwork, parts run, etc.
        }

        $clockOut = $cursor ? $cursor->addMinutes(Geo::driveMinutes(Geo::km($here, $office)) + mt_rand(5, 20)) : null;
        if ($clockOut && $clockOut->gte($now)) {
            $clockOut = null;
        }
        if ($cursor && $clockOut) {
            $entries[] = ['travel', null, $cursor, $clockOut->subMinutes(mt_rand(3, 8)), $here];
        }
        $entries[0][3] = $clockOut;

        foreach ($entries as [$type, $wo, $from, $to, $at]) {
            TimeEntry::create([
                'technician_id' => $tech->id, 'work_order_id' => $wo?->id, 'type' => $type,
                'started_at' => $from->utc(), 'ended_at' => $to?->utc(),
                'start_lat' => $at[0], 'start_lng' => $at[1], 'end_lat' => $to ? $at[0] : null, 'end_lng' => $to ? $at[1] : null,
            ]);
        }

        // GPS every 15 minutes while on the clock, at whichever stop they were at or heading to.
        $pings = [];
        $stamp = now();
        for ($t = $clockIn; $t->lt($clockOut ?? $now); $t = $t->addMinutes(15)) {
            $spot = $office;
            foreach ($entries as [$type, $wo, $from, $to, $at]) {
                if ($type !== 'shift' && $t->gte($from) && (! $to || $t->lt($to))) {
                    $spot = $type === 'travel' && $wo ? $this->between($at, $wo->coordinates() ?? $at, $from, $to, $t) : $at;
                }
            }
            $pings[] = ['technician_id' => $tech->id, 'lat' => round($spot[0] + mt_rand(-20, 20) / 100000, 7), 'lng' => round($spot[1] + mt_rand(-20, 20) / 100000, 7),
                'accuracy_m' => mt_rand(5, 30), 'reason' => $t->eq($clockIn) ? 'clock_in' : 'interval', 'recorded_at' => $t->utc(), 'created_at' => $stamp, 'updated_at' => $stamp];
        }
        foreach (array_chunk($pings, 200) as $chunk) {
            LocationPing::insert($chunk);
        }
    }

    private function between(array $a, array $b, CarbonImmutable $from, ?CarbonImmutable $to, CarbonImmutable $t): array
    {
        $span = $to ? max(1, $from->diffInSeconds($to)) : 1;
        $f = min(1, max(0, $from->diffInSeconds($t) / $span));

        return [$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f];
    }

    /**
     * A job that took two visits: the first visit was invoiced on its own (fee, labor, parts),
     * the second visit is done but not billed yet, so "Create invoice" bills only the second visit.
     */
    private function twoVisitJob(Location $loc, array $tenants, Technician $tech, $pastDays, array $office): void
    {
        if ($pastDays->count() < 4) {
            return;
        }
        $tz = $loc->timezone;
        $first = $pastDays[0]->setTime(9, 0);
        $second = $pastDays[$pastDays->count() - 2]->setTime(14, 0);
        $wo = WorkOrder::create($this->base($loc, $tenants[1], ['plumbing', 'urgent', 180, 'Water heater leaking from the tank. Needs replacing.']) + [
            'technician_id' => $tech->id, 'scheduled_start' => $second->utc(), 'scheduled_end' => $second->addHours(2)->utc(),
            'status' => WorkOrder::STATUS_COMPLETED, 'en_route_at' => $first->subMinutes(15)->utc(), 'started_at' => $first->utc(),
            'completed_at' => $second->addMinutes(150)->utc(), 'labor_minutes' => 240,
            'completion_notes' => $first->format('M j').": Drained tank, shut off gas, ordered a 40 gal water heater.\n"
                .$second->format('M j').': Installed new water heater, new supply lines, tested. Hauled old tank away.',
        ]);
        $wo->forceFill(['created_at' => $first->subDay()->utc()])->save();
        $wo->log('booked', ['demo' => true]);

        $visit = function (CarbonImmutable $at, int $drive, int $work) use ($tech, $wo, $office) {
            TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'travel', 'started_at' => $at->subMinutes($drive)->utc(),
                'ended_at' => $at->utc(), 'start_lat' => $office[0], 'start_lng' => $office[1]]);
            TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'work', 'started_at' => $at->utc(),
                'ended_at' => $at->addMinutes($work)->utc(), 'start_lat' => $wo->coordinates()[0] ?? null, 'start_lng' => $wo->coordinates()[1] ?? null]);
        };

        $visit($first, 14, 75);
        if ($p = Part::where('sku', 'PLB-SUPPLY')->first()) {
            $wo->parts()->create(['part_id' => $p->id, 'part_name' => $p->name, 'quantity' => 1, 'used' => true]);
        }
        $invoice = app(Invoicer::class)->create($wo, null, 'Visit 1 of 2: diagnosis, drain and shut-off. Water heater ordered.');
        $invoice->update(['status' => 'sent', 'sent_at' => $first->addDay()->utc(), 'created_at' => $first->addHours(3)->utc()]);
        $wo->log('return_visit_needed', ['notes' => 'Waiting on water heater']);

        $visit($second, 16, 150);
        $heater = Part::firstOrCreate(['sku' => 'PLB-WH40'], ['name' => '40 gal gas water heater', 'trade' => 'plumbing', 'keywords' => ['water heater'], 'unit_cost' => 649]);
        $wo->parts()->create(['part_id' => $heater->id, 'part_name' => $heater->name, 'quantity' => 1, 'used' => true]);
        if ($p = Part::where('sku', 'PLB-SUPPLY')->first()) {
            $wo->parts()->create(['part_id' => $p->id, 'part_name' => $p->name, 'quantity' => 2, 'used' => true]);
        }
    }

    /** Bill most closed jobs (paid or sent); leave the recently completed ones for the office to invoice. */
    private function invoices(): void
    {
        $invoicer = app(Invoicer::class);
        WorkOrder::with('property', 'location')->where('status', WorkOrder::STATUS_CLOSED)->whereNotNull('completed_at')
            ->orderBy('completed_at')->get()
            ->each(function (WorkOrder $wo, $i) use ($invoicer) {
                if ($i % 5 === 4) {
                    return; // a few closed jobs still waiting on an invoice
                }
                try {
                    $inv = $invoicer->create($wo, null);
                } catch (\RuntimeException) {
                    return;
                }
                $done = $wo->completed_at->toImmutable();
                $paid = $done->lt(now()->subDays(3)) && mt_rand(0, 3) > 0;
                $inv->forceFill([
                    'status' => $paid ? 'paid' : 'sent', 'created_at' => $done->addHours(3), 'sent_at' => $done->addHours(4),
                    'paid_at' => $paid ? $done->addDays(mt_rand(1, 3)) : null,
                ])->save();
            });
    }

    private function needsAPerson(Location $loc, array $tenants, $techs, CarbonImmutable $today): void
    {
        $cases = [
            [['general', 'routine', 120, 'There is a brown stain spreading on the ceiling under the upstairs bathroom. Not dripping yet.'], 'Not sure which trade this is'],
            [['hvac', 'urgent', 90, 'Furnace makes a loud bang when it starts, then runs. Started last night.'], 'No tech has an open slot within 3 business days'],
            [['plumbing', 'routine', 60, 'Outdoor spigot is leaking. Can someone come Saturday? I work weekdays.'], 'Tenant replied with a question instead of a number'],
            [['general', 'routine', 240, 'Back fence panel blew down in the wind, two panels missing.'], 'Looks like a job for an outside vendor'],
            [['electrical', 'emergency', 60, 'Burning smell coming from the outlet behind the fridge, outlet is warm.'], 'Emergency: on-call tech has not confirmed'],
        ];
        foreach ($cases as $i => [$job, $reason]) {
            $wo = WorkOrder::create($this->base($loc, $tenants[($i * 5 + 3) % count($tenants)], $job) + ['status' => WorkOrder::STATUS_TRIAGED]);
            $wo->forceFill(['review_reason' => $reason, 'needs_review_at' => now()->subMinutes(20 + $i * 47),
                'created_at' => now()->subMinutes(30 + $i * 50)])->save();
            if ($i === 2) {
                Message::create(['work_order_id' => $wo->id, 'tenant_id' => $wo->tenant_id, 'direction' => 'inbound', 'channel' => 'sms',
                    'from_number' => $wo->contact_phone, 'body' => 'Can someone come Saturday? I work weekdays.']);
            }
        }
    }
}
