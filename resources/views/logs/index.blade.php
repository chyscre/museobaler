@extends('layouts.admin')
@section('title','Logs — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Logs</h2>
  </div>
  <div class="ph-right">
    {{-- The audit export is the Tourism office's, since they are who answers
         for the trail upward. Museum staff can still read the log. --}}
    @if(auth()->user()->isTourismHead())
      @include('partials.report-menu', [
        'id'      => 'logsReportMenu',
        'mode'    => 'range',
        'reports' => [
          ['label' => 'Audit trail (CSV)', 'note' => 'Spreadsheet of every action', 'url' => route('reports.audit.export', ['format' => 'csv']), 'download' => true],
          ['label' => 'Audit trail (PDF)', 'note' => 'Printable, for filing',        'url' => route('reports.audit.export', ['format' => 'pdf'])],
        ],
      ])
    @endif
  </div>
</div>

<form method="GET" action="{{ route('logs.index') }}">
  <div class="fbar">
    <div class="search-box">
      <svg class="si" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Search user, action, details…">
    </div>
    <x-fctl icon="activity">
      <select class="fsel" name="action" onchange="this.form.submit()">
        <option value="">All Actions</option>
        @foreach($actions as $a)
        <option {{ request('action')===$a?'selected':'' }}>{{ $a }}</option>
        @endforeach
      </select>
    </x-fctl>
    <x-fctl icon="user">
      <select class="fsel" name="user" onchange="this.form.submit()">
        <option value="">All Users</option>
        @foreach($users as $u)
        <option {{ request('user')===$u?'selected':'' }}>{{ $u }}</option>
        @endforeach
      </select>
    </x-fctl>
    {{-- No date boxes and no Filter button: the day is chosen on the bar
         below, the lists apply as soon as they change, and the search runs
         on Enter. --}}
    @if(request()->hasAny(['search','action','user']))
    <a href="{{ route('logs.index') }}" class="btn btn-outline btn-sm" title="Clear filters">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear
    </a>
    @endif
    {{-- The day's own count is on the day bar below. --}}
    <span class="fcount">{{ number_format($total) }} total</span>
  </div>
</form>

<x-day-nav :day="$day" unit="entry" />

<div class="tbl-wrap">
  <table id="logsTable">
    <thead><tr>
      <th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP Address</th>
    </tr></thead>
    <tbody>
    @forelse($logs as $log)
    @php($at = \Carbon\Carbon::parse($log->created_at))
    <tr>
      {{-- The time only: a page is one day, and the day bar above already
           names it. The full date is on hover for anyone copying a row out. --}}
      <td style="color:var(--text-3);font-size:12px;white-space:nowrap"><time datetime="{{ $at->toIso8601String() }}" title="{{ $at->format('M j, Y · g:i A') }}">{{ $at->format('g:i A') }}</time></td>
      <td><span class="badge b-gray">{{ $log->user_name ?? 'System' }}</span></td>
      {{-- Historic rows can still carry roles that no longer exist (Curator
           was removed), so this falls through to the neutral badge. --}}
      <td><span class="badge {{ $log->role==='TourismHead'?'b-purple':($log->role==='Administrator'?'b-gold':'b-gray') }}">{{ $log->role ?? '—' }}</span></td>
      <td>{{ $log->display_action }}</td>
      <td style="font-size:12px;color:var(--text-3)">{{ $log->display_details }}</td>
      <td style="font-size:12px;color:var(--text-4)">{{ $log->ip_address ?? '—' }}</td>
    </tr>
    @empty
    <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-3)">Nothing was recorded on this day.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
