<?php

namespace App\Services\Triage;

/**
 * Keyword rules. Always available, used when AI triage is off or fails, and as a safety net:
 * an emergency keyword always wins, whatever the AI says.
 */
class RulesClassifier implements Classifier
{
    public const EMERGENCY_PATTERNS = [
        'gas smell' => 'If you smell gas, leave the unit now and call the gas company from outside. Do not use switches or flames.',
        'smell gas' => 'If you smell gas, leave the unit now and call the gas company from outside. Do not use switches or flames.',
        'smells like gas' => 'If you smell gas, leave the unit now and call the gas company from outside. Do not use switches or flames.',
        'carbon monoxide' => 'If a CO alarm is sounding, get everyone outside and call 911.',
        'sparking' => 'Turn off the breaker for that area if you can do so safely, and stay away from the outlet.',
        'burning smell' => 'If you see smoke or flames, leave and call 911.',
        'flooding' => 'Turn off the water shutoff valve under the sink or toilet, or the main shutoff, if you can.',
        'flooded' => 'Turn off the water shutoff valve under the sink or toilet, or the main shutoff, if you can.',
        'burst' => 'Turn off the main water shutoff if you can.',
        'water pouring' => 'Turn off the water shutoff valve nearest the leak, or the main shutoff.',
        'sewage' => 'Avoid using sinks, toilets and showers until a tech arrives.',
        'no heat' => null,
        'no power' => 'Check whether neighbors also lost power; if so, it may be a utility outage.',
        'locked out' => null,
        'lockout' => null,
        'door won\'t lock' => null,
        'break-in' => 'If you are in danger, call 911.',
        'broken window' => null,
    ];

    private const TRADES = [
        'plumbing' => ['leak', 'drip', 'toilet', 'clog', 'drain', 'faucet', 'sink', 'shower', 'tub', 'water heater', 'pipe', 'sewage', 'garbage disposal', 'disposal', 'flood', 'water pressure', 'running water'],
        'electrical' => ['outlet', 'breaker', 'light', 'switch', 'power', 'spark', 'electrical', 'gfci', 'fixture', 'ceiling fan', 'smoke detector', 'smoke alarm'],
        'hvac' => ['heat', 'furnace', 'ac ', 'a/c', 'air condition', 'thermostat', 'cooling', 'hvac', 'vent', 'filter', 'swamp cooler', 'heater'],
        'appliance' => ['fridge', 'refrigerator', 'freezer', 'dishwasher', 'washer', 'dryer', 'stove', 'oven', 'range', 'microwave', 'ice maker'],
        'locks' => ['lock', 'key', 'deadbolt', 'locked out', 'lockout', 'door handle'],
        'pest' => ['mice', 'mouse', 'roach', 'cockroach', 'ants', 'bed bug', 'bedbug', 'wasp', 'rodent', 'pest', 'spider'],
    ];

    private const MINUTES = ['plumbing' => 75, 'electrical' => 60, 'hvac' => 90, 'appliance' => 75, 'locks' => 45, 'pest' => 45, 'general' => 60];

    private const CATEGORY_TO_TRADE = [
        'plumbing' => 'plumbing', 'electrical' => 'electrical', 'heating/cooling' => 'hvac', 'hvac' => 'hvac',
        'appliance' => 'appliance', 'doors/locks' => 'locks', 'locks' => 'locks', 'pests' => 'pest', 'pest' => 'pest',
    ];

    public function classify(string $description, ?string $category = null): TriageResult
    {
        $text = ' '.mb_strtolower($description).' ';

        $scores = [];
        foreach (self::TRADES as $trade => $words) {
            foreach ($words as $w) {
                if (str_contains($text, $w)) {
                    $scores[$trade] = ($scores[$trade] ?? 0) + 1;
                }
            }
        }
        arsort($scores);
        $trade = array_key_first($scores) ?? (self::CATEGORY_TO_TRADE[mb_strtolower((string) $category)] ?? 'general');

        $categoryTrade = self::CATEGORY_TO_TRADE[mb_strtolower((string) $category)] ?? null;
        $confidence = match (true) {
            $scores === [] && $categoryTrade !== null => 0.6,
            $scores === [] => 0.3,
            $categoryTrade !== null && $categoryTrade !== $trade => 0.5,
            count($scores) > 1 && array_values($scores)[0] === array_values($scores)[1] => 0.55,
            default => 0.75,
        };

        [$urgency, $advice] = $this->urgency($text);

        return new TriageResult(
            trade: $trade,
            urgency: $urgency,
            estimatedMinutes: self::MINUTES[$trade],
            confidence: $confidence,
            likelyParts: [],
            summary: null,
            safetyAdvice: $advice,
            source: 'rules',
        );
    }

    /** @return array{0: string, 1: ?string} */
    public function urgency(string $text): array
    {
        $text = mb_strtolower($text);
        foreach (self::EMERGENCY_PATTERNS as $pattern => $advice) {
            if (str_contains($text, $pattern)) {
                return ['emergency', $advice];
            }
        }
        foreach (['no hot water', 'not cooling', 'no ac', 'fridge not', 'refrigerator not', 'toilet won', 'only toilet', 'active leak', 'leaking'] as $p) {
            if (str_contains($text, $p)) {
                return ['urgent', null];
            }
        }

        return ['routine', null];
    }
}
