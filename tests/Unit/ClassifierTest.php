<?php

namespace Tests\Unit;

use App\Services\Triage\ClaudeClassifier;
use App\Services\Triage\RulesClassifier;
use PHPUnit\Framework\TestCase;

class ClassifierTest extends TestCase
{
    public function test_rules_pick_trade_and_urgency(): void
    {
        $r = new RulesClassifier;
        $this->assertSame('plumbing', $r->classify('Toilet is clogged')->trade);
        $this->assertSame('routine', $r->classify('Toilet is clogged')->urgency);
        $this->assertSame('emergency', $r->classify('I smell gas near the stove')->urgency);
        $this->assertSame('electrical', $r->classify('Outlet in bedroom is sparking')->trade);
        $this->assertSame('emergency', $r->classify('Outlet in bedroom is sparking')->urgency);
        $this->assertSame('hvac', $r->classify('Furnace not working, no heat')->trade);
        $this->assertSame('locks', $r->classify('Something odd', 'Doors/Locks')->trade);
        $this->assertSame('general', $r->classify('Closet door fell off track')->trade);
    }

    public function test_ai_can_never_downgrade_an_emergency(): void
    {
        $rules = new RulesClassifier;
        $ai = new ClaudeClassifier($rules);
        $merged = $ai->merge(
            ['trade' => 'hvac', 'urgency' => 'routine', 'estimated_minutes' => 60, 'likely_parts' => ['igniter'], 'needs_vendor' => false, 'safety_advice' => '', 'summary' => 'x', 'confidence' => 0.9],
            $rules->classify('I smell gas from the furnace'),
        );
        $this->assertSame('emergency', $merged->urgency);
        $this->assertNotNull($merged->safetyAdvice);
        $this->assertSame('igniter', $merged->likelyParts[0]['name']);
    }

    public function test_ai_output_is_clamped_and_validated(): void
    {
        $rules = new RulesClassifier;
        $merged = (new ClaudeClassifier($rules))->merge(
            ['trade' => 'roofing', 'urgency' => 'whenever', 'estimated_minutes' => 9999, 'likely_parts' => [], 'confidence' => 4],
            $rules->classify('Leaky faucet'),
        );
        $this->assertSame('plumbing', $merged->trade);
        $this->assertSame('routine', $merged->urgency);
        $this->assertSame(480, $merged->estimatedMinutes);
        $this->assertSame(1.0, $merged->confidence);
    }
}
