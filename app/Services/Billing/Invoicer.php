<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a job's unbilled activity into an invoice: finished work time (and travel, if billed),
 * parts used, and the service call fee once per job. Everything it bills is stamped with the
 * invoice, so the next invoice on the same job only picks up what happened since.
 */
class Invoicer
{
    public function __construct(private RateCard $rates) {}

    /** What would go on an invoice right now, without saving anything. */
    public function preview(WorkOrder $wo): array
    {
        $wo->loadMissing('technician', 'property');
        $lines = [];
        $min = (int) Setting::get('minimum_billable_minutes');
        $travelPercent = $this->rates->travelPercent();
        $tz = $wo->location?->timezone ?? 'UTC';

        foreach ($this->unbilledTime($wo) as $entry) {
            $isTravel = $entry->type === TimeEntry::TRAVEL;
            if ($isTravel && $travelPercent <= 0) {
                continue;
            }
            $minutes = max($min, $entry->minutes());
            $rate = $this->rates->hourly($entry->technician, $wo->trade);
            if ($isTravel) {
                $rate = round($rate * $travelPercent / 100, 2);
            }
            $hours = round($minutes / 60, 2);
            $lines[] = [
                'kind' => $isTravel ? 'travel' : 'labor',
                'description' => ($isTravel ? 'Travel' : 'Labor').' · '.($entry->technician?->name ?? 'Technician').' · '
                    .$entry->started_at->setTimezone($tz)->format('M j g:i A').' ('.$minutes.' min)',
                'quantity' => $hours, 'unit_price' => $rate, 'amount' => round($hours * $rate, 2), 'time_entry_id' => $entry->id,
            ];
        }

        $markup = (float) Setting::get('parts_markup_percent');
        foreach ($this->unbilledParts($wo) as $part) {
            // What was actually paid (from a receipt) when we have it, else the catalog cost.
            $cost = round((float) ($part->unit_cost ?? $part->part?->unit_cost ?? 0) * (1 + $markup / 100), 2);
            $lines[] = [
                'kind' => 'part', 'description' => $part->part_name, 'quantity' => (float) $part->quantity,
                'unit_price' => $cost, 'amount' => round($cost * $part->quantity, 2), 'work_order_part_id' => $part->id,
            ];
        }

        $fee = $this->rates->serviceCallFee($wo->trade);
        if ($fee > 0 && ! $this->feeAlreadyBilled($wo)) {
            $lines[] = ['kind' => 'fee', 'description' => 'Service call · '.$wo->tradeLabel(), 'quantity' => 1, 'unit_price' => $fee, 'amount' => $fee];
        }

        return $lines;
    }

    public function create(WorkOrder $wo, ?User $by = null, ?string $notes = null): Invoice
    {
        return DB::transaction(function () use ($wo, $by, $notes) {
            // Lock the job's rows so two clicks can't bill the same time twice.
            WorkOrder::whereKey($wo->id)->lockForUpdate()->first();
            $lines = $this->preview($wo);
            if (! array_filter($lines, fn ($l) => $l['kind'] !== 'fee')) {
                throw new RuntimeException('Nothing new to bill on this job yet.');
            }

            $sum = fn (string ...$kinds) => round(array_sum(array_column(array_filter($lines, fn ($l) => in_array($l['kind'], $kinds, true)), 'amount')), 2);
            $invoice = Invoice::create([
                'number' => $this->nextNumber(), 'work_order_id' => $wo->id, 'bill_to' => $this->billTo($wo),
                'labor_total' => $sum('labor'), 'travel_total' => $sum('travel'), 'parts_total' => $sum('part'), 'fees_total' => $sum('fee'),
                'total' => $sum('labor', 'travel', 'part', 'fee'), 'notes' => $notes, 'created_by_user_id' => $by?->id,
            ]);
            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }
            TimeEntry::whereIn('id', array_filter(array_column($lines, 'time_entry_id')))->update(['invoice_id' => $invoice->id]);
            WorkOrderPart::whereIn('id', array_filter(array_column($lines, 'work_order_part_id')))->update(['invoice_id' => $invoice->id]);
            $wo->log('invoiced', ['invoice' => $invoice->number, 'total' => (float) $invoice->total], $by?->id);

            return $invoice;
        });
    }

    /** Voiding frees its time and parts so a corrected invoice can bill them again. */
    public function void(Invoice $invoice, ?User $by = null): void
    {
        DB::transaction(function () use ($invoice, $by) {
            TimeEntry::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            WorkOrderPart::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->update(['status' => 'void']);
            $invoice->workOrder?->log('invoice_voided', ['invoice' => $invoice->number], $by?->id);
        });
    }

    public function unbilledTime(WorkOrder $wo)
    {
        return $wo->timeEntries()->with('technician.user')->whereIn('type', [TimeEntry::WORK, TimeEntry::TRAVEL])
            ->whereNotNull('ended_at')->whereNull('invoice_id')->orderBy('started_at')->get();
    }

    public function unbilledParts(WorkOrder $wo)
    {
        return $wo->parts()->with('part')->where('used', true)->whereNull('invoice_id')->get();
    }

    private function feeAlreadyBilled(WorkOrder $wo): bool
    {
        return $wo->invoices()->where('status', '!=', 'void')->whereHas('lines', fn ($q) => $q->where('kind', 'fee'))->exists();
    }

    private function billTo(WorkOrder $wo): string
    {
        $p = $wo->property;

        return $p?->bill_to ?: (($p?->managed_by_axiom ?? true) ? 'Axiom Property Management' : ($p?->name ?? 'Owner'));
    }

    private function nextNumber(): string
    {
        $prefix = 'INV-'.now()->format('Y').'-';
        $last = Invoice::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
