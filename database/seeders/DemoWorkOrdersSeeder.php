<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Message;
use App\Models\Property;
use App\Models\SlotOffer;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\WorkOrder;
use App\Services\Scheduling\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * About two weeks of made-up work orders around today (last week done, this week booked, a few
 * needing a person), so the schedule, map and queue have something to show. Beta only.
 */
class DemoWorkOrdersSeeder extends Seeder
{
    /** [name, street, city, lat, lng, units] — real Treasure Valley areas, made-up addresses. */
    public const PROPERTIES = [
        ['Maple Court', '1200 W Maple Ct', 'Boise', 43.6187, -116.2146, ['101', '102', '201']],
        ['Riverside Apartments', '450 E Riverside Dr', 'Meridian', 43.6121, -116.3915, ['A1', 'A2']],
        ['Canyon View', '88 Canyon View Ln', 'Nampa', 43.5407, -116.5635, ['1', '2']],
        ['Eagle Ridge Townhomes', '2150 E State St', 'Eagle', 43.6955, -116.3540, ['3', '7', '12']],
        ['Bench Duplexes', '1740 S Vista Ave', 'Boise', 43.5905, -116.2105, ['A', 'B']],
        ['Linden Street Fourplex', '615 Linden St', 'Caldwell', 43.6629, -116.6874, ['1', '2', '3', '4']],
        ['Five Mile Commons', '3300 N Five Mile Rd', 'Boise', 43.6390, -116.3180, ['110', '214', '305']],
        ['Kuna Meadows', '480 W Main St', 'Kuna', 43.4918, -116.4201, ['A', 'B']],
        ['Harris Ranch Cottages', '3900 E Parkcenter Blvd', 'Boise', 43.5730, -116.1440, ['5', '9']],
        ['Star Crossing', '120 N Star Rd', 'Star', 43.6921, -116.4935, ['1', '2']],
        ['Garrity Flats', '1600 E Garrity Blvd', 'Nampa', 43.5899, -116.5446, ['101', '102', '103']],
        ['North End Bungalow', '1514 N 14th St', 'Boise', 43.6280, -116.2050, ['Main']],
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

    public function run(): void
    {
        mt_srand(20261112);
        $idaho = Location::where('rm_location_id', 1)->firstOrFail();
        $tz = $idaho->timezone;
        $techs = Technician::with('skills', 'user')->where('location_id', $idaho->id)->where('active', true)->get();
        if ($techs->isEmpty()) {
            return;
        }

        $tenants = $this->properties($idaho);
        $today = CarbonImmutable::now($tz)->startOfDay();
        $days = collect(range(-7, 9))->map(fn ($d) => $today->addDays($d))->filter(fn ($d) => $d->isWeekday())->values();

        foreach ($days as $day) {
            foreach ($techs as $tech) {
                $windows = collect(self::WINDOWS)->shuffle()->take(mt_rand(2, 4))->sort()->values();
                foreach ($windows as $hour) {
                    $job = $this->jobFor($tech);
                    $start = $day->setTime($hour, [0, 0, 30][mt_rand(0, 2)]);
                    $this->scheduled($idaho, $this->nearbyTenant($tenants, $tech), $tech, $job, $start, $today);
                }
            }
        }

        $this->needsAPerson($idaho, $tenants, $techs, $today);
        $this->waitingOnTenant($idaho, $tenants, $techs, $today);
    }

    /** @return list<Tenant> */
    private function properties(Location $idaho): array
    {
        $tenants = [];
        foreach (self::PROPERTIES as $i => [$name, $street, $city, $lat, $lng, $units]) {
            $prop = Property::firstOrCreate(['location_id' => $idaho->id, 'rm_property_id' => 9000 + $i], [
                'name' => $name, 'address' => $street, 'city' => $city, 'state' => 'ID', 'lat' => $lat, 'lng' => $lng,
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
            'triage' => ['trade' => $trade, 'urgency' => $urgency, 'estimated_minutes' => $minutes, 'demo' => true],
        ];
    }

    private function scheduled(Location $loc, Tenant $tenant, Technician $tech, array $job, CarbonImmutable $start, CarbonImmutable $today): void
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

    private function waitingOnTenant(Location $loc, array $tenants, $techs, CarbonImmutable $today): void
    {
        foreach ([['appliance', 'routine', 60, 'Washer will not drain, clothes still soaking wet.'], ['locks', 'routine', 30, 'Mailbox key broke off in the lock.']] as $i => $job) {
            $tech = $techs[$i % $techs->count()];
            $wo = WorkOrder::create($this->base($loc, $tenants[($i * 7 + 2) % count($tenants)], $job) + ['status' => WorkOrder::STATUS_OFFERED]);
            foreach ([1, 2, 3] as $n) {
                $start = $today->addWeekdays($n)->setTime(self::WINDOWS[$n], 0);
                SlotOffer::create(['work_order_id' => $wo->id, 'technician_id' => $tech->id, 'option_number' => $n,
                    'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes(120)->utc(), 'score' => 10 - $n,
                    'sent_at' => now()->subHours(3), 'expires_at' => now()->addHours(21)]);
            }
            Message::create(['work_order_id' => $wo->id, 'tenant_id' => $wo->tenant_id, 'direction' => 'outbound', 'channel' => 'sms',
                'to_number' => $wo->contact_phone, 'body' => 'Axiom Maintenance: we can fix this. Reply 1, 2 or 3 to pick a time.', 'status' => 'logged']);
        }
    }
}
