@extends('layouts.admin')
@section('title', 'Staff Attendance — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Staff Attendance</h2>
  </div>
  <div class="ph-right" style="display:flex;gap:8px;align-items:center">
    {{-- Everyone at once, over any period: the summary sheet that goes on
         top of the individual DTRs. Defaults to the month being looked at. --}}
    <div style="position:relative;display:inline-block">
      <button type="button" class="btn btn-green btn-sm" onclick="toggleReportMenu('allReportMenu')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print All Staff Attendance
      </button>
      <div id="allReportMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);width:270px;background:var(--surface);border:1.5px solid var(--border);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.14);z-index:900;padding:14px">
        <label style="display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">From</label>
        <div style="margin-bottom:10px">
          <x-date-field id="allFrom" :value="$date->copy()->startOfMonth()" block label="From" :max="today()->toDateString()" />
        </div>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">To</label>
        <div style="margin-bottom:12px">
          <x-date-field id="allTo" :value="$date->copy()->endOfMonth()->min(today())" block label="To" :max="today()->toDateString()" />
        </div>
        <button type="button" class="btn btn-green btn-sm" style="width:100%;justify-content:center" onclick="openAllAttendance()">Open printable summary</button>
      </div>
    </div>
    {{-- The DTR is per person per month, so it is picked here rather than
         living on a separate reports page. --}}
    <div style="position:relative;display:inline-block">
      <button type="button" class="btn btn-outline btn-sm" onclick="toggleReportMenu('dtrReportMenu')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print DTR
      </button>
      <div id="dtrReportMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);width:270px;background:var(--surface);border:1.5px solid var(--border);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.14);z-index:900;padding:14px">
        <label style="display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Staff member</label>
        <select id="dtrStaff" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface);margin-bottom:10px">
          @foreach($board as $row)
            <option value="{{ $row['staff']->staff_id }}">{{ $row['staff']->name }}</option>
          @endforeach
        </select>
        <label style="display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Month</label>
        <div style="margin-bottom:12px">
          <x-date-field type="month" id="dtrMonth" :value="$date" block label="Month" :max="today()->format('Y-m')" />
        </div>
        <button type="button" class="btn btn-green btn-sm" style="width:100%;justify-content:center" onclick="openDtr()">Open printable DTR</button>
      </div>
    </div>
    <a href="{{ route('attendance.kiosk') }}" target="_blank" class="btn btn-outline btn-sm">Open staff-room screen</a>
    <a href="{{ route('corrections.index') }}" class="btn btn-outline btn-sm">
      Corrections @if($pendingCorrections)<span class="badge b-red" style="margin-left:4px">{{ $pendingCorrections }}</span>@endif
    </a>
    <form method="GET">
      <x-date-field name="date" :value="$date" submit label="Day shown" :max="today()->toDateString()" />
    </form>
  </div>
</div>

<div class="stats-row" style="margin-bottom:22px">
  <div class="stat">
    <div class="stat-ico green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div><div class="stat-val">{{ $counts['present'] }}</div><div class="stat-lbl">Present @if($counts['present_rate'] !== null)· {{ $counts['present_rate'] }}% @endif</div></div>
  </div>
  <div class="stat">
    <div class="stat-ico red">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    </div>
    <div><div class="stat-val">{{ $counts['absent'] }}</div><div class="stat-lbl">Absent @if($counts['absent_rate'] !== null)· {{ $counts['absent_rate'] }}% @endif</div></div>
  </div>
</div>

<div class="card card-p-lg">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <div>
      <h3 class="sec-title" style="margin-bottom:0">{{ $date->format('l, F j, Y') }}</h3>
      <p style="font-size:12px;color:var(--text-3)">{{ $board->count() }} active staff</p>
    </div>
  </div>

  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1.5px solid var(--border)">
        @foreach(['Staff','Role','Shift','Status',''] as $h)
          <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($board as $row)
        <tr style="border-bottom:1px solid var(--border-light)">
          <td style="padding:10px;font-size:13px;font-weight:600;color:var(--text)">{{ $row['staff']->name }}</td>
          <td style="padding:10px;font-size:12px;color:var(--text-3)">{{ $row['staff']->role_label }}</td>
          <td style="padding:10px;font-size:12px;color:var(--text-3)">
            @if($row['schedule'] && !$row['schedule']->is_rest_day)
              {{ \Carbon\Carbon::parse($row['schedule']->shift_start)->format('g:i A') }}–{{ \Carbon\Carbon::parse($row['schedule']->shift_end)->format('g:i A') }}
            @elseif($row['schedule'])
              Rest day
            @else
              <span style="color:var(--text-3)">Not set</span>
            @endif
          </td>
          <td style="padding:10px">
            <span class="badge {{ $row['status'] === 'Present' ? 'b-green' : 'b-red' }}"
                  @if($row['in']) title="Checked in {{ $row['in']->scanned_at->format('g:i A') }}" @endif>
              {{ $row['status'] }}
            </span>
            @if($row['is_manual'])
              {{-- Flagged everywhere it appears so a hand-entered day can
                   never quietly pass as a scanned one. --}}
              <span class="badge b-gray" title="Entered by hand, approved by the Tourism office">Manual</span>
            @endif
          </td>
          <td style="padding:10px;text-align:right;white-space:nowrap">
            <a href="{{ route('staff-attendance.show', $row['staff']) }}" class="btn btn-outline btn-xs">Month</a>
            @if(auth()->user()->isTourismHead())
              <a href="{{ route('staff-attendance.schedule', $row['staff']) }}" class="btn btn-outline btn-xs">Shift</a>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endsection

@push('scripts')
<script>
  // Mirrors the shared report menu's toggle so this page behaves the same as
  // Records, Feedback and Logs without pulling in the whole partial for a
  // menu whose fields are a staff member and a month rather than a date range.
  function toggleReportMenu(id) {
    var el = document.getElementById(id);
    var open = el.style.display === 'block';
    document.querySelectorAll('[id$="ReportMenu"]').forEach(function (m) { m.style.display = 'none'; });
    el.style.display = open ? 'none' : 'block';
  }

  function openDtr() {
    var id    = document.getElementById('dtrStaff').value;
    var month = document.getElementById('dtrMonth').value;
    window.open('{{ url('/reports/dtr') }}/' + id + '?month=' + encodeURIComponent(month), '_blank');
    document.getElementById('dtrReportMenu').style.display = 'none';
  }

  function openAllAttendance() {
    var from = document.getElementById('allFrom').value;
    var to   = document.getElementById('allTo').value;
    window.open('{{ route('reports.staff-attendance') }}?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), '_blank');
    document.getElementById('allReportMenu').style.display = 'none';
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[onclick^="toggleReportMenu"]') || e.target.closest('[id$="ReportMenu"]')) return;
    document.querySelectorAll('[id$="ReportMenu"]').forEach(function (m) { m.style.display = 'none'; });
  });
</script>
@endpush
