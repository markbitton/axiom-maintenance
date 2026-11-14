<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\Technician;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\Receipts\ReceiptBook;
use App\Services\Receipts\ReceiptReader;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** Receipt photos: snap, attach to one or more jobs, match each line to a part, record what was paid and where. */
class ReceiptController extends Controller
{
    public function create(Request $request, ReceiptReader $reader)
    {
        $wo = $request->integer('wo') ? WorkOrder::findOrFail($request->integer('wo')) : null;
        if ($wo) {
            $this->authorizeJob($request, $wo);
        }
        $tech = $request->user()->technician ?? $wo?->technician;

        return view('receipts.create', [
            'wo' => $wo, 'jobs' => $this->recentJobs($tech, $wo), 'vendors' => Vendor::orderBy('name')->pluck('name'),
            'aiReads' => $reader->enabled(),
        ]);
    }

    public function store(Request $request, ReceiptBook $book)
    {
        $data = $request->validate([
            'photo' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf|max:12288',
            'work_orders' => 'required|array|min:1', 'work_orders.*' => 'integer|exists:work_orders,id',
            'vendor' => 'nullable|string|max:120', 'purchased_on' => 'nullable|date', 'total' => 'nullable|numeric|min:0',
        ], ['work_orders.required' => 'Pick at least one job this receipt is for.']);
        foreach (WorkOrder::whereIn('id', $data['work_orders'])->get() as $wo) {
            $this->authorizeJob($request, $wo);
        }
        $receipt = $book->create($request->file('photo'), $data['work_orders'], $request->user(), $data['vendor'] ?? null,
            $data['purchased_on'] ?? null, isset($data['total']) ? (float) $data['total'] : null);

        return redirect()->route('receipts.show', $receipt)->with('status', $receipt->read_by === 'ai'
            ? 'Receipt read. Check the prices and which job each item is for, then Save.'
            : 'Receipt saved. Enter what each item cost and which job it is for, then Save.');
    }

    public function show(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);
        $receipt->load('vendor', 'lines', 'workOrders.parts', 'workOrders.property', 'workOrders.unit');
        $tech = $receipt->technician ?? $request->user()->technician ?? $receipt->workOrders->first()?->technician;

        return view('receipts.show', [
            'receipt' => $receipt,
            'jobs' => $this->recentJobs($tech)->merge($receipt->workOrders)->unique('id')->sortByDesc('scheduled_start')->values(),
            'vendors' => Vendor::orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request, Receipt $receipt, ReceiptBook $book)
    {
        $this->authorizeReceipt($request, $receipt);
        $data = $request->validate([
            'vendor' => 'nullable|string|max:120', 'purchased_on' => 'nullable|date', 'total' => 'nullable|numeric|min:0',
            'work_orders' => 'required|array|min:1', 'work_orders.*' => 'integer|exists:work_orders,id',
            'lines' => 'array', 'lines.*.id' => 'nullable|integer', 'lines.*.description' => 'nullable|string|max:250',
            'lines.*.quantity' => 'nullable|numeric|min:0', 'lines.*.unit_price' => 'nullable|numeric|min:0', 'lines.*.assign' => 'nullable|string|max:30',
        ]);
        foreach (WorkOrder::whereIn('id', $data['work_orders'])->get() as $wo) {
            $this->authorizeJob($request, $wo);
        }
        $book->save($receipt, $data, $data['lines'] ?? [], array_map('intval', $data['work_orders']));
        $first = $receipt->workOrders()->first();

        return redirect()->to($request->user()->hasRole('admin', 'scheduler') ? route('queue.show', $first) : route('tech.show', $first))
            ->with('status', 'Receipt saved. Part costs updated on '.$receipt->workOrders()->count().' job(s).');
    }

    public function photo(Request $request, Receipt $receipt)
    {
        $this->authorizeReceipt($request, $receipt);
        abort_unless($receipt->photo_path && Storage::disk('local')->exists($receipt->photo_path), 404);

        return Storage::disk('local')->response($receipt->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** A tech's jobs from the last week through tomorrow (what a receipt is likely for). */
    private function recentJobs(?Technician $tech, ?WorkOrder $wo = null): Collection
    {
        $jobs = $tech ? WorkOrder::with('property', 'unit')->where('technician_id', $tech->id)
            ->whereBetween('scheduled_start', [now()->subDays(8), now()->addDays(2)])->orderByDesc('scheduled_start')->get() : collect();

        return $wo ? $jobs->prepend($wo->loadMissing('property', 'unit'))->unique('id')->values() : $jobs;
    }

    private function authorizeJob(Request $request, WorkOrder $wo): void
    {
        $u = $request->user();
        abort_unless($u->hasRole('admin', 'scheduler') || ($u->technician && $wo->technician_id === $u->technician->id), 403);
    }

    private function authorizeReceipt(Request $request, Receipt $receipt): void
    {
        $u = $request->user();
        if ($u->hasRole('admin', 'scheduler') || $receipt->uploaded_by_user_id === $u->id) {
            return;
        }
        abort_unless($u->technician && $receipt->workOrders()->where('technician_id', $u->technician->id)->exists(), 403);
    }
}
