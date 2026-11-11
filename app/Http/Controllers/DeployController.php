<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

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

        Artisan::call('optimize:clear');
        Artisan::call('migrate', ['--force' => true]);
        $out['migrate'] = trim(Artisan::output());

        $seeder = config('axiom.demo_data') && Technician::count() === 0 ? DemoSeeder::class : DatabaseSeeder::class;
        Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        $out['seed'] = class_basename($seeder);

        return response()->json(['ok' => true] + $out);
    }
}
