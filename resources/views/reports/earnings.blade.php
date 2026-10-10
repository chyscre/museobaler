@extends('layouts.print')
@section('title', 'Earnings Report')
@section('report-title', 'Earnings and Revenue')
@section('report-meta', $from->format('F j, Y') . ' – ' . $to->format('F j, Y'))

@section('downloads')
  @include('reports.partials.downloads', ['report' => 'earnings', 'params' => [
    'from' => $from->toDateString(),
    'to'   => $to->toDateString(),
  ]])
@endsection

@php $peso = fn ($n) => 'PHP ' . number_format($n, 2); @endphp

@section('content')
<div class="tot">
  <div><span class="k">Net revenue</span><span class="v">{{ $peso($totals['net']) }}</span></div>
  <div><span class="k">Transactions</span><span class="v">{{ number_format($totals['transactions']) }}</span></div>
  <div><span class="k">Individual</span><span class="v">{{ $peso($byPayer['individual']['net']) }}</span></div>
  <div><span class="k">Group</span><span class="v">{{ $peso($byPayer['group']['net']) }}</span></div>
  @if($totals['refunded'] > 0)
    <div><span class="k">Refunded</span><span class="v" style="color:#991b1b">{{ $peso($totals['refunded']) }}</span></div>
  @endif
</div>

<h2>Individual and group</h2>
<table>
  <thead><tr>
    <th>Paid as</th><th style="width:100px">Transactions</th><th style="width:80px">People</th>
    <th style="width:120px">Collected</th><th style="width:110px">Refunded</th><th style="width:120px">Net</th>
  </tr></thead>
  <tbody>
    @foreach($byPayer as $p)
      <tr>
        <td>{{ $p['label'] }}</td>
        <td>{{ number_format($p['transactions']) }}</td>
        <td>{{ number_format($p['headcount']) }}</td>
        <td>{{ $peso($p['collected']) }}</td>
        <td>{{ $p['refunded'] > 0 ? $peso($p['refunded']) : '—' }}</td>
        <td>{{ $peso($p['net']) }}</td>
      </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr>
      <td>Total</td>
      <td>{{ number_format($totals['transactions']) }}</td>
      <td>{{ number_format($totals['headcount']) }}</td>
      <td>{{ $peso($totals['collected']) }}</td>
      <td>{{ $totals['refunded'] > 0 ? $peso($totals['refunded']) : '—' }}</td>
      <td>{{ $peso($totals['net']) }}</td>
    </tr>
  </tfoot>
</table>

<h2>{{ $monthly ? 'Month by month' : 'Day by day' }}</h2>
<table>
  <thead><tr>
    <th>{{ $monthly ? 'Month' : 'Date' }}</th>
    <th style="width:90px">Individual</th><th style="width:110px"></th>
    <th style="width:70px">Group</th><th style="width:110px"></th>
    <th style="width:120px">Net</th>
  </tr></thead>
  <tbody>
    @foreach($periods as $period)
      <tr>
        <td>{{ $period['label'] }}</td>
        <td>{{ $period['individual']['transactions'] ?: '—' }}</td>
        <td>{{ $period['individual']['net'] ? $peso($period['individual']['net']) : '' }}</td>
        <td>{{ $period['group']['transactions'] ?: '—' }}</td>
        <td>{{ $period['group']['net'] ? $peso($period['group']['net']) : '' }}</td>
        <td>{{ $period['all']['net'] ? $peso($period['all']['net']) : '—' }}</td>
      </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr>
      <td>Total</td>
      <td>{{ number_format($byPayer['individual']['transactions']) }}</td>
      <td>{{ $peso($byPayer['individual']['net']) }}</td>
      <td>{{ number_format($byPayer['group']['transactions']) }}</td>
      <td>{{ $peso($byPayer['group']['net']) }}</td>
      <td>{{ $peso($totals['net']) }}</td>
    </tr>
  </tfoot>
</table>

<h2>Transactions</h2>
<table>
  <thead><tr>
    <th style="width:140px">Transaction No.</th><th style="width:118px">When</th><th>Paid by</th>
    <th style="width:78px">Paid as</th><th style="width:52px">People</th>
    <th style="width:105px">Amount</th><th style="width:120px">Recorded by</th>
  </tr></thead>
  <tbody>
    @forelse($payments as $p)
      <tr>
        <td>{{ $p->number }}</td>
        <td>{{ $p->recorded_at->format('M j, g:i A') }}</td>
        <td>
          {{ $p->payer_name ?: '—' }}
          <span style="color:#78716c">· {{ $p->visitor_type }}</span>
          @if($p->breakdown_summary)
            <div style="color:#78716c;font-size:90%">{{ $p->breakdown_summary }}</div>
          @endif
        </td>
        <td>{{ $p->payer === 'group' ? 'Group' : 'Individual' }}</td>
        <td>{{ $p->headcount }}</td>
        <td @if($p->kind === 'refund') style="color:#991b1b" @endif>
          {{ $p->kind === 'refund' ? '− ' : '' }}{{ $peso($p->amount) }}
          @if($p->kind === 'refund') <span class="tag t-red">Refund</span> @endif
        </td>
        <td>{{ $p->recordedBy?->name ?? ($p->backfilled ? 'Earlier record' : '—') }}</td>
      </tr>
    @empty
      <tr><td colspan="7">No admission payments in this range.</td></tr>
    @endforelse
  </tbody>
</table>

@if($backfilled > 0)
  <p class="note">
    {{ $backfilled }} {{ Str::plural('transaction', $backfilled) }} marked "Earlier record" were rebuilt from
    visitor and group records when the payments ledger was introduced. A returning visitor's earlier payments
    from before then could not be recovered, so totals for that period may be lower than what was collected.
  </p>
@endif

<p class="note">Generated {{ now()->format('F j, Y g:i A') }}{{ auth()->check() ? ' by ' . auth()->user()->name : '' }}.</p>
@endsection
