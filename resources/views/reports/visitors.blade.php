@extends('layouts.print')
@section('title', 'Visitor Report')
@section('report-title', 'Visitors and Admission')
@section('report-meta', $from->format('F j, Y') . ' – ' . $to->format('F j, Y'))

@section('content')
<div class="tot">
  <div><span class="k">Total people</span><span class="v">{{ number_format($totalHeads) }}</span></div>
  <div><span class="k">Collected</span><span class="v">PHP {{ number_format($totalMoney, 2) }}</span></div>
  @if($outstanding > 0)
    <div><span class="k">Outstanding</span><span class="v" style="color:#991b1b">PHP {{ number_format($outstanding, 2) }}</span></div>
  @endif
  <div><span class="k">Daily average</span><span class="v">{{ $daily->count() ? round($totalHeads / $daily->count(), 1) : 0 }}</span></div>
</div>

<h2>By visitor type</h2>
<table>
  <thead><tr><th>Type</th><th style="width:120px">People</th><th style="width:120px">Share</th></tr></thead>
  <tbody>
    @foreach($byType as $type => $count)
      <tr>
        <td>{{ $type }}</td>
        <td>{{ number_format($count) }}</td>
        <td>{{ $totalHeads ? round($count / $totalHeads * 100, 1) : 0 }}%</td>
      </tr>
    @endforeach
  </tbody>
</table>

<h2>How they registered</h2>
{{-- The point of this table is to watch the paper book die: as "app" grows
     and "desk" shrinks, visitors are entering their own details from the
     entrance poster and the desk has stopped transcribing. --}}
<table>
  <thead><tr><th>Source</th><th style="width:120px">Entries</th></tr></thead>
  <tbody>
    @forelse($bySource as $source => $count)
      <tr>
        <td>
          {{ ucfirst($source) }}
          <span style="color:#78716c">
            @switch($source)
              @case('app')       — visitor's own phone @break
              @case('kiosk')     — counter tablet (retired) @break
              @case('desk')      — typed in by staff @break
              @case('recovered') — paper slip, keyed in later @break
            @endswitch
          </span>
        </td>
        <td>{{ number_format($count) }}</td>
      </tr>
    @empty
      <tr><td colspan="2">No individual registrations in this range.</td></tr>
    @endforelse
  </tbody>
</table>

<h2>Day by day</h2>
<table>
  <thead><tr><th>Date</th><th style="width:120px">People</th><th style="width:140px">Collected</th></tr></thead>
  <tbody>
    @foreach($daily as $day)
      <tr>
        <td>{{ $day['date']->format('D, M j') }}</td>
        <td>{{ number_format($day['headcount']) }}</td>
        <td>{{ $day['collected'] > 0 ? 'PHP ' . number_format($day['collected'], 2) : '—' }}</td>
      </tr>
    @endforeach
  </tbody>
  <tfoot>
    <tr>
      <td>Total</td>
      <td>{{ number_format($totalHeads) }}</td>
      <td>PHP {{ number_format($totalMoney, 2) }}</td>
    </tr>
  </tfoot>
</table>

<p class="note">Generated {{ now()->format('F j, Y g:i A') }} by {{ auth()->user()->name }}.</p>
@endsection
