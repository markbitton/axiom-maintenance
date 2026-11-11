<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Sms\InboundHandler;
use App\Services\Sms\SmsGateway;
use App\Support\Phone;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function sms(Request $request, InboundHandler $handler)
    {
        $reply = $handler->handle((string) $request->input('From'), (string) $request->input('Body'), $request->input('MessageSid'));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Response>'
            .($reply ? '<Message>'.htmlspecialchars($reply, ENT_XML1).'</Message>' : '')
            .'</Response>';

        return response($xml, 200, ['Content-Type' => 'text/xml']);
    }

    public function smsStatus(Request $request)
    {
        if ($sid = $request->input('MessageSid')) {
            Message::where('provider_sid', $sid)->update(['status' => (string) $request->input('MessageStatus')]);
        }

        return response()->noContent();
    }

    /** A call to the Axiom number rings the tech on that tenant's job today, else the office. */
    public function voice(Request $request, SmsGateway $gateway)
    {
        $from = Phone::normalize((string) $request->input('From'));
        $tenantIds = $from ? Tenant::where('phone', $from)->pluck('id') : collect();
        $wo = WorkOrder::with('technician.user')
            ->where(fn ($q) => $q->whereIn('tenant_id', $tenantIds)->orWhere('contact_phone', $from))
            ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_EN_ROUTE, WorkOrder::STATUS_IN_PROGRESS])
            ->whereDate('scheduled_start', now()->toDateString())
            ->latest('scheduled_start')
            ->first();

        $techPhone = $wo?->technician?->user?->phone;
        $to = $techPhone ?: config('axiom.sms.office_phone');
        if (! $to) {
            return response('<?xml version="1.0" encoding="UTF-8"?><Response><Say>Thanks for calling Axiom Maintenance. Please text this number or leave a message.</Say><Record maxLength="120"/></Response>', 200, ['Content-Type' => 'text/xml']);
        }
        $whisper = $techPhone ? null : 'Thanks for calling Axiom Maintenance. Connecting you now.';

        return response($gateway->forwardCallXml($to, $whisper), 200, ['Content-Type' => 'text/xml']);
    }
}
