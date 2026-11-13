@extends('layouts.app')
@section('title', $invoice->number)
@section('content')
@php($wo = $invoice->workOrder)
<div class="row no-print" style="margin-bottom:10px">
    <div><a href="{{ route('queue.show', $wo) }}">&larr; {{ $wo->reference }}</a></div>
    <div style="flex:3;display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
        <button class="secondary" onclick="window.print()">Print / PDF</button>
        @if($invoice->status === 'draft')
            <form method="post" action="{{ route('invoices.status', $invoice) }}">@csrf<input type="hidden" name="status" value="sent"><button>Mark sent</button></form>
        @endif
        @if(in_array($invoice->status, ['draft', 'sent']))
            <form method="post" action="{{ route('invoices.status', $invoice) }}">@csrf<input type="hidden" name="status" value="paid"><button>Mark paid</button></form>
        @endif
        @if($invoice->status !== 'void')
            <form method="post" action="{{ route('invoices.status', $invoice) }}" onsubmit="return confirm('Void this invoice? Its time and parts go back to unbilled.')">@csrf<input type="hidden" name="status" value="void"><button class="danger">Void</button></form>
        @endif
    </div>
</div>
<div class="card">
    <div class="row" style="align-items:start">
        <div>
            <h2 style="margin:0">Axiom Maintenance</h2>
            <div class="muted">Invoice {{ $invoice->number }} · {{ $invoice->created_at->setTimezone($tz)->format('M j, Y') }}</div>
            <span class="pill {{ $invoice->status === 'paid' ? 'ok' : ($invoice->status === 'void' ? 'emergency' : '') }}">{{ $invoice->status }}</span>
        </div>
        <div style="text-align:right">
            <b>Bill to</b><div>{{ $invoice->bill_to }}</div>
            <div class="muted">{{ $wo->reference }} · {{ $wo->displayAddress() }}</div>
            <div class="muted">{{ $wo->tradeLabel() }}</div>
        </div>
    </div>
    <table style="margin-top:14px">
        <tr><th>Item</th><th style="text-align:right">Qty / hrs</th><th style="text-align:right">Rate</th><th style="text-align:right">Amount</th></tr>
        @foreach($invoice->lines->sortBy(fn ($l) => array_search($l->kind, ['fee', 'labor', 'travel', 'part'])) as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td style="text-align:right">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</td>
                <td style="text-align:right">${{ number_format((float) $line->unit_price, 2) }}</td>
                <td style="text-align:right">${{ number_format((float) $line->amount, 2) }}</td>
            </tr>
        @endforeach
        @foreach(['fees_total' => 'Service call', 'labor_total' => 'Labor', 'travel_total' => 'Travel', 'parts_total' => 'Parts & supplies'] as $k => $label)
            @if((float) $invoice->$k > 0)
                <tr><td colspan="3" style="text-align:right" class="muted">{{ $label }}</td><td style="text-align:right">${{ number_format((float) $invoice->$k, 2) }}</td></tr>
            @endif
        @endforeach
        <tr><td colspan="3" style="text-align:right"><b>Total</b></td><td style="text-align:right"><b>${{ number_format((float) $invoice->total, 2) }}</b></td></tr>
    </table>
    @if($invoice->notes)<p>{{ $invoice->notes }}</p>@endif
    @if($wo->completion_notes)<p class="muted">Work done: {{ $wo->completion_notes }}</p>@endif
</div>
@endsection
