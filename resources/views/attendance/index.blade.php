@extends('layouts.admin')
@section('title', 'Attendance — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Attendance</h2>
  </div>
  <div class="ph-right">
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
      <input type="date" name="date" value="{{ $date }}"
             style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface);"
             onchange="this.form.submit()">
    </form>
  </div>
</div>

{{-- Stat Cards --}}
<div class="stats-row" style="margin-bottom:22px">
  <div class="stat">
    <div class="stat-ico green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($todayCount) }}</div><div class="stat-lbl">Today's Attendance</div></div>
  </div>
  <div class="stat">
    <div class="stat-ico blue">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($totalCount) }}</div><div class="stat-lbl">Total Logged</div></div>
  </div>
</div>

{{-- 7-day chart --}}
<div class="card card-p-lg" style="margin-bottom:22px">
  <h3 class="sec-title">Last 7 Days</h3>
  <div class="chart-wrap"><canvas id="chartAttendance"></canvas></div>
</div>

{{-- Attendance log table --}}
<div class="card card-p-lg">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <div>
      <h3 class="sec-title" style="margin-bottom:0">Attendance Log</h3>
      <p style="font-size:12px;color:var(--text-3)">Showing records for {{ \Carbon\Carbon::parse($date)->format('F j, Y') }}</p>
    </div>
    <span class="badge b-green">{{ $attendances->total() }} records</span>
  </div>

  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1.5px solid var(--border)">
        <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">#</th>
        <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">Visitor</th>
        <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">Method</th>
        <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">Accuracy</th>
        <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">Time</th>
      </tr>
    </thead>
    <tbody>
    @forelse($attendances as $i => $a)
      <tr style="border-bottom:1px solid var(--border-light)">
        <td style="padding:10px;font-size:13px;color:var(--text-3)">{{ $attendances->firstItem() + $i }}</td>
        <td style="padding:10px">
          <div style="font-size:13px;font-weight:600;color:var(--text)">
            {{ $a->visitor_name ?: ($a->visitor ? $a->visitor->full_name : 'Anonymous') }}
          </div>
          @if($a->visitor)
          <div style="font-size:11px;color:var(--text-3)">{{ $a->visitor->visitor_type }} · {{ $a->visitor->city ?? 'Unknown' }}</div>
          @endif
        </td>
        <td style="padding:10px">
          {{-- Label the method that is actually stored. The old two-way
               check called anything that wasn't 'geofence' "Manual", which
               swept up the 'registered' value the claim endpoint used to
               write and mislabelled every signed-in visitor. --}}
          @php
            [$badgeClass, $badgeText] = match ($a->method) {
                'geofence', 'registered' => ['b-green', '📍 Geofence'],
                'manual'                 => ['b-blue',  '✋ Manual'],
                default                  => ['b-blue',  ucfirst($a->method ?? 'Unknown')],
            };
          @endphp
          <span class="badge {{ $badgeClass }}">{{ $badgeText }}</span>
        </td>
        <td style="padding:10px;font-size:13px;color:var(--text-3)">
          {{ $a->accuracy ? '±' . $a->accuracy . 'm' : '—' }}
        </td>
        <td style="padding:10px;font-size:13px;color:var(--text-3)">
          {{ $a->created_at->format('h:i A') }}
        </td>
      </tr>
    @empty
      <tr>
        <td colspan="5" style="padding:32px;text-align:center;color:var(--text-3);font-size:13px">
          No attendance records for this date.
        </td>
      </tr>
    @endforelse
    </tbody>
  </table>

  @if($attendances->hasPages())
  <div style="margin-top:16px">{{ $attendances->links() }}</div>
  @endif
</div>
@endsection

@push('scripts')
<script>
const labels = @json($chartLabels);
const values = @json($chartValues);
new Chart(document.getElementById('chartAttendance'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'Visitors',
      data: values,
      backgroundColor: 'rgba(34,197,94,.7)',
      borderRadius: 6
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
  }
});
</script>
@endpush
