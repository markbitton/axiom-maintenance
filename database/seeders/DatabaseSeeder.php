<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Part;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Base data every install needs. Safe to re-run. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Rent Manager location IDs. Idaho is live; Arizona and Utah are ready to switch on in Admin.
        foreach ([
            [1, 'Idaho', 'America/Boise', true],
            [2, 'Arizona', 'America/Phoenix', false],
            [9, 'Utah', 'America/Denver', false],
        ] as [$id, $name, $tz, $enabled]) {
            Location::firstOrCreate(['rm_location_id' => $id], ['name' => $name, 'timezone' => $tz, 'enabled' => $enabled]);
        }

        foreach (['plumbing' => 'Plumbing', 'electrical' => 'Electrical', 'hvac' => 'Heating & cooling', 'appliance' => 'Appliances',
            'locks' => 'Doors & locks', 'pest' => 'Pest control', 'general' => 'General repair'] as $slug => $name) {
            Skill::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }

        foreach ([
            ['PLB-WAX', 'Toilet wax ring', 'plumbing', ['wax ring', 'toilet seal']],
            ['PLB-FILL', 'Toilet fill valve', 'plumbing', ['fill valve', 'running toilet']],
            ['PLB-FLAP', 'Toilet flapper', 'plumbing', ['flapper', 'running toilet']],
            ['PLB-SUPPLY', 'Braided supply line', 'plumbing', ['supply line', 'supply hose']],
            ['PLB-PTRAP', 'P-trap kit 1-1/2"', 'plumbing', ['p-trap', 'trap', 'drain']],
            ['PLB-CART', 'Faucet cartridge (universal)', 'plumbing', ['cartridge', 'faucet']],
            ['ELC-GFCI', '15A GFCI outlet', 'electrical', ['gfci', 'outlet']],
            ['ELC-OUT', '15A duplex outlet', 'electrical', ['outlet', 'receptacle']],
            ['ELC-SW', 'Single-pole switch', 'electrical', ['switch']],
            ['ELC-SMOKE', 'Smoke detector', 'electrical', ['smoke detector', 'smoke alarm']],
            ['ELC-BREAKER', '15A breaker', 'electrical', ['breaker']],
            ['HVAC-FLT', 'Furnace filter 16x25x1', 'hvac', ['filter']],
            ['HVAC-IGN', 'Furnace igniter', 'hvac', ['igniter', 'ignitor']],
            ['HVAC-TSTAT', 'Thermostat', 'hvac', ['thermostat']],
            ['HVAC-CAP', 'AC run capacitor', 'hvac', ['capacitor']],
            ['APP-DWHOSE', 'Dishwasher drain hose', 'appliance', ['dishwasher hose']],
            ['APP-ELEM', 'Oven bake element', 'appliance', ['bake element', 'oven element']],
            ['LCK-DB', 'Deadbolt', 'locks', ['deadbolt', 'lock']],
            ['LCK-KNOB', 'Entry knob set', 'locks', ['door knob', 'door handle']],
            ['LCK-REKEY', 'Rekey kit', 'locks', ['rekey', 'key']],
        ] as [$sku, $name, $trade, $keywords]) {
            Part::firstOrCreate(['sku' => $sku], ['name' => $name, 'trade' => $trade, 'keywords' => $keywords]);
        }

        if (($email = env('ADMIN_EMAIL')) && ($password = env('ADMIN_PASSWORD'))) {
            User::firstOrCreate(['email' => $email], [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => $password,
                'role' => 'admin',
                'location_id' => Location::where('rm_location_id', 1)->value('id'),
            ]);
        }
    }
}
