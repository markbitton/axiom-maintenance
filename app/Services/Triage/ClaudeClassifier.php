<?php

namespace App\Services\Triage;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a tenant's request with Claude and returns trade, urgency, time on site and likely parts.
 * Falls back to the rules classifier on any error or refusal, and the rules classifier's emergency
 * check always gets the final say so a gas leak is never downgraded.
 */
class ClaudeClassifier implements Classifier
{
    private const SYSTEM = <<<'TXT'
You triage residential maintenance requests for a property maintenance company.
Read the tenant's request and classify it.

trade: one of plumbing, electrical, hvac, appliance, locks, pest, general.
urgency:
- emergency: risk to safety or major property damage now (gas smell, active flooding, sewage backup, no heat when freezing, no power to the unit, sparking, lockout, unsecured entry door or window).
- urgent: significant loss of use, fix within 24 to 48 hours (no hot water, AC out in heat, only toilet broken, fridge not cooling, slow active leak).
- routine: everything else.
estimated_minutes: realistic time on site for one technician, 15 to 480.
likely_parts: parts a technician should bring, most likely first, at most 6, short generic names (for example "wax ring", "fill valve", "15A GFCI outlet").
needs_vendor: true only if this clearly needs a licensed outside contractor (roof, major sewer line, structural, large appliance replacement).
safety_advice: one or two plain sentences for the tenant to follow until help arrives, only for emergencies; otherwise empty string.
summary: one short line a technician can read at a glance.
confidence: 0 to 1, how sure you are of trade and urgency together.
TXT;

    public function __construct(private RulesClassifier $rules, private ?Client $client = null) {}

    public function classify(string $description, ?string $category = null): TriageResult
    {
        $fallback = $this->rules->classify($description, $category);

        try {
            $client = $this->client ?? new Client(apiKey: (string) config('services.anthropic.key'));
            $message = $client->messages->create(
                model: config('axiom.ai.model'),
                maxTokens: 2000,
                system: self::SYSTEM,
                messages: [[
                    'role' => 'user',
                    'content' => 'Category chosen by tenant: '.($category ?: 'none')."\n\nRequest:\n".$description,
                ]],
                outputConfig: [
                    'effort' => config('axiom.ai.effort', 'low'),
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
            );

            if ($message->stopReason === 'refusal') {
                return $fallback;
            }

            $data = null;
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $data = json_decode($block->text, true);
                    break;
                }
            }
            if (! is_array($data)) {
                return $fallback;
            }

            return $this->merge($data, $fallback);
        } catch (Throwable $e) {
            Log::warning('AI triage failed, using rules', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /** Combine AI output with rule-based safety net. Public for testing. */
    public function merge(array $data, TriageResult $rules): TriageResult
    {
        $trades = ['plumbing', 'electrical', 'hvac', 'appliance', 'locks', 'pest', 'general'];
        $trade = in_array($data['trade'] ?? null, $trades, true) ? $data['trade'] : $rules->trade;
        $urgency = in_array($data['urgency'] ?? null, ['emergency', 'urgent', 'routine'], true) ? $data['urgency'] : $rules->urgency;
        $advice = ($data['safety_advice'] ?? '') ?: null;

        if ($rules->urgency === 'emergency') {
            $urgency = 'emergency';
            $advice ??= $rules->safetyAdvice;
        }

        $parts = [];
        foreach (array_slice($data['likely_parts'] ?? [], 0, 6) as $i => $name) {
            if (is_string($name) && trim($name) !== '') {
                $parts[] = ['name' => trim($name), 'quantity' => 1, 'confidence' => round(max(0.3, 0.8 - 0.1 * $i), 2)];
            }
        }

        return new TriageResult(
            trade: $trade,
            urgency: $urgency,
            estimatedMinutes: max(15, min(480, (int) ($data['estimated_minutes'] ?? $rules->estimatedMinutes))),
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.5))),
            likelyParts: $parts,
            summary: $data['summary'] ?? null,
            safetyAdvice: $advice,
            needsVendor: (bool) ($data['needs_vendor'] ?? false),
            source: 'ai',
        );
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'trade' => ['type' => 'string', 'enum' => ['plumbing', 'electrical', 'hvac', 'appliance', 'locks', 'pest', 'general']],
                'urgency' => ['type' => 'string', 'enum' => ['emergency', 'urgent', 'routine']],
                'estimated_minutes' => ['type' => 'integer'],
                'likely_parts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'needs_vendor' => ['type' => 'boolean'],
                'safety_advice' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['trade', 'urgency', 'estimated_minutes', 'likely_parts', 'needs_vendor', 'safety_advice', 'summary', 'confidence'],
            'additionalProperties' => false,
        ];
    }
}
