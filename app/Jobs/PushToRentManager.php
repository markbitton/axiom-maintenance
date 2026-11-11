<?php

namespace App\Jobs;

use App\Models\WorkOrder;
use App\Services\RentManager\IssueSync;
use App\Services\RentManager\RentManagerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushToRentManager implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $workOrderId) {}

    public function handle(IssueSync $sync, RentManagerClient $client): void
    {
        if (! config('axiom.rent_manager.enabled') || ! $client->isConfigured()) {
            return; // sync switched off until the Rent Manager login is in .env
        }
        if ($wo = WorkOrder::find($this->workOrderId)) {
            $sync->push($wo);
        }
    }
}
