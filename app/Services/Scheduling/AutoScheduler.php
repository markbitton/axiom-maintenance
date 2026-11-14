<?php

namespace App\Services\Scheduling;

use App\Jobs\PushToRentManager;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sms\Messenger;
use Carbon\CarbonImmutable;

/**
 * Turns a triaged work order into a booked job without a scheduler.
 * Anything it can't settle goes to the exception queue with a plain-English reason.
 */
class AutoScheduler
{
    public function __construct(private SlotFinder $finder, private Messenger $messenger) {}

    public function schedule(WorkOrder $wo): string
    {
        $wo->loadMissing('location', 'tenant', 'property');
        $tz = $wo->location->timezone;

        if ($wo->isEmergency()) {
            return $this->dispatchEmergency($wo);
        }

        if (data_get($wo->triage, 'needs_vendor')) {
            $wo->flagForReview('May need an outside vendor');

            return 'flagged';
        }

        $lowConfidence = $wo->triage_confidence !== null && $wo->triage_confidence < (float) config('axiom.ai.min_confidence');

        $slots = $this->finder->find($wo, (int) config('axiom.scheduling.offers_per_request'));
        if ($slots === []) {
            $wo->flagForReview('No open slot in the next '.config('axiom.scheduling.horizon_days').' days');

            return 'flagged';
        }

        $withinTarget = $slots[0]->start->lte(CarbonImmutable::now()->addWeekdays((int) config('axiom.scheduling.target_business_days') + 1));
        if (! $withinTarget) {
            $wo->flagForReview('Earliest opening is past the target window');
        }

        if ($lowConfidence) {
            $wo->flagForReview('Not sure of the trade or urgency; please check');
        }

        if ($wo->permission_to_enter && $wo->urgency === 'routine' && config('axiom.scheduling.auto_book_with_permission')) {
            $best = collect($slots)->sortByDesc('score')->first();
            $this->book($wo, $best->technician, $best->start, $best->end, autoBooked: true);

            return 'booked';
        }

        if (! $wo->tenantPhone() || ($wo->tenant && ! $wo->tenant->canText())) {
            $wo->flagForReview('Tenant has no textable number; call to schedule');

            return 'flagged';
        }

        $this->offer($wo, $slots, $tz);

        return 'offered';
    }

    /** @param  Slot[]  $slots */
    public function offer(WorkOrder $wo, array $slots, string $tz): void
    {
        $wo->offers()->open()->update(['withdrawn_at' => now()]);
        $offers = [];
        foreach (array_values($slots) as $i => $slot) {
            $offers[] = $wo->offers()->create([
                'technician_id' => $slot->technician->id,
                'option_number' => $i + 1,
                'starts_at' => $slot->start,
                'ends_at' => $slot->end,
                'score' => $slot->score,
                'sent_at' => now(),
                'expires_at' => now()->addHours((int) config('axiom.scheduling.offer_reply_hours') * 2),
            ]);
        }
        $wo->update(['status' => WorkOrder::STATUS_OFFERED]);
        $this->messenger->toTenant($wo, Texts::offers($wo, $offers, $tz));
        $wo->log('slots_offered', ['count' => count($offers)]);
    }

    /** Tenant replied with an option number. Returns true when booked. */
    public function accept(WorkOrder $wo, int $option): bool
    {
        $offer = $wo->offers()->open()->where('option_number', $option)->first();
        if (! $offer) {
            return false;
        }
        $tz = $wo->location->timezone;
        $tech = $offer->technician()->with('skills', 'user')->first();
        $start = CarbonImmutable::parse($offer->starts_at);

        if (! $this->finder->stillFree($tech, $start, (int) ($wo->estimated_minutes ?: 60), $wo->id, $tz)) {
            // someone else took it; offer fresh times
            $slots = $this->finder->find($wo, (int) config('axiom.scheduling.offers_per_request'));
            if ($slots === []) {
                $wo->flagForReview('Chosen time was taken and no other openings found');

                return false;
            }
            $this->messenger->toTenant($wo, 'Sorry, that time was just taken. Here are the next openings.');
            $this->offer($wo, $slots, $tz);

            return false;
        }

        $offer->update(['accepted_at' => now()]);
        $this->book($wo, $tech, $start, CarbonImmutable::parse($offer->ends_at));

        return true;
    }

    public function book(WorkOrder $wo, Technician $tech, CarbonImmutable $start, CarbonImmutable $end, bool $autoBooked = false, ?User $by = null): void
    {
        $wo->offers()->open()->update(['withdrawn_at' => now()]);
        $wo->forceFill([
            'technician_id' => $tech->id,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'status' => WorkOrder::STATUS_SCHEDULED,
            'proposed_technician_id' => null, 'proposed_start' => null, 'proposed_kind' => null, 'proposed_reason' => null,
        ])->save();
        if ($wo->review_reason && ! str_starts_with($wo->review_reason, 'Not sure')) {
            $wo->clearReview();
        }
        $wo->setRelation('technician', $tech);
        $wo->log($autoBooked ? 'auto_booked' : 'booked', ['technician_id' => $tech->id, 'start' => $start->toIso8601String()], $by?->id);

        $this->messenger->toTenant($wo, Texts::booked($wo, $wo->location->timezone, $autoBooked));
        PushToRentManager::dispatch($wo->id);
    }

    private function dispatchEmergency(WorkOrder $wo): string
    {
        $techs = $this->finder->qualifiedTechs($wo);
        $onCall = $techs->firstWhere('on_call', true)
            ?? Technician::where('location_id', $wo->location_id)->where('active', true)->where('on_call', true)->first()
            ?? $techs->first();

        $advice = data_get($wo->triage, 'safety_advice');

        if ($onCall) {
            $wo->forceFill([
                'technician_id' => $onCall->id,
                'scheduled_start' => now(),
                'scheduled_end' => now()->addHours(2),
                'status' => WorkOrder::STATUS_SCHEDULED,
            ])->save();
            $wo->setRelation('technician', $onCall);
            if ($phone = $onCall->user?->phone) {
                $this->messenger->toNumber($phone, "EMERGENCY {$wo->reference}: ".$wo->displayAddress().' - '
                    .str($wo->description)->limit(200).' Tenant: '.($wo->tenant?->name ?? $wo->contact_name ?? 'unknown')
                    .'. Open the app to message the tenant.', $wo);
            }
            PushToRentManager::dispatch($wo->id);
        }

        $this->messenger->toTenant($wo, Texts::emergency($wo, $advice));
        if ($office = config('axiom.sms.office_phone')) {
            $this->messenger->toNumber($office, "Emergency {$wo->reference} at ".$wo->displayAddress()
                .($onCall ? " sent to {$onCall->name}." : ' - NO TECH AVAILABLE.'), $wo);
        }
        $wo->flagForReview($onCall ? 'Emergency dispatched to '.$onCall->name.'; confirm they responded' : 'Emergency with no tech available');
        $wo->log('emergency_dispatched', ['technician_id' => $onCall?->id]);

        return 'emergency';
    }
}
