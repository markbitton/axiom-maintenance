<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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

        Artisan::call('optimize:clear');
        Artisan::call('migrate', ['--force' => true]);
        $out['migrate'] = trim(Artisan::output());

        $seeder = config('axiom.demo_data') && Technician::count() === 0 ? DemoSeeder::class : DatabaseSeeder::class;
        Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        $out['seed'] = class_basename($seeder);

        return response()->json(['ok' => true] + $out);
    }
}
