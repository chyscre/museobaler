@extends('layouts.admin')
@section('title', 'Dashboard — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Dashboard</h2>
    <p>Overview of museum analytics and statistics</p>
  </div>
  <div class="ph-right">
    <button class="btn btn-gold btn-sm" onclick="exportDashboardCSV()">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Export CSV
    </button>
    <button class="btn btn-outline btn-sm" onclick="window.print()">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Report
    </button>
  </div>
</div>

{{-- Stat Cards --}}
{{-- Every tile links to the section its figure is drawn from. Real anchors
     rather than onclick handlers so they are keyboard-reachable and can be
     opened in a new tab. --}}
@php $statLink = 'text-decoration:none;color:inherit;cursor:pointer'; @endphp
<div class="stats-row">
  <a href="{{ route('records.index') }}?tab=visitors" class="stat" style="{{ $statLink }}">
    <div class="stat-ico green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($stats['visitors']) }}</div><div class="stat-lbl">Total Visitors</div></div>
  </a>
  <a href="{{ route('attendance.index') }}" class="stat" style="{{ $statLink }}">
    <div class="stat-ico green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
    </div>
    <div>
      <div class="stat-val">{{ number_format($stats['today_attendance']) }}</div>
      <div class="stat-lbl">Today's Attendance</div>
    </div>
  </a>
  <a href="{{ route('records.index') }}?tab=scans" class="stat" style="{{ $statLink }}">
    <div class="stat-ico gold">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($stats['scans']) }}</div><div class="stat-lbl">Total Scans</div></div>
  </a>
  <a href="{{ route('feedback.index') }}" class="stat" style="{{ $statLink }}">
    <div class="stat-ico blue">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($stats['feedback']) }}</div><div class="stat-lbl">Total Feedback</div></div>
  </a>
  <a href="{{ route('feedback.index') }}" class="stat" style="{{ $statLink }}">
    <div class="stat-ico purple">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div><div class="stat-val">{{ $stats['avg_rating'] ?: '—' }}</div><div class="stat-lbl">Avg. Rating</div></div>
  </a>
</div>

{{-- Charts --}}
<div class="charts-grid">
  <div class="card card-p-lg">
    <h3 class="sec-title">Monthly Visitor Trend</h3>
    <p class="sec-sub">Visitors per month — {{ date('Y') }}</p>
    <div class="chart-wrap"><canvas id="chartVisitors"></canvas></div>
  </div>
  <div class="card card-p-lg">
    <h3 class="sec-title">Scans by Category</h3>
    <p class="sec-sub">QR scan distribution by exhibit category</p>
    <div class="chart-wrap-cat"><canvas id="chartCat"></canvas></div>
  </div>
</div>

{{-- Most / Least Scanned --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:22px">
  <div class="card card-p-lg">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <div>
        <h3 class="sec-title" style="margin-bottom:0">Most Scanned Exhibits</h3>
        <p style="font-size:12px;color:var(--text-3)">Top 5 by QR scan count</p>
      </div>
      <span class="badge b-green">Top 5</span>
    </div>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="border-bottom:1.5px solid var(--border)">
        <th style="padding:8px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">#</th>
        <th style="padding:8px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">Exhibit</th>
        <th style="padding:8px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:right">Scans</th>
      </tr></thead>
      <tbody>
      @forelse($topExhibits as $i => $ex)
      <tr style="border-bottom:1px solid var(--border-light)">
        <td style="padding:10px;font-size:13px;color:var(--text-3)">{{ $i + 1 }}</td>
        <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $ex->name }}</td>
        <td style="padding:10px;font-size:13px;font-weight:700;color:var(--green-dark);text-align:right">{{ number_format($ex->scans_count) }}</td>
      </tr>
      @empty
      <tr><td colspan="3" style="padding:16px;text-align:center;color:var(--text-3);font-size:13px">No scan data yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>

  <div class="card card-p-lg">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <div>
        <h3 class="sec-title" style="margin-bottom:0">Least Scanned Exhibits</h3>
        <p style="font-size:12px;color:var(--text-3)">
          {{ $lowExhibits->isEmpty() ? 'Once visitors start scanning' : 'Bottom 3 — needs attention' }}
        </p>
      </div>
      {{-- No "low engagement" verdict before there is any engagement to
           measure: with nothing scanned, the red badge accuses exhibits of
           something that has not happened. --}}
      @if($lowExhibits->isNotEmpty())
      <span class="badge b-red">Low Engagement</span>
      @endif
    </div>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="border-bottom:1.5px solid var(--border)">
        <th style="padding:8px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">#</th>
        <th style="padding:8px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">Exhibit</th>
        <th style="padding:8px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:right">Scans</th>
      </tr></thead>
      <tbody>
      @forelse($lowExhibits as $i => $ex)
      <tr style="border-bottom:1px solid var(--border-light)">
        <td style="padding:10px;font-size:13px;color:var(--text-3)">{{ $i + 1 }}</td>
        <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $ex->name }}</td>
        <td style="padding:10px;font-size:13px;font-weight:700;color:var(--red);text-align:right">{{ number_format($ex->scans_count) }}</td>
      </tr>
      @empty
      <tr><td colspan="3" style="padding:16px;text-align:center;color:var(--text-3);font-size:13px">No scan data yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- Feedback Summary --}}
@php $fbTotal = $fbDist->sum(); @endphp
<div class="card card-p-lg" style="margin-bottom:22px">

  {{-- Header --}}
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px">
    <div>
      <h3 class="sec-title" style="margin-bottom:2px">Feedback Summary</h3>
      <p class="sec-sub" style="margin-bottom:0">{{ number_format($fbTotal) }} total responses</p>
    </div>
    <div style="text-align:right">
      <div style="font-family:'Young Serif',serif;font-size:40px;color:var(--green-dark);line-height:1">{{ $stats['avg_rating'] ?: '—' }}</div>
      <div style="color:var(--gold);font-size:15px;letter-spacing:3px;margin-top:3px">
        @if($stats['avg_rating'])
          @php $rounded = round($stats['avg_rating']); @endphp
          {{ str_repeat('★', $rounded) }}{{ str_repeat('☆', 5 - $rounded) }}
        @else ☆☆☆☆☆ @endif
      </div>
      <div style="font-size:11px;color:var(--text-3);margin-top:2px">out of 5</div>
    </div>
  </div>

  {{-- Rating bars --}}
  @php
    $ratingLabels = [5 => 'Excellent', 4 => 'Good', 3 => 'Average', 2 => 'Poor', 1 => 'Very Poor'];
    $ratingColors = [5 => 'var(--green)', 4 => '#4ade80', 3 => 'var(--gold)', 2 => '#f97316', 1 => 'var(--red)'];
    $starStr      = [5 => '★★★★★', 4 => '★★★★☆', 3 => '★★★☆☆', 2 => '★★☆☆☆', 1 => '★☆☆☆☆'];
  @endphp
  @foreach($ratingLabels as $r => $lbl)
    @php $cnt = $fbDist[$r] ?? 0; $pct = $fbTotal > 0 ? round($cnt / $fbTotal * 100) : 0; @endphp
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
      <div style="width:80px;flex-shrink:0;text-align:right">
        <span style="font-size:11px;color:var(--gold);letter-spacing:1px">{{ $starStr[$r] }}</span>
      </div>
      <div style="flex:1;height:10px;background:var(--border-light);border-radius:99px;overflow:hidden">
        <div style="height:100%;width:{{ $pct }}%;background:{{ $ratingColors[$r] }};border-radius:99px;transition:width .4s ease"></div>
      </div>
      <div style="width:90px;flex-shrink:0;display:flex;align-items:center;gap:6px">
        <span style="font-size:13px;font-weight:700;color:var(--text)">{{ number_format($cnt) }}</span>
        <span style="font-size:11px;color:var(--text-4)">({{ $pct }}%)</span>
      </div>
    </div>
  @endforeach

</div>
@endsection

@push('scripts')
<script>
async function loadCharts() {
  const vRes  = await fetch('{{ route("dashboard.chart.visitors") }}');
  const vData = await vRes.json();
  new Chart(document.getElementById('chartVisitors'), {
    type: 'bar',
    data: { labels: vData.labels, datasets: [{ label: 'Visitors', data: vData.values, backgroundColor: 'rgba(34,197,94,.7)', borderRadius: 6 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });

  const cRes  = await fetch('{{ route("dashboard.chart.categories") }}');
  const cData = await cRes.json();
  new Chart(document.getElementById('chartCat'), {
    type: 'doughnut',
    data: { labels: cData.labels, datasets: [{ data: cData.values, backgroundColor: ['#22c55e','#3b82f6','#fbbf24','#8b5cf6','#ef4444','#f97316'] }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14, font: { size: 12 } } } } }
  });
}
loadCharts();

function exportDashboardCSV() {
  const rows = [
    ['Metric','Value'],
    ['Total Visitors','{{ $stats["visitors"] }}'],
    ['Total Scans','{{ $stats["scans"] }}'],
    ['Total Feedback','{{ $stats["feedback"] }}'],
    ['Average Rating','{{ $stats["avg_rating"] ?: "N/A" }}'],
    [''],
    ['Most Scanned Exhibits',''],
    ['Rank','Exhibit','Scans'],
    @foreach($topExhibits as $i => $ex)
    ['{{ $i+1 }}','{{ addslashes($ex->name) }}','{{ $ex->scans_count }}'],
    @endforeach
    [''],
    ['Least Scanned Exhibits',''],
    ['Rank','Exhibit','Scans'],
    @foreach($lowExhibits as $i => $ex)
    ['{{ $i+1 }}','{{ addslashes($ex->name) }}','{{ $ex->scans_count }}'],
    @endforeach
  ];
  const csv = rows.map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download = 'dashboard_{{ date("Y-m-d") }}.csv';
  a.click();
  toast('CSV exported', 'gold');
}
</script>
@endpush
