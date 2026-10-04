@extends('layouts.print')
@section('title', 'Staff Attendance — ' . $from->format('M j') . ' to ' . $to->format('M j, Y'))
@section('report-title', 'Staff Attendance Summary')
@section('report-meta', 'All museum staff · ' . $from->format('F j, Y') . ' to ' . $to->format('F j, Y'))

@section('downloads')
  @include('reports.partials.downloads', ['report' => 'staff-attendance', 'params' => [
    'from' => $from->toDateString(),
    'to'   => $to->toDateString(),
  ]])
@endsection

@section('content')
<div class="tot">
  <div><span class="k">Staff</span><span class="v">{{ $totals['staff'] }}</span></div>
  <div><span class="k">Present</span><span class="v">{{ $totals['present'] }}</span></div>
  <div><span class="k">Absent</span><span class="v">{{ $totals['absent'] }}</span></div>
  <div><span class="k">Present rate</span><span class="v">{{ $totals['rate'] === null ? '—' : $totals['rate'] . '%' }}</span></div>
  <div><span class="k">Hours worked</span><span class="v">{{ \App\Support\Reports\StaffAttendanceSummary::hours($totals['minutes']) }}</span></div>
</div>

<table>
  <thead>
    <tr>
      <th style="width:36px">No.</th>
      <th>Name</th>
      <th style="width:70px">Present</th>
      <th style="width:70px">Absent</th>
      <th style="width:70px">Rate</th>
      <th style="width:90px">Hours</th>
      <th style="width:140px">Verification</th>
    </tr>
  </thead>
  <tbody>
    @forelse($rows as $i => $row)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $row['staff']->name }}</td>
        <td>{{ $row['present'] }}</td>
        <td>{{ $row['absent'] }}</td>
        <td>{{ $row['rate'] === null ? '—' : $row['rate'] . '%' }}</td>
        <td>{{ \App\Support\Reports\StaffAttendanceSummary::hours($row['minutes']) }}</td>
        <td>
          <span class="tag {{ $row['manual'] || $row['pending'] ? 't-gold' : 't-green' }}">{{ $row['verified'] }}</span>
        </td>
      </tr>
    @empty
      <tr><td colspan="7" style="text-align:center;color:#78716c">No active museum staff.</td></tr>
    @endforelse
  </tbody>
</table>

<p class="note">
  <strong>Present</strong> counts every day with a check-in, on time or late; the minutes late
  are on each person's Daily Time Record. <strong>Absent</strong> is a scheduled working day with
  no check-in. Rest days and days with no shift on file count toward neither, and the rate is
  present out of the two.
  @if($totals['manual'] || $totals['pending'])
    <strong>Manual</strong> days were entered by hand and approved by the Tourism office rather
    than scanned; <strong>pending</strong> are corrections filed for this period and not yet decided,
    so those days may still change.
  @endif
  Generated {{ now()->format('F j, Y g:i A') }}.
</p>

<div class="sign">
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
