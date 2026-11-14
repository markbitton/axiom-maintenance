<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\WorkOrder;
use App\Services\Parts\ToolKit;
use App\Services\Scheduling\Planner;
use Carbon\CarbonImmutable;

class PlannerTest extends MaintenanceTestCase
{
    private function unbooked(string $trade, array $attrs = []): WorkOrder
    {
        return WorkOrder::create($attrs + ['location_id' => $this->idaho->id, 'property_id' => $this->property->id, 'tenant_id' => $this->tenant->id,
            'description' => 'Something to fix', 'trade' => $trade, 'estimated_minutes' => 60, 'status' => WorkOrder::STATUS_TRIAGED]);
    }

    public function test_bulk_suggestions_wait_for_approval_then_book_and_text(): void
    {
        $mike = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $this->tech('Dana Sparks', ['electrical' => 3]);
        $jobs = collect([$this->unbooked('plumbing'), $this->unbooked('plumbing'), $this->unbooked('electrical')]);
        $scheduler = $this->staff('scheduler');

        $this->actingAs($scheduler)->post('/schedule/propose', ['kind' => 'place', 'location' => $this->idaho->id])->assertRedirect();
        $jobs = $jobs->map->fresh();
        $this->assertTrue($jobs->every(fn ($j) => $j->proposed_start && $j->proposed_kind === 'place'));
        $this->assertSame($mike->id, $jobs[0]->proposed_technician_id, 'plumbing goes to the plumber');
        $this->assertNotEquals($jobs[0]->proposed_start, $jobs[1]->proposed_start, 'suggestions do not collide');
        $this->assertTrue($jobs->every(fn ($j) => $j->scheduled_start === null), 'nothing booked yet');
        $this->assertEmpty($this->sms->sent, 'no texts before approval');

        $this->actingAs($scheduler)->get('/schedule?date='.$jobs[0]->proposed_start->setTimezone('America/Boise')->toDateString())
            ->assertOk()->assertSee('Waiting for your OK')->assertSee('Approve checked');

        $this->actingAs($scheduler)->post('/schedule/approve', ['ids' => [$jobs[0]->id, $jobs[2]->id], 'action' => 'approve'])->assertRedirect();
        $this->assertSame(WorkOrder::STATUS_SCHEDULED, $jobs[0]->fresh()->status);
        $this->assertEquals($jobs[0]->proposed_start, $jobs[0]->fresh()->scheduled_start);
        $this->assertNull($jobs[0]->fresh()->proposed_start);
        $this->assertCount(2, $this->sms->sent);
        $this->assertNotNull($jobs[1]->fresh()->proposed_start, 'unticked suggestion stays');

        $this->actingAs($scheduler)->post('/schedule/approve', ['ids' => [$jobs[1]->id], 'action' => 'dismiss'])->assertRedirect();
        $this->assertNull($jobs[1]->fresh()->proposed_start);
    }

    public function test_drive_time_moves_need_24_hours_notice_and_approval(): void
    {
        $this->idaho->update(['office_lat' => 42.8713, 'office_lng' => -112.4455]);
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $place = fn ($n, $lat, $lng) => Property::create(['location_id' => $this->idaho->id, 'rm_property_id' => 600 + $n, 'name' => "P$n",
            'address' => "$n Main", 'city' => 'Pocatello', 'state' => 'ID', 'lat' => $lat, 'lng' => $lng]);
        $book = fn ($p, CarbonImmutable $at) => WorkOrder::create(['location_id' => $this->idaho->id, 'property_id' => $p->id, 'tenant_id' => $this->tenant->id,
            'description' => 'x', 'trade' => 'plumbing', 'estimated_minutes' => 60, 'status' => WorkOrder::STATUS_SCHEDULED, 'technician_id' => $tech->id,
            'scheduled_start' => $at->utc(), 'scheduled_end' => $at->addHours(2)->utc()]);
        $far = $place(1, 43.19, -112.345);
        $near = $place(2, 42.875, -112.445);
        $mid = $place(3, 42.92, -112.466);

        // Tomorrow (less than 24 hours away): left alone even though the order is bad.
        $tomorrow = CarbonImmutable::parse('2026-11-10', 'America/Boise');
        $t1 = $book($far, $tomorrow->setTime(8, 0));
        $book($near, $tomorrow->setTime(10, 0));
        $book($far, $tomorrow->setTime(13, 0));
        $book($near, $tomorrow->setTime(15, 0));
        // Two days out: zig-zag far / near / far / near.
        $day = CarbonImmutable::parse('2026-11-11', 'America/Boise');
        $a = $book($far, $day->setTime(8, 0));
        $b = $book($near, $day->setTime(10, 0));
        $c = $book($mid, $day->setTime(13, 0));
        $d = $book($near, $day->setTime(15, 0));

        $r = app(Planner::class)->proposeMoves($this->idaho->fresh());
        $this->assertGreaterThan(0, $r['moves']);
        $this->assertNull($t1->fresh()->proposed_start, 'nothing inside 24 hours');
        $moved = WorkOrder::where('proposed_kind', 'move')->get();
        $this->assertTrue($moved->every(fn ($w) => $w->proposed_start->gte(now()->addDay())));
        $this->assertEquals($day->setTime(8, 0)->utc(), $a->fresh()->scheduled_start, 'not moved until approved');
        $this->assertEmpty($this->sms->sent);

        $this->actingAs($this->staff('scheduler'))->post('/schedule/approve', ['ids' => $moved->pluck('id')->all(), 'action' => 'approve'])->assertRedirect();
        $this->assertSame($b->id, WorkOrder::whereIn('id', [$a->id, $b->id, $c->id, $d->id])->orderBy('scheduled_start')->value('id'), 'nearest stop first now');
        $this->assertCount($moved->count(), $this->sms->sent);
        $this->assertStringContainsString('arrival window is now', end($this->sms->sent)['body']);
    }

    public function test_work_order_lists_supplies_and_tools(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $wo = $this->unbooked('plumbing', ['description' => 'Toilet keeps running after flushing', 'technician_id' => $tech->id]);
        $kit = app(ToolKit::class)->forWorkOrder($wo);
        $names = array_column($kit['supplies'], 'name');
        $this->assertContains('Toilet flapper', $names);
        $this->assertContains('Toilet fill valve', $names);
        $this->assertContains('Channel-lock pliers', $kit['tools']);
        $this->assertFalse($kit['supplies'][0]['on_truck'], 'empty truck');

        $this->actingAs($this->staff('scheduler'))->get("/work-orders/{$wo->id}")->assertOk()->assertSee('Supplies &amp; tools to bring', false)->assertSee('Toilet flapper');
        $this->actingAs($tech->user)->get("/tech/jobs/{$wo->id}")->assertOk()->assertSee('Toilet flapper')->assertSee('Channel-lock pliers');
    }
}
