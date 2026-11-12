<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Location;
use App\Models\Message;
use App\Models\WorkOrder;
use Database\Seeders\DemoWorkOrdersSeeder;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $since = now()->subDays(30);
        $created = WorkOrder::where('created_at', '>=', $since)->count();
        $autoBooked = Activity::where('event', 'auto_booked')->where('created_at', '>=', $since)->count();
        $tenantBooked = Activity::where('event', 'booked')->whereNull('user_id')->where('created_at', '>=', $since)->count();
        $manual = Activity::where('event', 'booked')->whereNotNull('user_id')->where('created_at', '>=', $since)->count();
        $booked = $autoBooked + $tenantBooked + $manual;

        return view('admin.dashboard', [
            'open' => WorkOrder::open()->count(),
            'review' => WorkOrder::needsReview()->count(),
            'emergencies' => WorkOrder::open()->where('urgency', 'emergency')->count(),
            'today' => WorkOrder::whereDate('scheduled_start', now()->toDateString())->count(),
            'created30' => $created,
            'hands_off_rate' => $booked ? round(100 * ($autoBooked + $tenantBooked) / $booked) : null,
            'avg_rating' => round((float) WorkOrder::whereNotNull('tenant_rating')->where('completed_at', '>=', $since)->avg('tenant_rating'), 1),
            'byTrade' => WorkOrder::where('created_at', '>=', $since)->selectRaw('trade, count(*) as n')->groupBy('trade')->pluck('n', 'trade'),
            'locations' => Location::orderBy('rm_location_id')->get(),
            'recent' => WorkOrder::with('technician.user')->latest()->limit(15)->get(),
            'demo' => (bool) config('axiom.demo_data'),
        ]);
    }

    public function toggleLocation(Location $location)
    {
        $location->update(['enabled' => ! $location->enabled]);

        return back()->with('status', $location->name.($location->enabled ? ' is now active.' : ' is now off.'));
    }

    /** Beta only: throw away every work order and make a fresh set dated around today. */
    public function resetDemo()
    {
        abort_unless(config('axiom.demo_data'), 404);
        DB::transaction(function () {
            Message::query()->delete();
            Activity::query()->delete();
            WorkOrder::query()->delete();
            (new DemoWorkOrdersSeeder)->run();
        });

        return back()->with('status', 'Demo work orders were reset around today.');
    }
}
