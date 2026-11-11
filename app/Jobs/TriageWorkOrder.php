<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\Parts\PartSuggester;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Scheduling\Texts;
use App\Services\Sms\Messenger;
use App\Services\Triage\Classifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TriageWorkOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $workOrderId) {}

    public function handle(Classifier $classifier, PartSuggester $parts, AutoScheduler $scheduler, Messenger $messenger): void
    {
        $wo = WorkOrder::find($this->workOrderId);
        if (! $wo || $wo->status !== WorkOrder::STATUS_NEW) {
            return;
        }

        $result = $classifier->classify($wo->description, $wo->category);
        $wo->forceFill([
            'trade' => $result->trade,
            'urgency' => $result->urgency,
            'estimated_minutes' => $result->estimatedMinutes,
            'triage' => $result->toArray(),
            'triage_confidence' => $result->confidence,
            'status' => WorkOrder::STATUS_TRIAGED,
        ])->save();
        $wo->log('triaged', $result->toArray());

        $parts->suggest($wo);

        if (! $wo->isEmergency() && $wo->source !== 'rent_manager') {
            $messenger->toTenant($wo, Texts::received($wo));
        }

        $scheduler->schedule($wo->fresh());
    }
}
