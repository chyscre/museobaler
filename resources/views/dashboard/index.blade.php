@extends('layouts.admin')
@section('title', 'Dashboard — Museo de Baler')

@section('content')
{{-- Period. One control for the whole page: every tile, chart and table
     below reads the same dates, so they can be compared side by side. It sits
     beside Generate Report - see components/period-bar.

     Generate Report hands the same dates to the museum's own reports, which
     carry the letterhead and save as CSV / Excel / Word / PDF. The old
     Print Report printed this screen, sidebar and all, cut off at the edge;
     the old Export CSV was a second, unbranded copy of the same figures. --}}
<div class="ph">
  <div class="ph-left">
    <h2>Dashboard</h2>
    <p>Showing <strong>{{ $period->label() }}</strong></p>
  </div>
  <div class="ph-right">
    <x-period-bar :period="$period" :action="route('dashboard')" />
    @include('partials.report-menu', [
      'id'      => 'dashReportMenu',
      'mode'    => 'range',
      'from'    => $period->from,
      'to'      => $period->to->copy()->min(today()),
      'reports' => [
        ['label' => 'Visitors & admission', 'note' => 'Headcount and fees collected',     'url' => route('reports.visitors')],
        ['label' => 'Earnings & revenue',   'note' => 'Individual vs group, by date',     'url' => route('reports.earnings')],
        ['label' => 'Exhibit engagement',   'note' => 'Which exhibits were scanned most', 'url' => route('reports.exhibits')],
        ['label' => 'Feedback & CSM',       'note' => 'Ratings and what visitors wrote',  'url' => route('reports.feedback'), 'csv' => route('reports.feedback.csv')],
        ['label' => 'Daily logbook',        'note' => 'Who came in, hour by hour',        'url' => route('reports.logbook'), 'csv' => route('reports.logbook.csv')],
      ],
    ])
  </div>
</div>

{{-- Stat Cards --}}
{{-- Every tile links to the section its figure is drawn from. Real anchors
     rather than onclick handlers so they are keyboard-reachable and can be
     opened in a new tab. --}}
@php $statLink = 'text-decoration:none;color:inherit;cursor:pointer'; @endphp
<div class="stats-row stats-4">
  <a href="{{ route('records.index') }}?tab=visitors" class="stat" style="{{ $statLink }}">
    <div class="stat-ico green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($stats['visitors']) }}</div><div class="stat-lbl">Visitors</div></div>
  </a>
  <a href="{{ route('attendance.index') }}" class="stat" style="{{ $statLink }}">
    <div class="stat-ico green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($stats['attendance']) }}</div><div class="stat-lbl">Attendance</div></div>
  </a>
  <a href="{{ route('feedback.index') }}" class="stat" style="{{ $statLink }}">
    <div class="stat-ico blue">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($stats['feedback']) }}</div><div class="stat-lbl">Feedback</div></div>
  </a>
  <a href="{{ route('feedback.index') }}" class="stat" style="{{ $statLink }}">
    <div class="stat-ico purple">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div><div class="stat-val">{{ $stats['avg_rating'] ?: '—' }}</div><div class="stat-lbl">Avg. Rating</div></div>
  </a>
</div>

{{-- Charts --}}
<div class="charts-grid charts-3-2">
  <div class="card card-p">
    <h3 class="sec-title">Visitor Trend</h3>
    <p class="sec-sub">Visitors per {{ $period->daily() ? 'day' : 'month' }} — {{ $period->label() }}</p>
    <div class="chart-wrap"><canvas id="chartVisitors"></canvas></div>
  </div>

  {{-- A ranked list rather than a doughnut: category names sit next to
       their numbers instead of in a legend under the chart, and a long
       list of categories costs a few rows, not a taller canvas. --}}
  @php $catTotal = $categories->sum('total'); $catMax = $categories->max('total') ?: 1; @endphp
  <div class="card card-p">
    <div class="sec-head">
      <div>
        <h3 class="sec-title">Scans by Category</h3>
        <p class="sec-sub">QR scans per exhibit category</p>
      </div>
      {{-- The scan total lives here now that it has no tile of its own,
           and still leads to the scan records the tile used to. --}}
      <a href="{{ route('records.index') }}?tab=scans" class="sec-figure" title="Open scan records">{{ number_format($catTotal) }} <small>scans</small></a>
    </div>
    @forelse($categories as $cat)
      @php $pct = $catTotal > 0 ? round($cat->total / $catTotal * 100) : 0; @endphp
      <div class="cat-row">
        <div class="cat-row-head">
          <span class="cat-row-name">{{ $cat->name }}</span>
          <span class="cat-row-val">{{ number_format($cat->total) }} <small>{{ $pct }}%</small></span>
        </div>
        <div class="rbar-track"><div class="rbar-fill" style="width:{{ round($cat->total / $catMax * 100) }}%;background:var(--green)"></div></div>
      </div>
    @empty
      <p class="dash-empty">No scans in this period.</p>
    @endforelse
  </div>
</div>

{{-- Most / Least Scanned --}}
<div class="dash-pair">
  <div class="card card-p">
    <div class="sec-head">
      <div>
        <h3 class="sec-title">Most Scanned Exhibits</h3>
        <p class="sec-sub">Top 5 by QR scan count</p>
      </div>
      <span class="badge b-green">Top 5</span>
    </div>
    <table class="mini-tbl">
      <thead><tr><th>#</th><th class="l">Exhibit</th><th class="r">Scans</th></tr></thead>
      <tbody>
      @forelse($topExhibits as $i => $ex)
      <tr>
        <td class="muted">{{ $i + 1 }}</td>
        <td>{{ $ex->name }}</td>
        <td class="r num" style="color:var(--green-dark)">{{ number_format($ex->scans_count) }}</td>
      </tr>
      @empty
      <tr><td colspan="3" class="dash-empty">No scan data yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>

  <div class="card card-p">
    <div class="sec-head">
      <div>
        <h3 class="sec-title">Least Scanned Exhibits</h3>
        <p class="sec-sub">
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
    <table class="mini-tbl">
      <thead><tr><th>#</th><th class="l">Exhibit</th><th class="r">Scans</th></tr></thead>
      <tbody>
      @forelse($lowExhibits as $i => $ex)
      <tr>
        <td class="muted">{{ $i + 1 }}</td>
        <td>{{ $ex->name }}</td>
        <td class="r num" style="color:var(--red)">{{ number_format($ex->scans_count) }}</td>
      </tr>
      @empty
      <tr><td colspan="3" class="dash-empty">No scan data yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- Feedback Summary --}}
@php $fbTotal = $fbDist->sum(); @endphp
<div class="card card-p" style="margin-bottom:16px">

  <div class="sec-head">
    <div>
      <h3 class="sec-title">Feedback Summary</h3>
      <p class="sec-sub">{{ number_format($fbTotal) }} {{ \Illuminate\Support\Str::plural('response', $fbTotal) }} — {{ $period->label() }}</p>
    </div>
    <div style="display:flex;align-items:baseline;gap:8px">
      <div style="font-family:'Young Serif',serif;font-size:28px;color:var(--green-dark);line-height:1">{{ $stats['avg_rating'] ?: '—' }}</div>
      <div style="color:var(--gold);font-size:13px;letter-spacing:2px">
        @if($stats['avg_rating'])
          @php $rounded = round($stats['avg_rating']); @endphp
          {{ str_repeat('★', $rounded) }}{{ str_repeat('☆', 5 - $rounded) }}
        @else ☆☆☆☆☆ @endif
      </div>
      <div style="font-size:11px;color:var(--text-3)">out of 5</div>
    </div>
  </div>

  @php
    $ratingColors = [5 => 'var(--green)', 4 => '#4ade80', 3 => 'var(--gold)', 2 => '#f97316', 1 => 'var(--red)'];
    $starStr      = [5 => '★★★★★', 4 => '★★★★☆', 3 => '★★★☆☆', 2 => '★★☆☆☆', 1 => '★☆☆☆☆'];
  @endphp
  <div class="fb-bars">
  @foreach($starStr as $r => $stars)
    @php $cnt = $fbDist[$r] ?? 0; $pct = $fbTotal > 0 ? round($cnt / $fbTotal * 100) : 0; @endphp
    <div class="fb-bar">
      <span class="fb-bar-stars">{{ $stars }}</span>
      <div class="rbar-track"><div class="rbar-fill" style="width:{{ $pct }}%;background:{{ $ratingColors[$r] }}"></div></div>
      <span class="fb-bar-val">{{ number_format($cnt) }} <small>({{ $pct }}%)</small></span>
    </div>
  @endforeach
  </div>

</div>
@endsection

@push('scripts')
@php
  $chartUrl = route('dashboard.chart.visitors', $period->query());
@endphp
<script>
async function loadCharts() {
  const vRes  = await fetch(@json($chartUrl));
  const vData = await vRes.json();
  new Chart(document.getElementById('chartVisitors'), {
    type: 'bar',
    data: { labels: vData.labels, datasets: [{ label: 'Visitors', data: vData.values, backgroundColor: 'rgba(34,197,94,.7)', borderRadius: 4, maxBarThickness: 36 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0 } }, y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });
}
loadCharts();

</script>
@endpush
