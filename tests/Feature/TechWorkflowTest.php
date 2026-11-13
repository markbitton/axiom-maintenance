<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\StockLevel;
use App\Models\WorkOrder;
use App\Services\Parts\PartSuggester;

class TechWorkflowTest extends MaintenanceTestCase
{
    public function test_on_my_way_texts_eta_and_complete_records_parts_and_stock(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);
        $wax = Part::where('sku', 'PLB-WAX')->first();
        StockLevel::create(['stock_location_id' => $tech->truck->id, 'part_id' => $wax->id, 'quantity' => 4]);

        $wo = WorkOrder::create(['location_id' => $this->idaho->id, 'property_id' => $this->property->id, 'tenant_id' => $this->tenant->id,
            'description' => 'Toilet rocks and leaks at the base', 'trade' => 'plumbing', 'status' => WorkOrder::STATUS_SCHEDULED,
            'technician_id' => $tech->id, 'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2)]);
        $line = $wo->parts()->create(['part_id' => $wax->id, 'part_name' => $wax->name, 'quantity' => 1, 'suggested' => true]);

        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/on-my-way")->assertSessionHasErrors('lat');
        $this->assertSame(WorkOrder::STATUS_SCHEDULED, $wo->fresh()->status, 'no GPS, no clock');
        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/on-my-way", ['lat' => 43.6, 'lng' => -116.2])->assertRedirect();
        $this->assertSame(WorkOrder::STATUS_EN_ROUTE, $wo->fresh()->status);
        $this->assertMatchesRegularExpression('/Mike is on the way, about \d+ minutes/', end($this->sms->sent)['body']);
        $this->assertStringNotContainsString((string) $tech->user->phone, end($this->sms->sent)['body']);

        $this->actingAs($tech->user)->post("/tech/jobs/{$wo->id}/complete", [
            'notes' => 'Replaced wax ring and reset toilet', 'used' => [$line->id => 1], 'labor_minutes' => 50,
        ])->assertRedirect('/tech');

        $wo->refresh();
        $this->assertSame(WorkOrder::STATUS_COMPLETED, $wo->status);
        $this->assertTrue($line->fresh()->used);
        $this->assertSame(3, StockLevel::where('part_id', $wax->id)->value('quantity'));
        $this->assertStringContainsString('Reply 1 to 5', end($this->sms->sent)['body']);

        $this->post('/webhooks/sms', ['From' => '+12085551234', 'Body' => '2'])->assertOk();
        $this->assertSame(2, $wo->fresh()->tenant_rating);
        $this->assertStringContainsString('Low rating', $wo->fresh()->review_reason);
    }

    public function test_parts_history_drives_suggestions_after_enough_jobs(): void
    {
        $fill = Part::where('sku', 'PLB-FILL')->first();
        for ($i = 0; $i < 6; $i++) {
            $old = WorkOrder::create(['location_id' => $this->idaho->id, 'description' => 'Toilet keeps running', 'trade' => 'plumbing',
                'status' => WorkOrder::STATUS_CLOSED, 'completed_at' => now()->subDays($i + 1)]);
            $old->parts()->create(['part_id' => $fill->id, 'part_name' => $fill->name, 'quantity' => 1, 'used' => true]);
        }
        $wo = WorkOrder::create(['location_id' => $this->idaho->id, 'description' => 'My toilet is running constantly', 'trade' => 'plumbing']);

        $rows = app(PartSuggester::class)->suggest($wo);

        $this->assertSame('Toilet fill valve', $rows[0]->part_name);
        $this->assertEqualsWithDelta(1.0, $rows[0]->suggestion_confidence, 0.01);
    }
}
