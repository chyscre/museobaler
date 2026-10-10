@extends('layouts.admin')
@section('title','Activity Log — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Activity Log</h2>
    <p>Showing <strong>{{ $period->label() }}</strong></p>
  </div>
  <div class="ph-right">
    <x-period-bar :period="$period" :action="route('logs.index')" :keep="request()->only(['search', 'action', 'user'])" />
    {{-- The audit export is the Tourism office's, since they are who answers
         for the trail upward. Museum staff can still read the log. --}}
    @if(auth()->user()->isTourismHead())
      @include('partials.report-menu', [
        'id'      => 'logsReportMenu',
        'mode'    => 'range',
        'from'    => $period->from,
        'to'      => $period->to->copy()->min(today()),
        'reports' => [
          ['label' => 'Activity Log (CSV)', 'note' => 'Spreadsheet of every action', 'url' => route('reports.audit.export', ['format' => 'csv']), 'download' => true],
          ['label' => 'Activity Log (PDF)', 'note' => 'Printable, for filing',        'url' => route('reports.audit.export', ['format' => 'pdf'])],
        ],
      ])
    @endif
  </div>
</div>

<form method="GET" action="{{ route('logs.index') }}">
  <x-period-fields :period="$period" />
  <div class="fbar">
    <div class="search-box">
      <svg class="si" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Search user, action, details…">
    </div>
    <x-fctl icon="activity">
      <select class="fsel" name="action" onchange="this.form.submit()">
        <option value="">All Actions</option>
        {{-- In labelled sections, Exhibits first (AuditTrail::category). --}}
        @foreach($actions as $category => $kinds)
        <optgroup label="{{ $category }}">
          @foreach($kinds as $a)
          <option {{ request('action')===$a?'selected':'' }}>{{ $a }}</option>
          @endforeach
        </optgroup>
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
    {{-- No date boxes and no Filter button: the period is chosen on the
         bar above, the lists apply as soon as they change, and the search
         runs on Enter. --}}
    @if(request()->hasAny(['search','action','user']))
    <a href="{{ route('logs.index', $period->query()) }}" class="btn btn-outline btn-sm" title="Clear filters">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear
    </a>
    @endif
    {{-- The period's own count is under the table. --}}
    <span class="fcount">{{ number_format($total) }} total</span>
  </div>
</form>

<div class="tbl-wrap">
  <table id="logsTable" data-dt data-dt-length="50">
    <thead><tr>
      <th>Date &amp; Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP Address</th>
    </tr></thead>
    <tbody>
    @forelse($logs as $log)
    @php($at = \Carbon\Carbon::parse($log->created_at))
    <tr>
      {{-- Date and time: a period spans many days. --}}
      <td data-order="{{ $at->timestamp }}" style="color:var(--text-3);font-size:12px;white-space:nowrap"><time datetime="{{ $at->toIso8601String() }}">{{ $at->format('M j, Y') }}<div style="font-size:11px;color:var(--text-4)">{{ $at->format('g:i A') }}</div></time></td>
      <td><span class="badge b-gray">{{ $log->user_name ?? 'System' }}</span></td>
      {{-- Historic rows can still carry roles that no longer exist (Curator
           was removed), so this falls through to the neutral badge. --}}
      <td><span class="badge {{ $log->role==='TourismHead'?'b-purple':($log->role==='Administrator'?'b-gold':'b-gray') }}">{{ $log->role ?? '—' }}</span></td>
      <td>{{ $log->display_action }}</td>
      <td style="font-size:12px;color:var(--text-3)">{{ $log->display_details }}</td>
      <td style="font-size:12px;color:var(--text-4)">{{ $log->ip_address ?? '—' }}</td>
    </tr>
    @empty
    <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-3)">Nothing was recorded in this period.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
