<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Property;
use App\Models\Skill;
use App\Models\StockLocation;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Sms\LogGateway;
use App\Services\Sms\SmsGateway;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

abstract class MaintenanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected LogGateway $sms;

    protected Location $idaho;

    protected Property $property;

    protected Unit $unit;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 10:00 in Boise
        $now = CarbonImmutable::parse('2026-11-09 10:00', 'America/Boise')->utc();
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $this->seed(DatabaseSeeder::class);
        config(['axiom.intake_key' => 'test-key', 'axiom.sms.driver' => 'log', 'axiom.sms.from' => '+12085550000', 'axiom.ai.enabled' => false]);
        $this->sms = new LogGateway;
        $this->app->instance(SmsGateway::class, $this->sms);

        $this->idaho = Location::where('rm_location_id', 1)->first();
        $this->property = Property::create(['location_id' => $this->idaho->id, 'rm_property_id' => 1, 'name' => 'Maple Court',
            'address' => '1200 W Maple Ct', 'city' => 'Boise', 'state' => 'ID', 'lat' => 43.6187, 'lng' => -116.2146]);
        $this->unit = Unit::create(['property_id' => $this->property->id, 'rm_unit_id' => 11, 'name' => '101']);
        $this->tenant = Tenant::create(['location_id' => $this->idaho->id, 'rm_tenant_id' => 111, 'unit_id' => $this->unit->id,
            'name' => 'Pat Tenant', 'phone' => '+12085551234', 'sms_consent' => true]);
    }

    protected function tech(string $name, array $skills, array $attrs = []): Technician
    {
        $user = User::create(['name' => $name, 'email' => str($name)->slug().'@example.test', 'password' => 'secret-password',
            'role' => 'tech', 'phone' => '+1208555'.random_int(1000, 9999), 'location_id' => $this->idaho->id]);
        $tech = Technician::create(['user_id' => $user->id, 'location_id' => $this->idaho->id, 'start_lat' => 43.61, 'start_lng' => -116.20] + $attrs);
        $ids = Skill::pluck('id', 'slug');
        $tech->skills()->sync(collect($skills)->mapWithKeys(fn ($l, $s) => [$ids[$s] => ['level' => $l]])->all());
        StockLocation::create(['technician_id' => $tech->id, 'type' => 'truck', 'location_id' => $this->idaho->id, 'name' => 'truck']);

        return $tech->fresh(['skills', 'user']);
    }

    protected function staff(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $role.'@example.test', 'password' => 'secret-password', 'role' => $role, 'location_id' => $this->idaho->id]);
    }

    protected function intakePayload(array $overrides = []): array
    {
        return array_merge([
            'tenant_name' => 'Pat Tenant',
            'phone' => '(208) 555-1234',
            'sms_consent' => true,
            'property_address' => '1200 W Maple Ct, Boise ID',
            'unit' => '101',
            'category' => 'Plumbing',
            'description' => 'Kitchen sink drain is clogged and backing up slowly.',
            'permission_to_enter' => false,
            'submitted_at' => now()->toIso8601String(),
        ], $overrides);
    }
}
