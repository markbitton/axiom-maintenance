<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Part;
use App\Models\Property;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\TimeEntry;
use App\Models\WorkOrder;
use App\Services\Billing\Invoicer;
use App\Services\Billing\RateCard;
use App\Services\Scheduling\RouteOptimizer;
use App\Services\Time\Efficiency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class TimeBillingTest extends MaintenanceTestCase
{
    private const GPS = ['lat' => 43.61, 'lng' => -116.2, 'accuracy' => 12];

    private function job($tech, array $attrs = []): WorkOrder
    {
        return WorkOrder::create($attrs + ['location_id' => $this->idaho->id, 'property_id' => $this->property->id, 'tenant_id' => $this->tenant->id,
            'description' => 'Faucet drips', 'trade' => 'plumbing', 'status' => WorkOrder::STATUS_SCHEDULED, 'technician_id' => $tech->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2)]);
    }

    private function later(int $minutes): void
    {
        $t = CarbonImmutable::now()->addMinutes($minutes);
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    public function test_clocks_need_location_services(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $this->actingAs($tech->user)->post('/tech/clock/in')->assertSessionHasErrors('lat');
        $this->assertSame(0, TimeEntry::count());

        $this->actingAs($tech->user)->post('/tech/clock/in', self::GPS)->assertRedirect();
        $this->assertSame(1, TimeEntry::where('type', 'shift')->whereNull('ended_at')->count());
        $this->assertSame(1, $tech->pings()->where('reason', 'clock_in')->count());

        $this->actingAs($tech->user)->postJson('/tech/ping', self::GPS)->assertJson(['ok' => true]);
        $this->actingAs($tech->user)->postJson('/tech/ping', [])->assertStatus(422);
        $this->assertSame(2, $tech->pings()->count());
    }

    public function test_job_clocks_travel_work_break_and_finish(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $wo = $this->job($tech);

        // "On my way" clocks the tech in if they forgot, then runs the travel clock.
        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/on-my-way", self::GPS)->assertRedirect();
        $this->assertNotNull(TimeEntry::where('type', 'shift')->whereNull('ended_at')->first());
        $this->assertSame('travel', TimeEntry::whereNull('ended_at')->where('type', '!=', 'shift')->value('type'));

        $this->later(20);
        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/start", self::GPS)->assertRedirect();
        $this->later(40);
        $this->actingAs($tech->user)->post('/tech/break/start', self::GPS)->assertRedirect();
        $this->later(30);
        $this->actingAs($tech->user)->post('/tech/break/end', self::GPS)->assertRedirect();
        $this->later(25);
        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/complete", ['notes' => 'New cartridge'] + self::GPS)->assertRedirect('/tech');

        $minutes = fn ($type) => TimeEntry::where('type', $type)->get()->sum(fn ($e) => $e->minutes());
        $this->assertSame(20, $minutes('travel'));
        $this->assertSame(65, $minutes('work'));
        $this->assertSame(30, $minutes('break'));
        $this->assertSame(0, TimeEntry::whereNull('ended_at')->where('type', '!=', 'shift')->count(), 'complete stops the job clock');
        $this->assertSame(65, $wo->fresh()->labor_minutes, 'labor comes from the job clock, not counting the break');

        $this->actingAs($tech->user)->post('/tech/clock/out', self::GPS)->assertRedirect();
        $this->assertSame(0, TimeEntry::whereNull('ended_at')->count());
    }

    public function test_rate_card_uses_the_most_specific_rate(): void
    {
        $tech = $this->tech('Dana Sparks', ['electrical' => 3, 'hvac' => 2]);
        Setting::put('default_hourly_rate', '60');
        $rates = app(RateCard::class);
        $this->assertSame(60.0, $rates->hourly($tech, 'electrical'));

        Skill::where('slug', 'electrical')->update(['hourly_rate' => 105]);
        $this->assertSame(105.0, $rates->hourly($tech, 'electrical'));
        $this->assertSame(60.0, $rates->hourly($tech, 'hvac'));

        $tech->update(['hourly_rate' => 90]);
        $this->assertSame(90.0, $rates->hourly($tech->fresh(), 'electrical'));

        $tech->skills()->updateExistingPivot(Skill::where('slug', 'electrical')->value('id'), ['hourly_rate' => 125]);
        $this->assertSame(125.0, $rates->hourly($tech->fresh(), 'electrical'));
        $this->assertSame(90.0, $rates->hourly($tech->fresh(), 'hvac'));
    }

    public function test_partial_billing_never_bills_the_same_work_twice(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        Skill::where('slug', 'plumbing')->update(['hourly_rate' => 100, 'service_call_fee' => 75]);
        Setting::put('minimum_billable_minutes', '15');
        $part = Part::where('sku', 'PLB-CART')->first();
        $part->update(['unit_cost' => 20]);
        Setting::put('parts_markup_percent', '10');
        $wo = $this->job($tech);
        $at = CarbonImmutable::now();

        $e1 = TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'work', 'started_at' => $at, 'ended_at' => $at->addMinutes(90)]);
        TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'travel', 'started_at' => $at->subMinutes(20), 'ended_at' => $at]);
        $wo->parts()->create(['part_id' => $part->id, 'part_name' => $part->name, 'quantity' => 2, 'used' => true]);
        $wo->parts()->create(['part_id' => $part->id, 'part_name' => 'Suggested only', 'quantity' => 1, 'used' => false]);

        $admin = $this->staff('admin');
        $this->actingAs($admin)->post("/work-orders/{$wo->id}/invoices")->assertRedirect();
        $first = Invoice::firstOrFail();
        $this->assertSame('75.00', $first->fees_total);
        $this->assertSame('150.00', $first->labor_total, '1.5 h at the plumbing rate');
        $this->assertSame('0.00', $first->travel_total, 'travel is not billed unless turned on');
        $this->assertSame('44.00', $first->parts_total, '2 x $20 + 10%');
        $this->assertSame('269.00', $first->total);
        $this->assertSame('Axiom Property Management', $first->bill_to);
        $this->assertSame($first->id, $e1->fresh()->invoice_id);

        // Nothing new: no second invoice.
        $this->actingAs($admin)->post("/work-orders/{$wo->id}/invoices")->assertSessionHasErrors('invoice');
        $this->assertSame(1, Invoice::count());

        // A week later the tech comes back. Only the new visit is billed, and no second service call fee.
        $later = $at->addWeek();
        TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'work', 'started_at' => $later, 'ended_at' => $later->addMinutes(10)]);
        $second = app(Invoicer::class)->create($wo->fresh());
        $this->assertSame('0.00', $second->fees_total);
        $this->assertSame('25.00', $second->labor_total, 'minimum 15 minutes');
        $this->assertSame('0.00', $second->parts_total);

        // Voiding the first invoice frees its work so it can be billed again.
        $this->actingAs($admin)->post("/invoices/{$first->id}/status", ['status' => 'void'])->assertRedirect();
        $this->assertNull($e1->fresh()->invoice_id);
        $third = app(Invoicer::class)->create($wo->fresh());
        $this->assertSame('269.00', $third->total);
        $this->assertNotSame($first->number, $third->number);
    }

    public function test_owner_managed_property_bills_owner_and_travel_when_turned_on(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $owner = Property::create(['location_id' => $this->idaho->id, 'rm_property_id' => 77, 'name' => 'Ross Park Duplex', 'address' => '1 Main',
            'city' => 'Pocatello', 'state' => 'ID', 'managed_by_axiom' => false, 'bill_to' => 'R. Ortiz (owner)']);
        $wo = $this->job($tech, ['property_id' => $owner->id]);
        Setting::put('bill_travel', '1');
        Setting::put('travel_rate_percent', '50');
        Setting::put('default_hourly_rate', '80');
        $at = CarbonImmutable::now();
        TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'travel', 'started_at' => $at, 'ended_at' => $at->addMinutes(30)]);
        TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'work', 'started_at' => $at->addMinutes(30), 'ended_at' => $at->addMinutes(90)]);

        $inv = app(Invoicer::class)->create($wo);
        $this->assertSame('R. Ortiz (owner)', $inv->bill_to);
        $this->assertSame('20.00', $inv->travel_total, '0.5 h at half of $80');
        $this->assertSame('80.00', $inv->labor_total);
    }

    public function test_efficiency_counts_axiom_drive_time_as_utilized(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $owner = Property::create(['location_id' => $this->idaho->id, 'rm_property_id' => 78, 'name' => 'Owner place', 'address' => '2 Main',
            'city' => 'Pocatello', 'state' => 'ID', 'managed_by_axiom' => false]);
        $axiomJob = $this->job($tech);
        $ownerJob = $this->job($tech, ['property_id' => $owner->id]);
        $day = CarbonImmutable::parse('2026-11-09 08:00', 'America/Boise');
        $add = fn ($type, $wo, $from, $mins) => TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo?->id, 'type' => $type,
            'started_at' => $day->addMinutes($from)->utc(), 'ended_at' => $day->addMinutes($from + $mins)->utc()]);
        $add('shift', null, 0, 480);
        $add('travel', $axiomJob, 0, 30);
        $add('work', $axiomJob, 30, 120);
        $add('break', null, 150, 30);
        $add('travel', $ownerJob, 180, 60);
        $add('work', $ownerJob, 240, 120);

        $d = app(Efficiency::class)->day($tech->fresh('location'), $day);
        $this->assertSame(480, $d['shift']);
        $this->assertSame(240, $d['work']);
        $this->assertSame(30, $d['travel_axiom']);
        $this->assertSame(60, $d['travel_other']);
        $this->assertSame(120, $d['idle']);
        $this->assertSame(round(270 / 480, 3), $d['utilization']);

        $this->actingAs($this->staff('admin'))->get('/admin/efficiency?week=2026-11-09')->assertOk()->assertSee('Mike Plumber')->assertSee('56%');
    }

    public function test_route_optimizer_orders_stops_by_drive_from_the_office(): void
    {
        $this->idaho->update(['office_address' => '477 Taft Ave, Pocatello, ID', 'office_lat' => 42.8713, 'office_lng' => -112.4455]);
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $place = fn ($n, $lat, $lng) => Property::create(['location_id' => $this->idaho->id, 'rm_property_id' => 500 + $n, 'name' => "P$n",
            'address' => "$n Main", 'city' => 'Pocatello', 'state' => 'ID', 'lat' => $lat, 'lng' => $lng]);
        $far = $place(1, 43.19, -112.345);   // Blackfoot
        $near = $place(2, 42.875, -112.445); // a few blocks away
        $mid = $place(3, 42.92, -112.466);   // Chubbuck
        $tomorrow = CarbonImmutable::parse('2026-11-10', 'America/Boise');
        $farJob = $this->job($tech, ['property_id' => $far->id, 'scheduled_start' => $tomorrow->setTime(8, 0)->utc(), 'scheduled_end' => $tomorrow->setTime(10, 0)->utc()]);
        $nearJob = $this->job($tech, ['property_id' => $near->id, 'scheduled_start' => $tomorrow->setTime(10, 0)->utc(), 'scheduled_end' => $tomorrow->setTime(12, 0)->utc()]);
        $midJob = $this->job($tech, ['property_id' => $mid->id, 'scheduled_start' => $tomorrow->setTime(13, 0)->utc(), 'scheduled_end' => $tomorrow->setTime(15, 0)->utc()]);

        $r = app(RouteOptimizer::class)->optimizeDay($tech->fresh('location'), $tomorrow, notify: true);

        $order = WorkOrder::whereIn('id', [$farJob->id, $nearJob->id, $midJob->id])->orderBy('scheduled_start')->pluck('id')->all();
        $this->assertSame($nearJob->id, $order[0], 'closest stop to the office goes first');
        $this->assertSame(1, $nearJob->fresh()->route_position);
        $this->assertLessThanOrEqual($r['before'], $r['after']);
        $this->assertGreaterThan(0, $r['moved']);
        $this->assertStringContainsString('arrival window is now', end($this->sms->sent)['body']);

        // The office version only suggests moves for visits 24+ hours away, and nothing changes until approved.
        $this->actingAs($this->staff('scheduler'))->post('/schedule/propose', ['kind' => 'move', 'location' => $this->idaho->id])
            ->assertRedirect()->assertSessionHas('status');
    }

    public function test_needs_another_visit_sends_job_back_to_be_booked(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $wo = $this->job($tech);
        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/start", self::GPS);
        $this->later(45);
        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/pause", ['notes' => 'Need a new valve'] + self::GPS)->assertRedirect('/tech');

        $wo->refresh();
        $this->assertSame(WorkOrder::STATUS_TRIAGED, $wo->status);
        $this->assertNull($wo->scheduled_start);
        $this->assertNotNull($wo->needs_review_at);
        $this->assertSame(45, TimeEntry::where('type', 'work')->first()->minutes());
        $this->assertSame(0, TimeEntry::whereNull('ended_at')->where('type', '!=', 'shift')->count());
    }

    public function test_admin_pages_render(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $wo = $this->job($tech, ['status' => WorkOrder::STATUS_COMPLETED]);
        $at = CarbonImmutable::now();
        TimeEntry::create(['technician_id' => $tech->id, 'work_order_id' => $wo->id, 'type' => 'work', 'started_at' => $at, 'ended_at' => $at->addMinutes(60)]);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get("/work-orders/{$wo->id}")->assertOk()->assertSee('Create invoice for unbilled work');
        $inv = app(Invoicer::class)->create($wo);
        $this->actingAs($admin)->get("/work-orders/{$wo->id}")->assertOk()->assertSee($inv->number)->assertSee('Everything on this job so far has been billed');
        $this->actingAs($admin)->get('/invoices')->assertOk()->assertSee($inv->number);
        $this->actingAs($admin)->get("/invoices/{$inv->id}")->assertOk()->assertSee('Bill to');
        $this->actingAs($admin)->post("/invoices/{$inv->id}/status", ['status' => 'paid'])->assertRedirect();
        $this->assertSame('paid', $inv->fresh()->status);

        $this->actingAs($admin)->get('/admin/rates')->assertOk()->assertSee('Rates &amp; fees', false);
        $plumbing = Skill::where('slug', 'plumbing')->value('id');
        $this->actingAs($admin)->post('/admin/rates', [
            'settings' => ['default_hourly_rate' => 70, 'travel_rate_percent' => 100, 'parts_markup_percent' => 15, 'minimum_billable_minutes' => 30, 'bill_travel' => 1],
            'skills' => [$plumbing => ['hourly_rate' => 99, 'service_call_fee' => 65, 'licensed' => 1]],
            'techs' => [$tech->id => ['hourly_rate' => 85, 'trades' => [$plumbing => 110]]],
        ])->assertRedirect('/admin/rates');
        $this->assertEquals(99, Skill::find($plumbing)->hourly_rate);
        $this->assertTrue((bool) Skill::find($plumbing)->licensed);
        $this->assertSame('1', Setting::get('bill_travel'));
        $this->assertSame(110.0, app(RateCard::class)->hourly($tech->fresh(), 'plumbing'));

        $this->actingAs($tech->user)->get('/admin/rates')->assertForbidden();
        $this->actingAs($tech->user)->get('/tech')->assertOk()->assertSee('Clock in');
    }
}
