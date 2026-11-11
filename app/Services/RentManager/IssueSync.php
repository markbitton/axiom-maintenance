<?php

namespace App\Services\RentManager;

use App\Jobs\TriageWorkOrder;
use App\Models\Location;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Pulls new and changed service issues for every enabled location and pushes our changes back. */
class IssueSync
{
    public function __construct(private RentManagerClient $client, private IssueMapper $mapper) {}

    public function pull(): int
    {
        $created = 0;
        foreach (Location::enabled()->get() as $location) {
            $started = now();
            try {
                $since = optional($location->last_issue_sync_at)->subMinutes(5)->toIso8601String()
                    ?? now()->subDays(14)->toIso8601String();
                foreach ($this->client->serviceIssuesSince($location->rm_location_id, $since) as $issue) {
                    $wo = $this->mapper->upsertFromRentManager($location, $issue);
                    if ($wo && $wo->wasRecentlyCreated) {
                        $created++;
                        TriageWorkOrder::dispatch($wo->id);
                    }
                }
                $location->update(['last_issue_sync_at' => $started]);
            } catch (Throwable $e) {
                Log::warning('Rent Manager pull failed', ['location' => $location->rm_location_id, 'error' => $e->getMessage()]);
            }
        }

        return $created;
    }

    public function push(WorkOrder $wo): void
    {
        $wo->loadMissing('location', 'property', 'unit', 'tenant', 'technician.user');
        $saved = $this->client->saveServiceIssue($wo->location->rm_location_id, $this->mapper->toRentManager($wo));
        $wo->forceFill([
            'rm_service_issue_id' => $wo->rm_service_issue_id ?? ($saved['ServiceManagerIssueID'] ?? null),
            'rm_synced_at' => now(),
        ])->save();
    }
}
