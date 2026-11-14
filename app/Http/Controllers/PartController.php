<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PartPrice;
use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Parts inventory: what we stock, what we've paid, and where each part is cheapest. */
class PartController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $parts = Part::when($q, fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")))
            ->orderBy('trade')->orderBy('name')->get();
        $since = now()->subYear();
        $prices = PartPrice::with('vendor')->whereIn('part_id', $parts->pluck('id'))->where('purchased_on', '>=', $since)->get()->groupBy('part_id');
        $stock = StockLevel::selectRaw('part_id, sum(quantity) as qty')->groupBy('part_id')->pluck('qty', 'part_id');

        return view('parts.index', ['parts' => $parts, 'prices' => $prices, 'stock' => $stock, 'q' => $q]);
    }

    public function show(Part $part)
    {
        $history = $part->prices()->with('vendor')->orderByDesc('purchased_on')->orderByDesc('id')->get();
        $byStore = $history->groupBy(fn ($p) => $p->vendor?->name ?? 'Unknown store')->map(fn ($g) => [
            'last' => $g->first()->unit_cost, 'low' => $g->min('unit_cost'), 'avg' => round($g->avg('unit_cost'), 2),
            'count' => $g->count(), 'when' => $g->first()->purchased_on,
        ])->sortBy('low');

        return view('parts.show', [
            'part' => $part, 'history' => $history, 'byStore' => $byStore,
            'stock' => StockLevel::with('stockLocation')->where('part_id', $part->id)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'trade' => 'nullable|string|max:30', 'unit_cost' => 'nullable|numeric|min:0',
            'sku' => 'nullable|string|max:60|unique:parts,sku']);
        $part = Part::create(['name' => $data['name'], 'trade' => ($data['trade'] ?? null) ?: null, 'unit_cost' => $data['unit_cost'] ?? 0,
            'sku' => ($data['sku'] ?? null) ?: 'NEW-'.strtoupper(Str::random(6)), 'keywords' => []]);

        return redirect()->route('parts.show', $part)->with('status', 'Part added.');
    }

    public function update(Request $request, Part $part)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'trade' => 'nullable|string|max:30', 'unit_cost' => 'required|numeric|min:0',
            'vendor_sku' => 'nullable|string|max:60', 'keywords' => 'nullable|string|max:300']);
        $part->update(['name' => $data['name'], 'trade' => ($data['trade'] ?? null) ?: null, 'unit_cost' => $data['unit_cost'], 'vendor_sku' => ($data['vendor_sku'] ?? null) ?: null,
            'keywords' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['keywords'] ?? '')))))]);

        return back()->with('status', 'Part saved.');
    }
}
