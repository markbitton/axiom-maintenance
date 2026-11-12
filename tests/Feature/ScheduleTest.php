<?php

namespace Tests\Feature;

use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
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
        $this->assertSame(5, WorkOrder::needsReview()->count());
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
}
