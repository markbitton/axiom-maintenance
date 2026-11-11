<?php

namespace App\Services\Triage;

final class TriageResult
{
    /**
     * @param  array<int, array{name: string, quantity: int, confidence: float}>  $likelyParts
     */
    public function __construct(
        public readonly string $trade,
        public readonly string $urgency,
        public readonly int $estimatedMinutes,
        public readonly float $confidence,
        public readonly array $likelyParts = [],
        public readonly ?string $summary = null,
        public readonly ?string $safetyAdvice = null,
        public readonly bool $needsVendor = false,
        public readonly string $source = 'rules',
    ) {}

    public function toArray(): array
    {
        return [
            'trade' => $this->trade,
            'urgency' => $this->urgency,
            'estimated_minutes' => $this->estimatedMinutes,
            'confidence' => $this->confidence,
            'likely_parts' => $this->likelyParts,
            'summary' => $this->summary,
            'safety_advice' => $this->safetyAdvice,
            'needs_vendor' => $this->needsVendor,
            'source' => $this->source,
        ];
    }
}
