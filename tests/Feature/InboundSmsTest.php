<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Services\Sms\Messenger;

class InboundSmsTest extends MaintenanceTestCase
{
    private function offered(): WorkOrder
    {
        $this->tech('Mike Plumber', ['plumbing' => 3]);
        $ref = $this->postJson('/api/intake/requests', $this->intakePayload(), ['X-Axiom-Key' => 'test-key'])->json('reference');

        return WorkOrder::where('reference', $ref)->first();
    }

    public function test_reply_with_number_books_that_time(): void
    {
        $wo = $this->offered();
        $second = $wo->offers()->where('option_number', 2)->first();

        $this->post('/webhooks/sms', ['From' => '+12085551234', 'Body' => ' 2 ', 'MessageSid' => 'SM1'])->assertOk();

        $wo->refresh();
        $this->assertSame(WorkOrder::STATUS_SCHEDULED, $wo->status);
        $this->assertTrue($wo->scheduled_start->equalTo($second->starts_at));
        $this->assertSame(0, $wo->offers()->open()->count());
        $this->assertStringContainsString("you're booked", end($this->sms->sent)['body']);
    }

    public function test_zero_means_none_work_and_flags(): void
    {
        $wo = $this->offered();
        $this->post('/webhooks/sms', ['From' => '+12085551234', 'Body' => '0'])->assertOk()->assertSee('office will reach out', false);
        $this->assertStringContainsString('none of the offered times', $wo->fresh()->review_reason);
    }

    public function test_stop_opts_out_and_later_texts_are_skipped(): void
    {
        $wo = $this->offered();
        $this->post('/webhooks/sms', ['From' => '+12085551234', 'Body' => 'STOP'])->assertOk();
        $this->assertNotNull($this->tenant->fresh()->sms_opted_out_at);

        $count = count($this->sms->sent);
        app(Messenger::class)->toTenant($wo->fresh(), 'hello');
        $this->assertCount($count, $this->sms->sent);
    }

    public function test_free_text_is_saved_to_thread_and_flagged_before_booking(): void
    {
        $wo = $this->offered();
        $this->post('/webhooks/sms', ['From' => '+12085551234', 'Body' => 'Can someone come Saturday?'])->assertOk();
        $this->assertTrue($wo->messages()->where('direction', 'inbound')->where('body', 'Can someone come Saturday?')->exists());
        $this->assertSame('Tenant sent a message', $wo->fresh()->review_reason);
    }

    public function test_twilio_signature_required_when_live(): void
    {
        config(['axiom.sms.driver' => 'twilio', 'axiom.sms.twilio.auth_token' => 'tok', 'axiom.sms.twilio.validate_signature' => true]);
        $this->post('/webhooks/sms', ['From' => '+12085551234', 'Body' => 'hi'])->assertForbidden();

        $params = ['Body' => 'hi', 'From' => '+12085551234'];
        $sig = base64_encode(hash_hmac('sha1', url('/webhooks/sms').'Bodyhi'.'From+12085551234', 'tok', true));
        $this->post('/webhooks/sms', $params, ['X-Twilio-Signature' => $sig])->assertOk();
    }
}
