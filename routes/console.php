<?php

use App\Jobs\ArchivePhotosToRentManager;
use App\Jobs\PushToRentManager;
use App\Models\Location;
use App\Models\Photo;
use App\Models\SlotOffer;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Photos\R2Storage;
use App\Services\RentManager\IssueSync;
use App\Services\RentManager\RentManagerClient;
use App\Services\Scheduling\Planner;
use App\Services\Scheduling\RoutePlanner;
use App\Services\Scheduling\Texts;
use App\Services\Sms\Messenger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('rm:pull', function (IssueSync $sync, RentManagerClient $client) {
    if (! config('axiom.rent_manager.enabled') || ! $client->isConfigured()) {
        $this->warn('Rent Manager sync is off (set RM_SYNC_ENABLED, RM_API_USER, RM_API_PASSWORD).');

        return;
    }
    $this->info('New work orders: '.$sync->pull());
})->purpose('Pull new and changed service issues from Rent Manager');

Artisan::command('rm:test', function (RentManagerClient $client) {
    foreach (Location::orderBy('rm_location_id')->get() as $loc) {
        try {
            $client->token($loc->rm_location_id, true);
            $this->info("Location {$loc->rm_location_id} ({$loc->name}): login OK");
        } catch (Throwable $e) {
            $this->error("Location {$loc->rm_location_id} ({$loc->name}): ".$e->getMessage());
        }
    }
})->purpose('Check the Rent Manager login for each location');

Artisan::command('offers:follow-up', function (Messenger $messenger) {
    $hours = (int) config('axiom.scheduling.offer_reply_hours');
    WorkOrder::with('location', 'offers', 'tenant')->where('status', WorkOrder::STATUS_OFFERED)->get()
        ->each(function (WorkOrder $wo) use ($hours, $messenger) {
            $open = $wo->offers->whereNull('accepted_at')->whereNull('withdrawn_at');
            $sent = $open->min('sent_at');
            if (! $sent || $open->isEmpty()) {
                return;
            }
            if ($sent->lt(now()->subHours($hours)) && ! $open->first()->reminded_at) {
                $messenger->toTenant($wo, 'Axiom Maintenance: reminder to pick a time for '.$wo->reference.":\n"
                    .$open->map(fn (SlotOffer $o) => $o->option_number.') '.Texts::window($o->starts_at, $o->ends_at, $wo->location->timezone))->implode("\n"));
                SlotOffer::whereIn('id', $open->pluck('id'))->update(['reminded_at' => now()]);
            } elseif ($sent->lt(now()->subHours($hours * 2)) && ! $wo->needs_review_at) {
                $wo->flagForReview('No reply from tenant to time options');
            }
        });
})->purpose('Remind tenants who have not picked a time, then flag them');

Artisan::command('visits:remind', function (Messenger $messenger) {
    WorkOrder::with('location', 'tenant', 'technician.user')->where('status', WorkOrder::STATUS_SCHEDULED)
        ->whereBetween('scheduled_start', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])
        ->where('urgency', '!=', 'emergency')
        ->get()
        ->each(function (WorkOrder $wo) use ($messenger) {
            if (! $wo->activity()->where('event', 'reminder_sent')->exists()) {
                $messenger->toTenant($wo, Texts::reminder($wo, $wo->location->timezone));
                $wo->log('reminder_sent');
            }
        });
})->purpose('Text tenants the day before their visit');

Artisan::command('routes:plan', function (RoutePlanner $planner, Planner $suggest) {
    // Tomorrow: put stops in driving order without changing anyone's time (tenants already have them).
    Technician::with('location')->where('active', true)->get()
        ->each(fn ($t) => $planner->planDay($t, CarbonImmutable::now($t->location->timezone)->addDay()));
    // Further out: suggest drive-saving moves for the office to approve (tenants get 24+ hours' notice).
    Location::enabled()->get()->each(function (Location $loc) use ($suggest) {
        $r = $suggest->proposeMoves($loc);
        $this->line("{$loc->name}: {$r['moves']} suggested moves, about {$r['saved']} min less driving");
    });
})->purpose("Order tomorrow's stops and suggest drive-saving moves for later days");

Artisan::command('workorders:close', function () {
    WorkOrder::where('status', WorkOrder::STATUS_COMPLETED)->whereNull('needs_review_at')
        ->where('completed_at', '<', now()->subDay())->get()
        ->each(function (WorkOrder $wo) {
            $wo->update(['status' => WorkOrder::STATUS_CLOSED, 'closed_at' => now()]);
            $wo->log('closed');
            PushToRentManager::dispatch($wo->id);
            ArchivePhotosToRentManager::dispatch($wo->id);
        });
})->purpose('Close jobs a day after completion and archive their photos to Rent Manager');

Artisan::command('photos:purge', function (R2Storage $r2) {
    if (! $r2->isConfigured()) {
        return;
    }
    $days = (int) config('axiom.photos.purge_days_after_close');
    Photo::whereNotNull('archived_to_rm_at')->whereNull('purged_at')
        ->whereHas('workOrder', fn ($q) => $q->where('status', WorkOrder::STATUS_CLOSED)->where('closed_at', '<', now()->subDays($days)))
        ->each(function (Photo $p) use ($r2) {
            $r2->delete($p->object_key);
            $p->update(['purged_at' => now()]);
        });
})->purpose('Delete R2 copies of photos already archived in Rent Manager');

// Bluehost cron runs `php artisan schedule:run` every minute; the queue is drained by the same cron.
Schedule::command('rm:pull')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
Schedule::command('offers:follow-up')->hourly();
Schedule::command('visits:remind')->dailyAt('17:00')->timezone('America/Boise');
Schedule::command('routes:plan')->dailyAt('16:00')->timezone('America/Boise');
Schedule::command('workorders:close')->hourly();
Schedule::command('photos:purge')->dailyAt('03:00');
