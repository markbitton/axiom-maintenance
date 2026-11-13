<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Services\Billing\Invoicer;
use Illuminate\Http\Request;
use RuntimeException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');

        return view('invoices.index', [
            'invoices' => Invoice::with('workOrder.property', 'workOrder.unit')
                ->when(in_array($status, ['draft', 'sent', 'paid', 'void'], true), fn ($q) => $q->where('status', $status))
                ->latest()->paginate(50)->withQueryString(),
            'status' => $status,
            'totals' => Invoice::where('status', '!=', 'void')->selectRaw('status, count(*) as n, sum(total) as amount')->groupBy('status')->get()->keyBy('status'),
        ]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load('lines', 'workOrder.property', 'workOrder.unit', 'workOrder.tenant', 'workOrder.location', 'createdBy');

        return view('invoices.show', ['invoice' => $invoice, 'tz' => $invoice->workOrder->location->timezone ?? 'UTC']);
    }

    public function store(Request $request, WorkOrder $workOrder, Invoicer $invoicer)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);
        try {
            $invoice = $invoicer->create($workOrder, $request->user(), $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('invoices.show', $invoice)->with('status', "Invoice {$invoice->number} created.");
    }

    public function status(Request $request, Invoice $invoice, Invoicer $invoicer)
    {
        $data = $request->validate(['status' => 'required|in:sent,paid,void']);
        abort_if($invoice->status === 'void', 422, 'This invoice is void.');
        match ($data['status']) {
            'void' => $invoicer->void($invoice, $request->user()),
            'sent' => $invoice->update(['status' => 'sent', 'sent_at' => now()]),
            'paid' => $invoice->update(['status' => 'paid', 'paid_at' => now(), 'sent_at' => $invoice->sent_at ?? now()]),
        };

        return back()->with('status', "Invoice {$invoice->number} marked {$data['status']}.".($data['status'] === 'void' ? ' Its time and parts can be billed again.' : ''));
    }
}
