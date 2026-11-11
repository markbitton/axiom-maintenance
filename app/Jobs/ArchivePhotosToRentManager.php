<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Models\WorkOrder;
use App\Services\Photos\R2Storage;
use App\Services\RentManager\RentManagerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** On close: copy each photo from R2 into the Rent Manager work order. R2 copies are purged later. */
class ArchivePhotosToRentManager implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $workOrderId) {}

    public function handle(RentManagerClient $client, R2Storage $r2): void
    {
        $wo = WorkOrder::with('location')->find($this->workOrderId);
        if (! $wo || ! $wo->rm_service_issue_id || ! config('axiom.rent_manager.enabled') || ! $client->isConfigured() || ! $r2->isConfigured()) {
            return;
        }

        foreach ($wo->photos()->whereNull('archived_to_rm_at')->get() as $photo) {
            /** @var Photo $photo */
            try {
                $result = $client->uploadIssueAttachment(
                    $wo->location->rm_location_id,
                    (int) $wo->rm_service_issue_id,
                    "{$wo->reference}-{$photo->kind}-{$photo->id}.".pathinfo($photo->object_key, PATHINFO_EXTENSION),
                    $r2->get($photo->object_key),
                    $photo->content_type,
                );
                $photo->update(['archived_to_rm_at' => now(), 'rm_attachment_id' => (string) ($result['AttachmentID'] ?? $result['FileID'] ?? '')]);
            } catch (Throwable $e) {
                Log::warning('Photo archive to Rent Manager failed', ['photo' => $photo->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
