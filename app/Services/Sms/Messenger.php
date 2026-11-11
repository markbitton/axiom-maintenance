<?php

namespace App\Services\Sms;

use App\Models\Message;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends texts to tenants about a work order and records every message in the job's thread. */
class Messenger
{
    public function __construct(private SmsGateway $gateway) {}

    public function toTenant(WorkOrder $wo, string $body, ?User $sender = null): ?Message
    {
        $wo->loadMissing('tenant');
        $to = $wo->tenantPhone();
        if (! $to) {
            $wo->log('sms_skipped', ['reason' => 'no phone']);

            return null;
        }
        if ($wo->tenant && ($wo->tenant->sms_opted_out_at || ! $wo->tenant->sms_consent)) {
            $wo->log('sms_skipped', ['reason' => 'no consent or opted out']);

            return null;
        }

        return $this->send($wo, $to, $body, $sender);
    }

    public function toNumber(string $to, string $body, ?WorkOrder $wo = null): ?Message
    {
        return $this->send($wo, $to, $body, null);
    }

    private function send(?WorkOrder $wo, string $to, string $body, ?User $sender): Message
    {
        $message = Message::create([
            'work_order_id' => $wo?->id,
            'tenant_id' => $wo?->tenant_id,
            'user_id' => $sender?->id,
            'direction' => 'outbound',
            'channel' => 'sms',
            'from_number' => config('axiom.sms.from'),
            'to_number' => $to,
            'body' => $body,
            'status' => 'queued',
        ]);

        try {
            $sid = $this->gateway->send($to, $body);
            $message->update(['provider_sid' => $sid, 'status' => 'sent']);
        } catch (Throwable $e) {
            Log::error('SMS send failed', ['to' => $to, 'error' => $e->getMessage()]);
            $message->update(['status' => 'failed']);
        }

        return $message;
    }
}
