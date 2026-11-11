<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Scheduling\SlotFinder;
use Carbon\CarbonImmutable;

class SchedulingTest extends MaintenanceTestCase
{
    private function order(array $attrs = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'location_id' => $this->idaho->id, 'property_id' => $this->property->id, 'unit_id' => $this->unit->id,
            'tenant_id' => $this->tenant->id, 'description' => 'Leaky faucet', 'trade' => 'plumbing',
            'urgency' => 'routine', 'estimated_minutes' => 60, 'status' => WorkOrder::STATUS_TRIAGED,
        ], $attrs));
    }

    public function test_prefers_the_more_skilled_tech(): void
    {
        $this->tech('Generalist', ['plumbing' => 1, 'general' => 3]);
        $expert = $this->tech('Expert', ['plumbing' => 3]);

        $slots = app(SlotFinder::class)->find($this->order(), 3);

        $this->assertNotEmpty($slots);
        $best = collect($slots)->sortByDesc('score')->first();
        $this->assertSame($expert->id, $best->technician->id);
    }

    public function test_only_techs_with_the_skill_are_used(): void
    {
        $this->tech('Electrician', ['electrical' => 3]);
        $this->assertSame([], app(SlotFinder::class)->find($this->order(), 3));
    }

    public function test_never_double_books_and_respects_work_hours(): void
    {
        $tech = $this->tech('Only Tech', ['plumbing' => 3]);
        $tz = 'America/Boise';
        $tomorrow9 = CarbonImmutable::parse('2026-11-10 09:00', $tz);
        $this->order(['technician_id' => $tech->id, 'scheduled_start' => $tomorrow9->utc(), 'scheduled_end' => $tomorrow9->addHours(2)->utc(), 'status' => WorkOrder::STATUS_SCHEDULED]);

        $slots = app(SlotFinder::class)->find($this->order(), 10);

        foreach ($slots as $s) {
            $local = $s->start->setTimezone($tz);
            $this->assertFalse($local->between($tomorrow9->subHours(2), $tomorrow9->addHours(2)), 'overlaps the booked 9am job: '.$local);
            $this->assertGreaterThanOrEqual(8, $local->hour);
            $this->assertLessThan(17, $local->hour);
            $this->assertTrue($local->isWeekday());
        }
    }

    public function test_urgent_can_be_booked_today_but_routine_cannot(): void
    {
        $this->tech('Only Tech', ['plumbing' => 3]);
        $finder = app(SlotFinder::class);

        $routine = $finder->find($this->order(), 3);
        $this->assertTrue(collect($routine)->every(fn ($s) => ! $s->start->isSameDay(now())));

        $urgent = $finder->find($this->order(['urgency' => 'urgent']), 3);
        $this->assertTrue($urgent[0]->start->isSameDay(now()));
    }

    public function test_offered_slots_are_held_for_other_requests(): void
    {
        $this->tech('Only Tech', ['plumbing' => 3]);
        $finder = app(SlotFinder::class);
        $first = $this->order();
        $slots = $finder->find($first, 3);
        app(AutoScheduler::class)->offer($first, $slots, 'America/Boise');

        $second = $finder->find($this->order(), 3);
        foreach ($second as $s) {
            foreach ($slots as $held) {
                $this->assertFalse($s->start->equalTo($held->start), 'offered a time already held for another tenant');
            }
        }
    }

    public function test_taken_slot_triggers_fresh_offer(): void
    {
        $tech = $this->tech('Only Tech', ['plumbing' => 3]);
        $wo = $this->order();
        $scheduler = app(AutoScheduler::class);
        $scheduler->offer($wo, app(SlotFinder::class)->find($wo, 3), 'America/Boise');
        $offer = $wo->offers()->where('option_number', 1)->first();

        // someone books that time first
        $this->order(['technician_id' => $tech->id, 'scheduled_start' => $offer->starts_at, 'scheduled_end' => $offer->ends_at, 'status' => WorkOrder::STATUS_SCHEDULED]);

        $this->assertFalse($scheduler->accept($wo->fresh(), 1));
        $this->assertSame(WorkOrder::STATUS_OFFERED, $wo->fresh()->status);
        $this->assertStringContainsString('just taken', $this->sms->sent[count($this->sms->sent) - 2]['body']);
    }
}
