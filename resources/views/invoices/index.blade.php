@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
<h2>Invoices</h2>
<div class="grid" style="margin-bottom:14px">
    @foreach(['draft' => 'Drafts', 'sent' => 'Sent, unpaid', 'paid' => 'Paid'] as $k => $label)
        <a class="card stat" href="?status={{ $k }}" style="color:inherit;margin:0">{{ $label }}<b>${{ number_format((float) ($totals[$k]->amount ?? 0), 2) }}</b><span class="muted">{{ (int) ($totals[$k]->n ?? 0) }} invoices</span></a>
    @endforeach
</div>
<div class="card">
    <div class="row" style="margin-bottom:8px">
        <div class="muted">Showing {{ $status ? $status : 'all' }} · <a href="{{ route('invoices') }}">all</a> · <a href="?status=void">void</a></div>
    </div>
    <table>
        <tr><th>Invoice</th><th>Job</th><th class="hide-sm">Bill to</th><th>Status</th><th style="text-align:right">Total</th></tr>
        @forelse($invoices as $inv)
            <tr>
                <td><a href="{{ route('invoices.show', $inv) }}">{{ $inv->number }}</a><div class="muted">{{ $inv->created_at->format('M j, Y') }}</div></td>
                <td><a href="{{ route('queue.show', $inv->workOrder) }}">{{ $inv->workOrder->reference }}</a><div class="muted">{{ $inv->workOrder->property?->name }} {{ $inv->workOrder->unit?->name }}</div></td>
                <td class="hide-sm">{{ $inv->bill_to }}</td>
                <td><span class="pill {{ $inv->status === 'paid' ? 'ok' : ($inv->status === 'void' ? 'emergency' : '') }}">{{ $inv->status }}</span></td>
                <td style="text-align:right">${{ number_format((float) $inv->total, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No invoices yet. Open a finished work order and click "Create invoice".</td></tr>
        @endforelse
    </table>
    {{ $invoices->links() }}
</div>
@endsection
