@extends('layouts.print')
@section('title', 'DTR — ' . $staff->name . ' — ' . $month->format('M Y'))
@section('report-title', 'Daily Time Record')
@section('report-meta', $staff->name . ' · ' . $staff->role_label . ' · ' . $month->format('F Y'))

@section('content')
<div class="tot">
  <div><span class="k">Days present</span><span class="v">{{ $totals['present'] + $totals['late'] }}</span></div>
  <div><span class="k">On time</span><span class="v">{{ $totals['present'] }}</span></div>
  <div><span class="k">Late</span><span class="v">{{ $totals['late'] }}</span></div>
  <div><span class="k">Absent</span><span class="v">{{ $totals['absent'] }}</span></div>
  <div><span class="k">Hours worked</span><span class="v">{{ intdiv($totals['minutes'], 60) }}h {{ $totals['minutes'] % 60 }}m</span></div>
</div>

<table>
  <thead>
    <tr>
      <th style="width:110px">Date</th>
      <th style="width:120px">Shift</th>
      <th style="width:80px">In</th>
      <th style="width:80px">Out</th>
      <th style="width:80px">Worked</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
    @foreach($days as $day)
      <tr>
        <td>{{ $day['date']->format('D, M j') }}</td>
        <td>
          @if($day['schedule'] && !$day['schedule']->is_rest_day)
            {{ \Carbon\Carbon::parse($day['schedule']->shift_start)->format('g:i A') }}–{{ \Carbon\Carbon::parse($day['schedule']->shift_end)->format('g:i A') }}
          @elseif($day['schedule']) Rest day
          @else — @endif
        </td>
        <td>{{ $day['in']?->scanned_at->format('g:i A') ?? '—' }}</td>
        <td>{{ $day['out']?->scanned_at->format('g:i A') ?? '—' }}</td>
        <td>
          @if($day['worked_minutes'] !== null){{ intdiv($day['worked_minutes'], 60) }}h {{ $day['worked_minutes'] % 60 }}m @else — @endif
        </td>
        <td>
          <span class="tag {{ $day['status'] === 'Present' ? 't-green' : ($day['status'] === 'Late' ? 't-gold' : ($day['status'] === 'Absent' ? 't-red' : 't-gray')) }}">
            {{ $day['status'] }}@if($day['late_minutes'] > 0) · {{ $day['late_minutes'] }}m @endif
          </span>
          {{-- A hand-entered day is marked on the printed record too, so the
               flag survives onto the copy that gets signed and filed. --}}
          @if($day['is_manual'])<span class="tag t-gray">Manual</span>@endif
        </td>
      </tr>
    @endforeach
  </tbody>
</table>

@if($totals['manual'] > 0)
  <p class="note">
    <strong>{{ $totals['manual'] }} {{ \Illuminate\Support\Str::plural('day', $totals['manual']) }} marked Manual.</strong>
    These were not scanned. Each was filed by the museum administrator and approved by the
    Tourism office; the audit log holds both names and the stated reason.
  </p>
@endif

<p class="note">
  Attendance is recorded by scanning a code that changes every minute on the staff-room
  screen, with the device's location checked against the museum. Status is measured against
  the schedule on file for {{ $staff->name }}.
  Generated {{ now()->format('F j, Y g:i A') }}.
</p>

<div class="sign">
  <div>
    <div class="line"></div>
    <div>{{ $staff->name }}</div>
    <div class="role">Employee</div>
  </div>
  <div>
    <div class="line"></div>
    <div>Certified by</div>
    <div class="role">Museum Administrator</div>
  </div>
  <div>
    <div class="line"></div>
    <div>Approved by</div>
    <div class="role">Municipal Tourism Officer</div>
  </div>
</div>
@endsection
