<?php

namespace Tests\Feature;

use App\Models\WorkOrder;

class IntakeTest extends MaintenanceTestCase
{
    public function test_rejects_missing_or_wrong_key(): void
    {
        $this->postJson('/api/intake/requests', $this->intakePayload())->assertStatus(401);
        $this->postJson('/api/intake/requests', $this->intakePayload(), ['X-Axiom-Key' => 'nope'])->assertStatus(401);
    }

    public function test_validates_fields(): void
    {
        $this->postJson('/api/intake/requests', ['description' => 'short'], ['X-Axiom-Key' => 'test-key'])
            ->assertStatus(422)->assertJsonValidationErrors(['tenant_name', 'phone', 'category', 'description']);
    }

    public function test_request_is_triaged_and_tenant_gets_time_options(): void
    {
        $this->tech('Mike Plumber', ['plumbing' => 3]);

        $res = $this->postJson('/api/intake/requests', $this->intakePayload(), ['X-Axiom-Key' => 'test-key'])->assertCreated();

        $wo = WorkOrder::where('reference', $res->json('reference'))->first();
        $this->assertSame('plumbing', $wo->trade);
        $this->assertSame($this->tenant->id, $wo->tenant_id, 'matched to the Rent Manager tenant by phone');
        $this->assertSame(WorkOrder::STATUS_OFFERED, $wo->status);
        $this->assertCount(3, $wo->offers);
        $this->assertNull($wo->needs_review_at);

        $bodies = array_column($this->sms->sent, 'body');
        $this->assertStringContainsString('we got your request', $bodies[0]);
        $this->assertStringContainsString('Reply with the number', $bodies[1]);
        $this->assertSame('+12085551234', $this->sms->sent[1]['to']);
    }

    public function test_permission_to_enter_routine_job_is_booked_without_asking(): void
    {
        $tech = $this->tech('Mike Plumber', ['plumbing' => 3]);

        $res = $this->postJson('/api/intake/requests', $this->intakePayload(['permission_to_enter' => true]), ['X-Axiom-Key' => 'test-key'])->assertCreated();

        $wo = WorkOrder::where('reference', $res->json('reference'))->first();
        $this->assertSame(WorkOrder::STATUS_SCHEDULED, $wo->status);
        $this->assertSame($tech->id, $wo->technician_id);
        $this->assertTrue($wo->scheduled_start->isAfter(now()->endOfDay()), 'routine work is not booked same day');
        $this->assertStringContainsString("you're booked", end($this->sms->sent)['body']);
    }

    public function test_unknown_tenant_still_accepted_but_flagged(): void
    {
        $this->tech('Mike Plumber', ['plumbing' => 3]);
        $res = $this->postJson('/api/intake/requests', $this->intakePayload(['phone' => '208-555-9999']), ['X-Axiom-Key' => 'test-key'])->assertCreated();

        $wo = WorkOrder::where('reference', $res->json('reference'))->first();
        $this->assertNull($wo->tenant_id);
        $this->assertNotNull($wo->needs_review_at);
    }

    public function test_emergency_goes_to_on_call_tech_immediately(): void
    {
        $this->tech('Day Tech', ['plumbing' => 3]);
        $onCall = $this->tech('Night Tech', ['plumbing' => 2], ['on_call' => true]);

        $res = $this->postJson('/api/intake/requests', $this->intakePayload([
            'description' => 'Pipe burst under the bathroom sink, water pouring onto the floor!',
        ]), ['X-Axiom-Key' => 'test-key'])->assertCreated();

        $wo = WorkOrder::where('reference', $res->json('reference'))->first();
        $this->assertSame('emergency', $wo->urgency);
        $this->assertSame($onCall->id, $wo->technician_id);
        $this->assertSame(WorkOrder::STATUS_SCHEDULED, $wo->status);
        $this->assertStringContainsString('Emergency dispatched', $wo->review_reason);
        $tenantText = collect($this->sms->sent)->firstWhere('to', '+12085551234');
        $this->assertStringContainsString('shutoff', $tenantText['body']);
        $this->assertTrue(collect($this->sms->sent)->contains(fn ($m) => $m['to'] === $onCall->user->phone && str_contains($m['body'], 'EMERGENCY')));
    }

    public function test_no_qualified_tech_goes_to_queue(): void
    {
        $res = $this->postJson('/api/intake/requests', $this->intakePayload(), ['X-Axiom-Key' => 'test-key'])->assertCreated();
        $wo = WorkOrder::where('reference', $res->json('reference'))->first();
        $this->assertStringContainsString('No open slot', $wo->review_reason);
    }

    public function test_status_page_is_public_by_token(): void
    {
        $this->tech('Mike Plumber', ['plumbing' => 3]);
        $res = $this->postJson('/api/intake/requests', $this->intakePayload(), ['X-Axiom-Key' => 'test-key']);
        $this->get(parse_url($res->json('status_url'), PHP_URL_PATH))->assertOk()->assertSee($res->json('reference'));
        $this->get('/s/not-a-token')->assertNotFound();
    }
}
