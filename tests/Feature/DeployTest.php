<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

class DeployTest extends MaintenanceTestCase
{
    public function test_hook_is_hidden_without_a_key(): void
    {
        config(['axiom.deploy_key' => '']);
        $this->post('/_deploy/run', [], ['X-Deploy-Key' => ''])->assertNotFound();
    }

    public function test_hook_rejects_a_wrong_key(): void
    {
        config(['axiom.deploy_key' => 'right-key']);
        $this->post('/_deploy/run', [], ['X-Deploy-Key' => 'wrong-key'])->assertNotFound();
    }

    public function test_hook_migrates_and_seeds_demo_data_once(): void
    {
        config(['axiom.deploy_key' => 'right-key', 'axiom.demo_data' => true]);
        User::create(['name' => 'Existing Admin', 'email' => 'existing@example.test', 'password' => 'secret-password', 'role' => 'admin']);

        $this->post('/_deploy/run', [], ['X-Deploy-Key' => 'right-key'])
            ->assertOk()->assertJson(['ok' => true, 'fresh_install' => false, 'seed' => 'DemoSeeder']);
        $this->assertTrue(User::where('email', 'mike@example.test')->exists());

        $this->post('/_deploy/run', [], ['X-Deploy-Key' => 'right-key'])
            ->assertOk()->assertJson(['fresh_install' => false, 'seed' => 'DatabaseSeeder']);
        $this->assertSame(3, Location::count());
    }

    public function test_first_run_rebuilds_from_an_empty_database(): void
    {
        config(['axiom.deploy_key' => 'right-key']);
        User::query()->delete();
        Artisan::spy();

        $this->post('/_deploy/run', [], ['X-Deploy-Key' => 'right-key'])
            ->assertOk()->assertJson(['fresh_install' => true]);
        Artisan::shouldHaveReceived('call')->with('migrate:fresh', ['--force' => true]);
    }
}
