<?php

namespace App\Services\Receipts;

use App\Models\Part;
use App\Models\PartPrice;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receipt photos for jobs. One receipt can cover several work orders. Each line is matched to a part on one of
 * those jobs; saving puts the price paid on that job's part, and records it in the part's price history by store.
 */
class ReceiptBook
{
    public function __construct(private ReceiptReader $reader) {}

    /** Save the photo, read it if AI is on, attach the jobs, and lay out lines to fill in. */
    public function create(?UploadedFile $photo, array $workOrderIds, User $by, ?string $vendor = null, ?string $date = null, ?float $total = null): Receipt
    {
        $path = null;
        $read = null;
        if ($photo) {
            $path = $photo->storeAs('receipts/'.now()->format('Y/m'), Str::uuid().'.'.($photo->guessExtension() ?: 'jpg'), 'local');
            $read = $this->reader->read((string) file_get_contents($photo->getRealPath()), (string) $photo->getMimeType());
        }

        return DB::transaction(function () use ($photo, $path, $read, $workOrderIds, $by, $vendor, $date, $total) {
            $receipt = Receipt::create([
                'vendor_id' => Vendor::resolve($vendor ?: ($read['vendor'] ?? null))?->id,
                'technician_id' => $by->technician?->id,
                'uploaded_by_user_id' => $by->id,
                'purchased_on' => $this->date($date ?: ($read['date'] ?? null)),
                'subtotal' => $read['subtotal'] ?? null, 'tax' => $read['tax'] ?? null,
                'total' => $total ?? ($read['total'] ?? null),
                'photo_path' => $path, 'photo_type' => $photo?->getMimeType(),
                'read_by' => $read ? 'ai' : 'manual', 'raw' => $read,
            ]);
            $receipt->workOrders()->sync($workOrderIds);
            $jobs = $receipt->workOrders()->with('parts')->get();

            if ($read && ! empty($read['lines'])) {
                foreach ($read['lines'] as $l) {
                    $qty = (float) ($l['quantity'] ?? 1) ?: 1;
                    $amount = (float) ($l['amount'] ?? ((float) ($l['unit_price'] ?? 0) * $qty));
                    $line = $receipt->lines()->create([
                        'description' => Str::limit((string) $l['description'], 250, ''), 'sku' => $l['sku'] ?? null, 'quantity' => $qty,
                        'unit_price' => (float) ($l['unit_price'] ?? ($qty ? $amount / $qty : 0)), 'amount' => $amount,
                    ]);
                    $line->fill($this->match($line->description, $line->sku, $jobs))->save();
                }
            } else {
                // No reading: list the parts on the attached jobs so the tech only types what each cost.
                foreach ($jobs as $wo) {
                    foreach ($wo->parts->filter(fn ($p) => $p->used || $p->suggested)->whereNull('unit_cost') as $p) {
                        $receipt->lines()->create(['description' => $p->part_name, 'quantity' => $p->quantity, 'unit_price' => 0, 'amount' => 0,
                            'part_id' => $p->part_id, 'work_order_id' => $wo->id, 'work_order_part_id' => $p->id]);
                    }
                }
            }

            return $receipt;
        });
    }

    /**
     * Best guess at which job and part a receipt line is for: a part already on one of the jobs, else a catalog part.
     *
     * @return array{work_order_id:?int, work_order_part_id:?int, part_id:?int}
     */
    public function match(string $description, ?string $sku, Collection $jobs): array
    {
        $best = ['work_order_id' => $jobs->count() === 1 ? $jobs->first()->id : null, 'work_order_part_id' => null, 'part_id' => null];
        $catalog = $this->catalogPart($description, $sku);
        $bestScore = 0;
        foreach ($jobs as $wo) {
            foreach ($wo->parts as $p) {
                $score = ($catalog && $p->part_id === $catalog->id) ? 100 : $this->similarity($description, $p->part_name);
                if ($score > $bestScore && $score >= 45) {
                    $bestScore = $score;
                    $best = ['work_order_id' => $wo->id, 'work_order_part_id' => $p->id, 'part_id' => $p->part_id ?? $catalog?->id];
                }
            }
        }
        $best['part_id'] ??= $catalog?->id;

        return $best;
    }

    /**
     * Save the reviewed receipt. $lines: [{id?, description, quantity, unit_price, assign: "wop:12" | "wo:5" | ""}]
     */
    public function save(Receipt $receipt, array $data, array $lines, array $workOrderIds): void
    {
        DB::transaction(function () use ($receipt, $data, $lines, $workOrderIds) {
            $vendor = Vendor::resolve($data['vendor'] ?? null);
            $receipt->update([
                'vendor_id' => $vendor?->id, 'purchased_on' => $this->date($data['purchased_on'] ?? null),
                'total' => ($data['total'] ?? '') === '' ? null : (float) $data['total'],
            ]);
            $receipt->workOrders()->sync($workOrderIds);
            $day = $receipt->purchased_on ?? now();

            $keep = [];
            foreach ($lines as $row) {
                $desc = trim((string) ($row['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $qty = max(0.01, (float) ($row['quantity'] ?? 1));
                $price = round((float) ($row['unit_price'] ?? 0), 2);
                $line = ! empty($row['id']) ? $receipt->lines()->find($row['id']) : null;
                $line ??= $receipt->lines()->make();
                $line->fill(['description' => $desc, 'quantity' => $qty, 'unit_price' => $price, 'amount' => round($qty * $price, 2)]);

                [$kind, $id] = array_pad(explode(':', (string) ($row['assign'] ?? '')), 2, null);
                $wop = null;
                if ($kind === 'wop' && ($wop = WorkOrderPart::whereIn('work_order_id', $workOrderIds)->find($id))) {
                    $line->work_order_id = $wop->work_order_id;
                } elseif ($kind === 'wo' && in_array((int) $id, array_map('intval', $workOrderIds), true)) {
                    $wo = WorkOrder::find($id);
                    $part = $line->part_id ? Part::find($line->part_id) : ($this->catalogPart($desc, $line->sku) ?? Part::create([
                        'sku' => 'NEW-'.strtoupper(Str::random(6)), 'vendor_sku' => $line->sku, 'name' => Str::limit($desc, 120, ''),
                        'trade' => $wo->trade, 'keywords' => [], 'unit_cost' => $price,
                    ]));
                    $wop = $wo->parts()->create(['part_id' => $part->id, 'part_name' => $part->name, 'quantity' => max(1, (int) round($qty)), 'used' => true]);
                    $line->work_order_id = $wo->id;
                } else {
                    $line->work_order_id = null; // shop stock or not for a job
                }
                if ($wop) {
                    $wop->update(['unit_cost' => $price, 'vendor_id' => $vendor?->id, 'used' => true,
                        'quantity' => max(1, (int) round($qty))]);
                    $line->work_order_part_id = $wop->id;
                    $line->part_id = $wop->part_id ?? $line->part_id;
                } else {
                    $line->work_order_part_id = null;
                    $line->part_id ??= $this->catalogPart($desc, $line->sku)?->id;
                }
                $line->save();
                $keep[] = $line->id;

                // Price history by store, and the catalog cost follows the latest price paid.
                if ($line->part_id && $price > 0) {
                    PartPrice::updateOrCreate(['receipt_line_id' => $line->id], [
                        'part_id' => $line->part_id, 'vendor_id' => $vendor?->id, 'unit_cost' => $price, 'purchased_on' => $day,
                    ]);
                    Part::whereKey($line->part_id)->update(['unit_cost' => $price]);
                }
            }
            $receipt->lines()->whereNotIn('id', $keep)->each(function (ReceiptLine $l) {
                PartPrice::where('receipt_line_id', $l->id)->delete();
                $l->delete();
            });
        });
    }

    public function catalogPart(string $description, ?string $sku): ?Part
    {
        if ($sku && ($p = Part::where('vendor_sku', $sku)->orWhere('sku', $sku)->first())) {
            return $p;
        }
        $best = null;
        $bestScore = 0;
        $text = mb_strtolower($description);
        foreach (Part::all() as $part) {
            $score = $this->similarity($description, $part->name);
            foreach ($part->keywords ?? [] as $k) {
                if ($k !== '' && str_contains($text, mb_strtolower($k))) {
                    $score = max($score, 70);
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$part, $score];
            }
        }

        return $bestScore >= 60 ? $best : null;
    }

    /** 0-100: shared words count most, then letter similarity ("TOILET FLAPPER 2IN" vs "Toilet flapper"). */
    private function similarity(string $a, string $b): float
    {
        $words = fn ($s) => array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($s)), fn ($w) => strlen($w) > 2));
        $wa = $words($a);
        $wb = $words($b);
        if (! $wa || ! $wb) {
            return 0;
        }
        $shared = count(array_intersect($wa, $wb));
        similar_text(mb_strtolower($a), mb_strtolower($b), $pct);

        return min(100, 100 * $shared / min(count($wa), count($wb)) * 0.8 + $pct * 0.2);
    }

    private function date(?string $value): ?string
    {
        try {
            return $value ? CarbonImmutable::parse($value)->toDateString() : now()->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }
}
