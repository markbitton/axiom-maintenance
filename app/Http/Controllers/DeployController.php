<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoWorkOrdersSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Called by the GitHub deploy job right after it uploads a new build, because Bluehost
 * has no SSH step in that job. Runs migrations and the (re-runnable) base seeder.
 */
class DeployController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $key = (string) config('axiom.deploy_key');
        abort_if($key === '' || ! hash_equals($key, (string) $request->header('X-Deploy-Key')), 404);

        @set_time_limit(300);
        $out = [];

        // True only on the run that creates the first logins, so the deploy job hands them over once.
        $out['fresh_install'] = ! Schema::hasTable('users') || User::count() === 0;

        // Not optimize:clear: its cache:clear needs the cache table, which may not exist yet.
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            Artisan::call($command);
        }
        // MySQL can't roll back a half-applied migration, so until the first users exist a failed
        // first run is retried from an empty database instead of tripping over its leftover tables.
        Artisan::call($out['fresh_install'] ? 'migrate:fresh' : 'migrate', ['--force' => true]);
        $out['migrate'] = trim(Artisan::output());

        $seeder = config('axiom.demo_data') && Technician::count() === 0 ? DemoSeeder::class : DatabaseSeeder::class;
        Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        $out['seed'] = class_basename($seeder);

        if (config('axiom.demo_data') && ! DemoWorkOrdersSeeder::isCurrent()) {
            DemoWorkOrdersSeeder::reset();
            $out['demo_work_orders'] = WorkOrder::count();
        }

        // Counts only (no personal data), so the deploy log shows what the site will display.
        $out['check'] = [
            'db_now' => DB::getDriverName() === 'mysql' ? (string) DB::scalar('select now()') : null,
            'app_now_utc' => now()->utc()->toDateTimeString(),
            'users' => User::selectRaw('role, location_id, count(*) as n')->groupBy('role', 'location_id')->get(),
            'techs' => Technician::selectRaw('location_id, active, count(*) as n')->groupBy('location_id', 'active')->get(),
            'locations' => Location::get(['id', 'rm_location_id', 'timezone', 'enabled']),
            'jobs_by_status' => WorkOrder::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'booked_next_7_days' => WorkOrder::whereNotNull('technician_id')->whereBetween('scheduled_start', [now()->startOfDay(), now()->addDays(7)])->count(),
            'first_booked' => (string) WorkOrder::whereNotNull('scheduled_start')->min('scheduled_start'),
            'last_booked' => (string) WorkOrder::whereNotNull('scheduled_start')->max('scheduled_start'),
        ];

        return response()->json(['ok' => true] + $out);
    }
}
