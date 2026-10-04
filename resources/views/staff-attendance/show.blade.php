@extends('layouts.admin')
@section('title', $staff->name . ' — Attendance')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>{{ $staff->name }}</h2>
    <p>{{ $staff->role_label }} · {{ $month->format('F Y') }}</p>
  </div>
  <div class="ph-right" style="display:flex;gap:8px;align-items:center">
    <a href="{{ route('reports.dtr', $staff) }}?month={{ $month->format('Y-m') }}" target="_blank" class="btn btn-green btn-sm">Print DTR</a>
    @if(auth()->user()->isTourismHead())
      <a href="{{ route('staff-attendance.schedule', $staff) }}" class="btn btn-outline btn-sm">Edit shift</a>
    @endif
    <form method="GET">
      <x-date-field type="month" name="month" :value="$month" submit label="Month"
                    :max="today()->format('Y-m')" />
    </form>
  </div>
</div>

<div class="stats-row" style="margin-bottom:22px">
  <div class="stat"><div class="stat-ico green"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
    <div><div class="stat-val">{{ $totals['present'] }}</div><div class="stat-lbl">Present @if($totals['present_rate'] !== null)· {{ $totals['present_rate'] }}% @endif</div></div></div>
  <div class="stat"><div class="stat-ico red"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
    <div><div class="stat-val">{{ $totals['absent'] }}</div><div class="stat-lbl">Absent @if($totals['absent_rate'] !== null)· {{ $totals['absent_rate'] }}% @endif</div></div></div>
</div>

@if($totals['manual'] > 0)
  <div class="alert" style="background:#fffbeb;color:#92400e;margin-bottom:18px">
    {{ $totals['manual'] }} {{ \Illuminate\Support\Str::plural('day', $totals['manual']) }} this month were entered by hand rather than scanned.
  </div>
@endif

<div class="card card-p-lg">
  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1.5px solid var(--border)">
        @foreach(['Date','Shift','In','Out','Worked','Status'] as $h)
          <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($days as $day)
        <tr style="border-bottom:1px solid var(--border-light);{{ $day['date']->isWeekend() ? 'background:var(--border-light)' : '' }}">
          <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $day['date']->format('D, M j') }}</td>
          <td style="padding:10px;font-size:12px;color:var(--text-3)">
            @if($day['schedule'] && !$day['schedule']->is_rest_day)
              {{ \Carbon\Carbon::parse($day['schedule']->shift_start)->format('g:i A') }}–{{ \Carbon\Carbon::parse($day['schedule']->shift_end)->format('g:i A') }}
            @elseif($day['schedule']) Rest day
            @else — @endif
          </td>
          <td style="padding:10px;font-size:13px">{{ $day['in']?->scanned_at->format('g:i A') ?? '—' }}</td>
          <td style="padding:10px;font-size:13px">{{ $day['out']?->scanned_at->format('g:i A') ?? '—' }}</td>
          <td style="padding:10px;font-size:13px">
            @if($day['worked_minutes'] !== null){{ intdiv($day['worked_minutes'], 60) }}h {{ $day['worked_minutes'] % 60 }}m @else — @endif
          </td>
          <td style="padding:10px">
            {{-- Present or Absent only. Late is a day present (the minutes
                 are on the printed DTR); a rest day or a day with no shift
                 is neither, and the Shift column already says which. --}}
            @if(in_array($day['status'], ['Present', 'Late'], true))
              <span class="badge b-green">Present</span>
            @elseif($day['status'] === 'Absent')
              <span class="badge b-red">Absent</span>
            @else
              <span style="color:var(--text-3)">—</span>
            @endif
            @if($day['is_manual'])<span class="badge b-gray">Manual</span>@endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endsection
