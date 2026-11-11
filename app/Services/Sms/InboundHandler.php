<?php

namespace App\Services\Sms;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Scheduling\Texts;
use App\Support\Phone;

/**
 * Handles a text sent to the Axiom number. Replies 1-3 book a time, 0 means none work,
 * a 1-5 after completion is a rating, STOP/START manage consent, anything else lands in the job's thread.
 */
class InboundHandler
{
    public function __construct(private AutoScheduler $scheduler, private Messenger $messenger) {}

    public function handle(string $from, string $body, ?string $sid = null): ?string
    {
        $from = Phone::normalize($from) ?? $from;
        $text = trim($body);
        $upper = strtoupper($text);

        $tenants = Tenant::where('phone', $from)->get();
        $wo = WorkOrder::with('location', 'tenant')
            ->where(fn ($q) => $q->whereIn('tenant_id', $tenants->pluck('id'))->orWhere('contact_phone', $from))
            ->latest('updated_at')
            ->first();

        Message::create([
            'work_order_id' => $wo?->id,
            'tenant_id' => $wo?->tenant_id ?? $tenants->first()?->id,
            'direction' => 'inbound',
            'channel' => 'sms',
            'from_number' => $from,
            'to_number' => config('axiom.sms.from'),
            'body' => $text,
            'provider_sid' => $sid,
            'status' => 'received',
        ]);

        if (in_array($upper, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            Tenant::where('phone', $from)->update(['sms_opted_out_at' => now()]);

            return null; // carrier sends the opt-out confirmation
        }
        if (in_array($upper, ['START', 'YES', 'UNSTOP'], true)) {
            Tenant::where('phone', $from)->update(['sms_opted_out_at' => null, 'sms_consent' => true]);

            return null;
        }

        if (! $wo) {
            return 'Axiom Maintenance: we could not find an open request for this number. Please submit one at axiom-properties.com.';
        }

        if ($wo->status === WorkOrder::STATUS_OFFERED && preg_match('/^\s*([0-3])\s*\.?\s*$/', $text, $m)) {
            $choice = (int) $m[1];
            if ($choice === 0) {
                $wo->offers()->open()->update(['withdrawn_at' => now()]);
                $wo->flagForReview('Tenant said none of the offered times work');

                return Texts::noneWork();
            }
            $this->scheduler->accept($wo, $choice);

            return null; // booking or re-offer already texted the tenant
        }

        if ($wo->status === WorkOrder::STATUS_COMPLETED && preg_match('/^\s*([1-5])\s*$/', $text, $m)) {
            $wo->update(['tenant_rating' => (int) $m[1]]);
            if ((int) $m[1] <= 2) {
                $wo->flagForReview('Low rating from tenant ('.$m[1].'/5)');
            }

            return 'Thanks for letting us know.';
        }

        // Free text: the assigned tech sees it in the job thread. Before a tech is set, the office sees it.
        if (! $wo->technician_id || $wo->status === WorkOrder::STATUS_OFFERED) {
            $wo->flagForReview('Tenant sent a message');
        }

        return null;
    }
}
