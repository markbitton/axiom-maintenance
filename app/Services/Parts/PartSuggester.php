<?php

namespace App\Services\Parts;

use App\Models\Part;
use App\Models\StockLevel;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Illuminate\Support\Facades\DB;

/**
 * Suggests parts for a job.
 * Stage 1: the AI's guess from the description.
 * Stage 2: once enough jobs are closed, parts actually used on similar past jobs (same trade + shared keywords).
 */
class PartSuggester
{
    public const HISTORY_MIN_JOBS = 5;

    /** @return WorkOrderPart[] */
    public function suggest(WorkOrder $wo): array
    {
        $wo->parts()->where('suggested', true)->where('used', false)->delete();

        $suggestions = [];
        foreach ($this->fromHistory($wo) as $s) {
            $suggestions[mb_strtolower($s['name'])] = $s;
        }
        foreach (data_get($wo->triage, 'likely_parts', []) as $p) {
            $key = mb_strtolower($p['name']);
            $suggestions[$key] ??= $p + ['part_id' => $this->matchCatalog($p['name'], $wo->trade)?->id];
        }

        $rows = [];
        foreach (array_slice($suggestions, 0, 8) as $s) {
            $rows[] = $wo->parts()->create([
                'part_id' => $s['part_id'] ?? null,
                'part_name' => $s['name'],
                'quantity' => $s['quantity'] ?? 1,
                'suggested' => true,
                'suggestion_confidence' => $s['confidence'] ?? null,
            ]);
        }

        return $rows;
    }

    /** Parts used on similar closed jobs, ranked by how often they were needed. */
    public function fromHistory(WorkOrder $wo): array
    {
        if (! $wo->trade) {
            return [];
        }
        $words = $this->keywords($wo->description);
        $similar = WorkOrder::where('trade', $wo->trade)
            ->where('id', '!=', $wo->id)
            ->whereIn('status', [WorkOrder::STATUS_COMPLETED, WorkOrder::STATUS_CLOSED])
            ->latest('completed_at')
            ->limit(300)
            ->get(['id', 'description'])
            ->filter(fn ($j) => $words === [] || count(array_intersect($words, $this->keywords($j->description))) > 0);

        if ($similar->count() < self::HISTORY_MIN_JOBS) {
            return [];
        }

        $counts = WorkOrderPart::whereIn('work_order_id', $similar->pluck('id'))
            ->where('used', true)
            ->select('part_id', 'part_name', DB::raw('COUNT(DISTINCT work_order_id) as jobs'), DB::raw('AVG(quantity) as qty'))
            ->groupBy('part_id', 'part_name')
            ->orderByDesc('jobs')
            ->limit(6)
            ->get();

        return $counts->map(fn ($r) => [
            'part_id' => $r->part_id,
            'name' => $r->part_name,
            'quantity' => max(1, (int) round($r->qty)),
            'confidence' => round($r->jobs / $similar->count(), 2),
        ])->filter(fn ($s) => $s['confidence'] >= 0.15)->values()->all();
    }

    /** Which suggested parts are not on the assigned tech's truck. */
    public function missingFromTruck(WorkOrder $wo): array
    {
        $truck = $wo->technician?->truck;
        if (! $truck) {
            return [];
        }
        $missing = [];
        foreach ($wo->parts()->where('suggested', true)->get() as $p) {
            if (! $p->part_id) {
                continue;
            }
            $qty = StockLevel::where('stock_location_id', $truck->id)->where('part_id', $p->part_id)->value('quantity') ?? 0;
            if ($qty < $p->quantity) {
                $missing[] = $p->part_name;
            }
        }

        return $missing;
    }

    public function matchCatalog(string $name, ?string $trade): ?Part
    {
        $needle = mb_strtolower($name);
        $best = null;
        $bestScore = 0;
        foreach (Part::when($trade, fn ($q) => $q->where(fn ($q) => $q->where('trade', $trade)->orWhereNull('trade')))->get() as $part) {
            $hay = mb_strtolower($part->name.' '.implode(' ', $part->keywords ?? []));
            similar_text($needle, mb_strtolower($part->name), $pct);
            $score = $pct + (str_contains($hay, $needle) ? 40 : 0);
            if ($score > $bestScore) {
                $best = $part;
                $bestScore = $score;
            }
        }

        return $bestScore >= 60 ? $best : null;
    }

    private function keywords(string $text): array
    {
        $stop = ['the', 'and', 'is', 'in', 'my', 'a', 'of', 'to', 'it', 'not', 'on', 'for', 'with', 'has', 'have', 'was', 'are', 'but', 'from', 'this', 'that', 'there', 'when', 'our', 'we', 'i'];
        $words = preg_split('/[^a-z]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn ($w) => strlen($w) > 2 && ! in_array($w, $stop, true))));
    }
}
