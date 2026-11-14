@extends('layouts.app')
@section('title', $invoice->number)
@section('content')
@php
    $wo = $invoice->workOrder;
    $order = ['fee' => 0, 'labor' => 1, 'travel' => 2, 'part' => 3];
    $lines = $invoice->lines->sortBy(fn ($l) => $order[$l->kind] ?? 9)->groupBy('kind');
    $headings = ['fee' => 'Service call', 'labor' => 'Labor', 'travel' => 'Travel', 'part' => 'Parts & supplies'];
    $money = fn ($v) => '$'.number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp
<style>
    .inv { background:#fff; border:1px solid var(--line); border-radius:14px; overflow:hidden; max-width:860px; margin:0 auto; box-shadow:0 6px 24px rgba(15,42,74,.08); }
    .inv-head { background:#0f2a4a; color:#fff; padding:26px 30px; display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; }
    .inv-brand { display:flex; align-items:center; gap:14px; }
    .inv-brand img { width:64px; height:64px; }
    .inv-brand b { display:block; font-size:21px; letter-spacing:.3px; }
    .inv-brand span { color:#cbd5e1; font-size:13px; }
    .inv-title { text-align:right; }
    .inv-title h1 { margin:0; font-size:30px; letter-spacing:4px; color:#f59e0b; font-weight:800; }
    .inv-title div { color:#cbd5e1; font-size:13px; }
    .inv-stripe { height:5px; background:#f59e0b; }
    .inv-body { padding:26px 30px; }
    .inv-meta { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:18px; margin-bottom:22px; }
    .inv-meta h4 { margin:0 0 4px; font-size:11px; letter-spacing:1px; text-transform:uppercase; color:var(--muted); }
    .inv-meta p { margin:0; }
    .inv table { width:100%; border-collapse:collapse; }
    .inv th { background:#f1f5f9; color:#334155; font-size:11px; letter-spacing:.8px; padding:9px 10px; }
    .inv td { padding:9px 10px; border-bottom:1px solid #eef2f7; }
    .inv .num { text-align:right; white-space:nowrap; }
    .inv .group td { background:#fff; color:#0f2a4a; font-weight:700; font-size:12px; letter-spacing:.6px; text-transform:uppercase; padding-top:16px; border-bottom:2px solid #f59e0b; }
    .inv-totals { margin:18px 0 0 auto; width:300px; max-width:100%; }
    .inv-totals div { display:flex; justify-content:space-between; padding:5px 0; color:#475569; }
    .inv-totals .grand { border-top:2px solid #0f2a4a; margin-top:6px; padding-top:10px; color:#0f2a4a; font-size:20px; font-weight:800; }
    .inv-status { display:inline-block; padding:3px 12px; border-radius:99px; font-size:12px; font-weight:700; letter-spacing:.8px; text-transform:uppercase; }
    .inv-status.paid { background:#d1fae5; color:#047857; } .inv-status.sent { background:#dbeafe; color:#1d4ed8; }
    .inv-status.draft { background:#f1f5f9; color:#475569; } .inv-status.void { background:#fee2e2; color:#b91c1c; }
    .inv-notes { margin-top:22px; padding:14px 16px; background:#f8fafc; border-left:4px solid #f59e0b; border-radius:6px; font-size:14px; }
    .inv-foot { padding:16px 30px; background:#f8fafc; color:var(--muted); font-size:13px; text-align:center; border-top:1px solid var(--line); }
    @media print { .inv { box-shadow:none; border:0; } .inv-head, .inv-stripe, .inv th, .inv-status { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>

<div class="row no-print" style="margin-bottom:12px;max-width:860px;margin-left:auto;margin-right:auto">
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

<div class="inv">
    <div class="inv-head">
        <div class="inv-brand">
            <img src="/img/ams-logo.svg" alt="AMS">
            <div><b>Axiom Maintenance Services</b><span>{{ $wo->location?->office_address ?: 'Property maintenance & repair' }}</span></div>
        </div>
        <div class="inv-title">
            <h1>INVOICE</h1>
            <div>{{ $invoice->number }}</div>
        </div>
    </div>
    <div class="inv-stripe"></div>
    <div class="inv-body">
        <div class="inv-meta">
            <div><h4>Bill to</h4><p><b>{{ $invoice->bill_to }}</b></p></div>
            <div><h4>Service address</h4><p>{{ $wo->displayAddress() }}</p></div>
            <div>
                <h4>Details</h4>
                <p>Date: {{ $invoice->created_at->setTimezone($tz)->format('M j, Y') }}</p>
                <p>Work order: {{ $wo->reference }} · {{ $wo->tradeLabel() }}</p>
                <p style="margin-top:6px"><span class="inv-status {{ $invoice->status }}">{{ $invoice->status }}</span>
                    @if($invoice->paid_at) <span class="muted">{{ $invoice->paid_at->setTimezone($tz)->format('M j') }}</span>@endif</p>
            </div>
        </div>

        <table>
            <tr><th style="text-align:left">Description</th><th class="num">Qty / hrs</th><th class="num">Rate</th><th class="num">Amount</th></tr>
            @foreach($lines as $kind => $group)
                <tr class="group"><td colspan="4">{{ $headings[$kind] ?? ucfirst($kind) }}</td></tr>
                @foreach($group as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="num">{{ $qty($line->quantity) }}</td>
                        <td class="num">{{ $money($line->unit_price) }}</td>
                        <td class="num">{{ $money($line->amount) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table>

        <div class="inv-totals">
            @foreach(['fees_total' => 'Service call', 'labor_total' => 'Labor', 'travel_total' => 'Travel', 'parts_total' => 'Parts & supplies'] as $k => $label)
                @if((float) $invoice->$k > 0)<div><span>{{ $label }}</span><span>{{ $money($invoice->$k) }}</span></div>@endif
            @endforeach
            <div class="grand"><span>{{ $invoice->status === 'paid' ? 'Paid' : 'Amount due' }}</span><span>{{ $money($invoice->total) }}</span></div>
        </div>

        @if($invoice->notes || $wo->completion_notes)
            <div class="inv-notes">
                @if($invoice->notes)<p style="margin:0 0 6px"><b>Note:</b> {{ $invoice->notes }}</p>@endif
                @if($wo->completion_notes)<p style="margin:0;white-space:pre-line"><b>Work performed:</b> {{ $wo->completion_notes }}</p>@endif
            </div>
        @endif
    </div>
    <div class="inv-foot">Thank you for your business. &nbsp;·&nbsp; Axiom Maintenance Services</div>
</div>
@endsection
