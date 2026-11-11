<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Property;
use App\Models\Skill;
use App\Models\StockLocation;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Sample techs, properties and tenants for trying the app before Rent Manager is connected. Never run in production. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        $idaho = Location::where('rm_location_id', 1)->first();
        $skills = Skill::pluck('id', 'slug');

        User::firstOrCreate(['email' => 'admin@example.test'], ['name' => 'Demo Admin', 'password' => 'demo-password', 'role' => 'admin', 'location_id' => $idaho->id]);
        User::firstOrCreate(['email' => 'scheduler@example.test'], ['name' => 'Demo Scheduler', 'password' => 'demo-password', 'role' => 'scheduler', 'location_id' => $idaho->id]);

        foreach ([
            ['Mike Plumber', 'mike@example.test', ['plumbing' => 3, 'general' => 2, 'appliance' => 1], 43.6150, -116.2023, true],
            ['Dana Sparks', 'dana@example.test', ['electrical' => 3, 'hvac' => 2, 'general' => 2], 43.5826, -116.5635, false],
            ['Sam Fixit', 'sam@example.test', ['general' => 3, 'locks' => 3, 'pest' => 2, 'appliance' => 2], 43.6629, -116.6874, false],
        ] as [$name, $email, $levels, $lat, $lng, $onCall]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'demo-password', 'role' => 'tech', 'location_id' => $idaho->id]);
            $tech = Technician::firstOrCreate(['user_id' => $user->id], [
                'location_id' => $idaho->id, 'start_lat' => $lat, 'start_lng' => $lng, 'on_call' => $onCall,
            ]);
            $tech->skills()->sync(collect($levels)->mapWithKeys(fn ($l, $s) => [$skills[$s] => ['level' => $l]])->all());
            StockLocation::firstOrCreate(['technician_id' => $tech->id, 'type' => 'truck'], ['location_id' => $idaho->id, 'name' => "{$name}'s truck"]);
        }

        foreach ([
            ['Maple Court', '1200 W Maple Ct', 'Boise', 43.6187, -116.2146, ['101', '102', '201']],
            ['Riverside Apartments', '450 E Riverside Dr', 'Meridian', 43.6121, -116.3915, ['A1', 'A2']],
            ['Canyon View', '88 Canyon View Ln', 'Nampa', 43.5407, -116.5635, ['1', '2']],
        ] as $i => [$pname, $addr, $city, $lat, $lng, $units]) {
            $prop = Property::firstOrCreate(['location_id' => $idaho->id, 'rm_property_id' => 9000 + $i], [
                'name' => $pname, 'address' => $addr, 'city' => $city, 'state' => 'ID', 'lat' => $lat, 'lng' => $lng,
            ]);
            foreach ($units as $j => $u) {
                $unit = Unit::firstOrCreate(['property_id' => $prop->id, 'rm_unit_id' => 90000 + $i * 10 + $j], ['name' => $u]);
                Tenant::firstOrCreate(['location_id' => $idaho->id, 'rm_tenant_id' => 900000 + $i * 10 + $j], [
                    'unit_id' => $unit->id, 'name' => "Demo Tenant {$pname} {$u}", 'phone' => sprintf('+1208555%02d%02d', $i, $j), 'sms_consent' => true,
                ]);
            }
        }
    }
}
