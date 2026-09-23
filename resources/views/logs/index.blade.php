@extends('layouts.admin')
@section('title','Logs — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Logs</h2>
    <p>System activity and user actions</p>
  </div>
  <div class="ph-right">
    {{-- The audit export is the Tourism office's, since they are who answers
         for the trail upward. Museum staff can still read the log. --}}
    @if(auth()->user()->isTourismHead())
      @include('partials.report-menu', [
        'id'      => 'logsReportMenu',
        'mode'    => 'range',
        'reports' => [
          ['label' => 'Audit trail (CSV)', 'url' => route('reports.audit.export', ['format' => 'csv']), 'download' => true],
          ['label' => 'Audit trail (PDF)', 'url' => route('reports.audit.export', ['format' => 'pdf'])],
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
    <input type="date" class="date-in" name="from" value="{{ request('from') }}" title="From date">
    <input type="date" class="date-in" name="to"   value="{{ request('to') }}"   title="To date">
    <button type="submit" class="btn btn-green btn-sm" title="Filter">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filter
    </button>
    @if(request()->hasAny(['search','action','user','from','to']))
    <a href="{{ route('logs.index') }}" class="btn btn-outline btn-sm" title="Clear filters">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Clear
    </a>
    @endif
    <span class="fcount">{{ number_format($logs->total()) }} / {{ number_format($total) }} entries</span>
  </div>
</form>

<div class="tbl-wrap">
  <table id="logsTable">
    <thead><tr>
      <th>Date / Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP Address</th>
    </tr></thead>
    <tbody>
    @forelse($logs as $log)
    <tr>
      <td style="color:var(--text-3);font-size:12px;white-space:nowrap">{{ \Carbon\Carbon::parse($log->created_at)->format('Y-m-d H:i') }}</td>
      <td><span class="badge b-gray">{{ $log->user_name ?? 'System' }}</span></td>
      {{-- Historic rows can still carry roles that no longer exist (Curator
           was removed), so this falls through to the neutral badge. --}}
      <td><span class="badge {{ $log->role==='TourismHead'?'b-purple':($log->role==='Administrator'?'b-gold':'b-gray') }}">{{ $log->role ?? '—' }}</span></td>
      <td>{{ $log->action }}</td>
      <td style="font-size:12px;color:var(--text-3)">{{ $log->details }}</td>
      <td style="font-size:12px;color:var(--text-4)">{{ $log->ip_address ?? '—' }}</td>
    </tr>
    @empty
    <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--text-3)">No logs found.</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="tbl-foot">
    <span class="tbl-count">{{ $logs->total() }} entries</span>
    <div>{{ $logs->links() }}</div>
  </div>
</div>
@endsection

@push('scripts')
<script>
function exportLogsCSV(){
  const rows=[['Date/Time','User','Role','Action','Details','IP']];
  document.querySelectorAll('#logsTable tbody tr').forEach(tr=>{
    rows.push(Array.from(tr.querySelectorAll('td')).map(td=>'"'+td.textContent.trim().replace(/"/g,'""')+'"'));
  });
  const csv=rows.map(r=>r.join(',')).join('\n');
  const a=document.createElement('a');
  a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
  a.download='logs_'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
}
</script>
@endpush
