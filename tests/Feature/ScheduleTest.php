<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\LocationPing;
use App\Models\Technician;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Billing\Invoicer;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoWorkOrdersSeeder;

class ScheduleTest extends MaintenanceTestCase
{
    private User $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->seed(DemoWorkOrdersSeeder::class);
        $this->scheduler = User::where('email', 'scheduler@example.test')->firstOrFail();
    }

    public function test_demo_data_covers_every_part_of_the_flow(): void
    {
        $this->assertGreaterThan(40, WorkOrder::count());
        foreach ([WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_CLOSED, WorkOrder::STATUS_OFFERED, WorkOrder::STATUS_TRIAGED] as $status) {
            $this->assertTrue(WorkOrder::where('status', $status)->exists(), "no {$status} jobs");
        }
        $this->assertGreaterThanOrEqual(5, WorkOrder::needsReview()->count());
        $this->assertGreaterThan(20, Activity::where('event', 'auto_booked')->count());

        // Clocks, GPS, invoices and a job billed in two parts.
        $this->assertNotNull(Location::where('rm_location_id', 1)->first()->office());
        foreach (['shift', 'travel', 'work', 'break'] as $type) {
            $this->assertTrue(TimeEntry::where('type', $type)->exists(), "no {$type} time");
        }
        $this->assertGreaterThan(100, LocationPing::count());
        $this->assertTrue(Invoice::where('status', 'paid')->exists());
        $twoVisits = WorkOrder::whereHas('invoices')->whereHas('timeEntries', fn ($q) => $q->where('type', 'work')->whereNull('invoice_id'))->first();
        $this->assertNotNull($twoVisits, 'a job with one visit billed and one not');
        $this->assertNotEmpty(app(Invoicer::class)->preview($twoVisits));

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->actingAs($admin)->get('/admin/efficiency')->assertOk()->assertSee('Mike Plumber');
        $this->actingAs($admin)->get("/work-orders/{$twoVisits->id}")->assertOk()->assertSee('Create invoice for unbilled work');
        $this->actingAs($admin)->get('/map')->assertOk()->assertSee('477 Taft Ave');
    }

    public function test_calendar_and_map_pages_render(): void
    {
        $job = WorkOrder::where('status', WorkOrder::STATUS_SCHEDULED)->orderBy('scheduled_start')->firstOrFail();
        $date = $job->scheduled_start->setTimezone('America/Boise')->toDateString();

        $this->actingAs($this->scheduler)->get("/schedule?date={$date}")->assertOk()->assertSee($job->property->name)->assertSee('Mike Plumber');
        $this->actingAs($this->scheduler)->get("/schedule?view=week&date={$date}")->assertOk()->assertSee('Week of');
        $this->actingAs($this->scheduler)->get("/map?date={$date}")->assertOk()->assertSee('leaflet', false)->assertSee($job->reference);
    }

    public function test_dragging_a_job_moves_it_and_texts_the_tenant(): void
    {
        $job = WorkOrder::where('status', WorkOrder::STATUS_SCHEDULED)->where('scheduled_start', '>', now())->firstOrFail();
        $other = Technician::where('id', '!=', $job->technician_id)->firstOrFail();

        $this->actingAs($this->scheduler)->postJson('/schedule/move', [
            'work_order_id' => $job->id, 'technician_id' => $other->id, 'start' => '2026-11-13 14:15',
        ])->assertOk()->assertJson(['ok' => true]);

        $job->refresh();
        $this->assertSame($other->id, $job->technician_id);
        $this->assertSame('2026-11-13 14:15', $job->scheduled_start->setTimezone('America/Boise')->format('Y-m-d H:i'));
        $this->assertSame(120, (int) $job->scheduled_start->diffInMinutes($job->scheduled_end));
        $this->assertNotEmpty($this->sms->sent);
    }

    public function test_dragging_an_unbooked_request_books_it(): void
    {
        $job = WorkOrder::needsReview()->firstOrFail();
        $tech = Technician::firstOrFail();

        $this->actingAs($this->scheduler)->postJson('/schedule/move', [
            'work_order_id' => $job->id, 'technician_id' => $tech->id, 'start' => '2026-11-12 09:00',
        ])->assertOk();

        $this->assertSame(WorkOrder::STATUS_SCHEDULED, $job->fresh()->status);
        $this->assertNull($job->fresh()->needs_review_at);
    }

    public function test_finished_jobs_cannot_be_moved_and_techs_cannot_see_the_calendar(): void
    {
        $done = WorkOrder::where('status', WorkOrder::STATUS_CLOSED)->firstOrFail();
        $this->actingAs($this->scheduler)->postJson('/schedule/move', [
            'work_order_id' => $done->id, 'technician_id' => $done->technician_id, 'start' => '2026-11-13 09:00',
        ])->assertStatus(422);

        $tech = User::where('email', 'mike@example.test')->firstOrFail();
        $this->actingAs($tech)->get('/schedule')->assertForbidden();
    }

    public function test_admin_can_reset_demo_data_only_on_beta(): void
    {
        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->actingAs($admin)->post('/admin/demo/reset')->assertNotFound();

        config(['axiom.demo_data' => true]);
        $before = WorkOrder::max('id');
        $this->actingAs($admin)->post('/admin/demo/reset')->assertRedirect();
        $this->assertGreaterThan(40, WorkOrder::count());
        $this->assertGreaterThan($before, WorkOrder::min('id'));
    }

    public function test_calendar_falls_back_to_a_location_with_techs_and_can_switch(): void
    {
        $utah = Location::where('rm_location_id', 9)->firstOrFail();
        $this->scheduler->update(['location_id' => $utah->id]);
        $job = WorkOrder::where('status', WorkOrder::STATUS_SCHEDULED)->firstOrFail();
        $date = $job->scheduled_start->setTimezone('America/Boise')->toDateString();

        $this->actingAs($this->scheduler)->get("/schedule?date={$date}")->assertOk()->assertSee('Mike Plumber');
        $this->actingAs($this->scheduler)->get("/schedule?date={$date}&location={$utah->id}")->assertOk()->assertDontSee('Mike Plumber');
        $this->actingAs($this->scheduler)->get("/map?date={$date}")->assertOk()->assertDontSee('Mike Plumber');
    }

    public function test_find_best_time_suggests_skilled_techs_with_reasons(): void
    {
        $job = WorkOrder::create(['location_id' => $this->idaho->id, 'property_id' => $this->property->id, 'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id, 'description' => 'Toilet runs all night', 'trade' => 'plumbing', 'estimated_minutes' => 45,
            'status' => WorkOrder::STATUS_TRIAGED]);

        $res = $this->actingAs($this->scheduler)->getJson("/work-orders/{$job->id}/suggest")->assertOk()
            ->assertJsonPath('job.ref', $job->reference)->assertJsonPath('job.minutes', 45)->json();

        $this->assertNotEmpty($res['slots']);
        $this->assertTrue($res['slots'][0]['best']);
        $this->assertGreaterThan(0, Technician::find($res['slots'][0]['technician_id'])->skillLevel('plumbing'), 'best fit has plumbing skills');
        $this->assertStringContainsString('Plumbing', implode(' ', $res['slots'][0]['reasons']));
        $this->assertStringContainsString('room for the whole 45 min job', implode(' ', $res['slots'][0]['reasons']));

        // Only techs with plumbing skills, at most two openings each, and each opening fits the whole job.
        $plumbers = Technician::all()->filter(fn ($t) => $t->skillLevel('plumbing') > 0)->pluck('id');
        $this->assertEmpty(collect($res['slots'])->pluck('technician_id')->diff($plumbers));
        $this->assertTrue(collect($res['slots'])->countBy('technician_id')->every(fn ($n) => $n <= 2));
        $this->assertGreaterThan(1, collect($res['slots'])->pluck('technician_id')->unique()->count(), 'offers more than one tech');
        $this->assertSame($plumbers->count(), $res['qualified']);
    }

    public function test_office_can_enter_a_work_order_and_have_it_booked_automatically(): void
    {
        $this->actingAs($this->scheduler)->get('/new-work-order')->assertOk()->assertSee('Maple Court');

        $this->actingAs($this->scheduler)->post('/new-work-order', [
            'location_id' => $this->idaho->id, 'unit_id' => $this->unit->id, 'description' => 'Kitchen faucet is dripping nonstop.',
            'permission_to_enter' => 1, 'then' => 'auto',
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();
        $this->assertSame('manual', $wo->source);
        $this->assertSame('plumbing', $wo->trade);
        $this->assertSame(WorkOrder::STATUS_SCHEDULED, $wo->status);
        $this->assertSame($this->tenant->id, $wo->tenant_id);
    }

    public function test_office_can_enter_a_work_order_and_pick_the_time(): void
    {
        $this->actingAs($this->scheduler)->post('/new-work-order', [
            'location_id' => $this->idaho->id, 'address_text' => '9 Elm St, Boise', 'contact_name' => 'Walk In',
            'description' => 'Bedroom window will not lock.', 'trade' => 'locks', 'estimated_minutes' => 30, 'then' => 'place',
        ])->assertRedirect();

        $wo = WorkOrder::latest('id')->firstOrFail();
        $this->assertSame(['locks', 30, WorkOrder::STATUS_TRIAGED], [$wo->trade, $wo->estimated_minutes, $wo->status]);
        $this->actingAs($this->scheduler)->get("/schedule?find={$wo->id}")->assertOk()->assertSee($wo->reference);
    }
}
