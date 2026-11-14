<?php

namespace App\Services\Parts;

use App\Models\Part;
use App\Models\StockLevel;
use App\Models\WorkOrder;

/**
 * What the tech should bring: anticipated supplies (parts and consumables) and tools,
 * from the kind of work, words in the request, and parts used on similar past jobs.
 */
class ToolKit
{
    /** Always on the truck for this kind of work. */
    private const BASE_TOOLS = [
        'plumbing' => ['Channel-lock pliers', 'Adjustable wrench', 'Bucket and towels', 'Flashlight'],
        'electrical' => ['Non-contact voltage tester', 'Multimeter', 'Wire strippers', 'Insulated screwdrivers'],
        'hvac' => ['Multimeter', 'Nut drivers', 'Shop vac', 'Flashlight'],
        'appliance' => ['Multimeter', 'Nut drivers', 'Appliance dolly', 'Towels'],
        'locks' => ['Drill/driver', 'Screwdriver set', 'Chisel'],
        'pest' => ['Gloves and mask', 'Flashlight', 'Caulk gun'],
        'general' => ['Drill/driver', 'Screwdriver set', 'Tape measure', 'Level'],
    ];

    /** [trade or *, words in the request, tools, supplies] */
    private const RULES = [
        ['plumbing', ['clog', 'drain', 'backing up', 'slow'], ['Drain snake / auger', 'Plunger', 'Wet/dry vac'], ['Drain cleaner enzyme']],
        ['plumbing', ['toilet', 'running', 'flush'], ['Toilet auger', 'Sponge'], ['Toilet fill valve', 'Toilet flapper', 'Wax ring']],
        ['plumbing', ['faucet', 'drip', 'leak'], ['Basin wrench', 'Seat wrench', 'Cartridge puller'], ["Plumber's tape", 'Faucet cartridge (universal)', 'Silicone caulk']],
        ['plumbing', ['water heater'], ['Garden hose (to drain)', 'Pipe wrench', 'Tubing cutter', 'Torch / soldering kit'], ['Braided supply line', 'Pipe dope', 'T&P relief valve']],
        ['plumbing', ['pressure', 'shower'], ['Basin wrench'], ['Shower aerator / showerhead', "Plumber's tape"]],
        ['plumbing', ['spigot', 'hose bib', 'outdoor'], ['Pipe wrench'], ['Hose bib washer kit', "Plumber's tape"]],
        ['electrical', ['outlet', 'receptacle'], ['Outlet tester'], ['15A duplex outlet', '15A GFCI outlet', 'Wire nuts', 'Cover plates']],
        ['electrical', ['breaker', 'tripping'], ['Clamp meter', 'Panel screwdriver'], ['15A breaker']],
        ['electrical', ['smoke', 'chirp', 'detector'], ['Step ladder'], ['Smoke detector', '9V batteries']],
        ['electrical', ['fan', 'light', 'fixture'], ['Step ladder'], ['Wire nuts', 'Electrical tape']],
        ['electrical', ['burning', 'warm', 'smell'], ['Thermal camera', 'Clamp meter'], ['15A duplex outlet', 'Wire nuts']],
        ['hvac', ['furnace', 'heat', 'cold air'], ['Manometer', 'Igniter tester'], ['Furnace igniter', 'Flame sensor', 'Furnace filter 16x25x1']],
        ['hvac', ['thermostat', 'blank'], ['Screwdriver set'], ['Thermostat', 'AA batteries']],
        ['hvac', ['filter', 'dusty'], [], ['Furnace filter 16x25x1']],
        ['hvac', ['ac', 'air condition', 'cooling'], ['Refrigerant gauges', 'Fin comb'], ['AC run capacitor']],
        ['appliance', ['dishwasher'], ['Wet/dry vac'], ['Dishwasher drain hose', 'Hose clamps']],
        ['appliance', ['oven', 'stove', 'bake'], [], ['Oven bake element']],
        ['appliance', ['disposal'], ['Disposal hex key (1/4")'], ['Disposal splash guard']],
        ['appliance', ['refrigerator', 'fridge', 'cooling'], ['Coil brush', 'Thermometer'], ['Condenser fan motor']],
        ['locks', ['deadbolt', 'hard to turn', 'lock'], ['Lock pick / rekey kit', 'Graphite lubricant'], ['Deadbolt', 'Rekey kit']],
        ['locks', ['sliding', 'latch'], [], ['Sliding door latch', 'Rollers']],
        ['general', ['closet', 'track'], [], ['Bifold door hardware kit']],
        ['general', ['blind'], ['Step ladder'], ['2" faux wood blinds']],
        ['general', ['caulk', 'mold', 'tub'], ['Caulk gun', 'Razor scraper'], ['Mold-resistant silicone caulk', 'Mildew remover']],
        ['general', ['hinge', 'cabinet'], [], ['Cabinet hinges', 'Wood screws']],
        ['general', ['stain', 'ceiling', 'water'], ['Moisture meter', 'Step ladder'], ['Stain-blocking primer']],
        ['general', ['fence', 'panel'], ['Post-hole digger', 'Circular saw'], ['Fence panels', 'Exterior screws']],
        ['pest', ['ant'], [], ['Ant bait stations', 'Silicone caulk']],
        ['pest', ['mice', 'mouse', 'rodent', 'droppings'], [], ['Snap traps', 'Steel wool', 'Expanding foam']],
    ];

    /** @return array{supplies: list<array{name:string, qty:int, from:string, on_truck:?bool}>, tools: list<string>} */
    public function forWorkOrder(WorkOrder $wo): array
    {
        $wo->loadMissing('parts', 'technician.truck');
        $text = mb_strtolower($wo->description.' '.($wo->triage['summary'] ?? ''));
        $trade = $wo->trade ?: 'general';
        $tools = self::BASE_TOOLS[$trade] ?? self::BASE_TOOLS['general'];
        $supplies = [];
        $add = function (string $name, int $qty, string $from) use (&$supplies) {
            $supplies[mb_strtolower($name)] ??= ['name' => $name, 'qty' => $qty, 'from' => $from];
        };

        // Parts suggested for this job (AI triage or similar past jobs) come first.
        foreach ($wo->parts->where('used', false) as $p) {
            $add($p->part_name, (int) $p->quantity, $p->suggestion_confidence ? round($p->suggestion_confidence * 100).'% of similar jobs' : 'suggested');
        }
        foreach (self::RULES as [$t, $words, $ruleTools, $ruleSupplies]) {
            if (($t === '*' || $t === $trade) && $this->mentions($text, $words)) {
                array_push($tools, ...$ruleTools);
                foreach ($ruleSupplies as $s) {
                    $add($s, 1, 'from the request');
                }
            }
        }
        // Parts catalog keywords (e.g. "gfci", "flapper") that appear in the request.
        foreach (Part::where('trade', $trade)->get() as $part) {
            if ($this->mentions($text, $part->keywords ?? [])) {
                $add($part->name, 1, 'from the request');
            }
        }

        // Is it on the assigned tech's truck?
        $truck = $wo->technician?->truck;
        $catalog = Part::whereIn('name', array_column($supplies, 'name'))->pluck('id', 'name');
        foreach ($supplies as &$s) {
            $id = $catalog[$s['name']] ?? null;
            $s['on_truck'] = $truck && $id ? (StockLevel::where('stock_location_id', $truck->id)->where('part_id', $id)->value('quantity') ?? 0) >= $s['qty'] : null;
        }

        return ['supplies' => array_values(array_slice($supplies, 0, 10)), 'tools' => array_values(array_unique($tools))];
    }

    private function mentions(string $text, array $words): bool
    {
        foreach ($words as $w) {
            $w = mb_strtolower($w);
            if ($w !== '' && preg_match('/\b'.preg_quote($w, '/').(mb_strlen($w) <= 3 ? '\b' : '').'/u', $text)) {
                return true;
            }
        }

        return false;
    }
}
