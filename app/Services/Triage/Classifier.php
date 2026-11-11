<?php

namespace App\Services\Triage;

interface Classifier
{
    public function classify(string $description, ?string $category = null): TriageResult;
}
