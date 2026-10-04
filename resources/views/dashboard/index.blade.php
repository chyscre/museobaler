@extends('layouts.admin')
@section('title', 'Dashboard — Museo de Baler')

@section('content')
{{-- Period. One control for the whole page: every tile, chart and table
     below reads the same dates, so they can be compared side by side. It
     sits in the header beside Export and Print, the two things it changes,
     with what is being shown under the title. Only the inputs the chosen
     period needs are drawn; switching period submits and the server fills in
     a sensible default for the new one. --}}
<div class="ph dash-head">
  <div class="ph-left">
    <h2>Dashboard</h2>
    <p>Showing <strong>{{ $period->label() }}</strong></p>
  </div>
  <div class="ph-right">
    <form method="GET" action="{{ route('dashboard') }}" class="dash-period">
      {{-- Carries the current period when a picker submits the form; a
           period button sends its own value after this one, and the last
           value of a repeated name is the one PHP keeps. --}}
      <input type="hidden" name="period" value="{{ $period->period }}">
      <div class="seg" role="group" aria-label="Report period">
        @foreach(['year' => 'Year', 'month' => 'Month', 'week' => 'Week', 'range' => 'Range'] as $value => $label)
        <button type="submit" name="period" value="{{ $value }}" class="seg-btn {{ $period->period === $value ? 'on' : '' }}"
                aria-pressed="{{ $period->period === $value ? 'true' : 'false' }}"
                title="{{ \App\Support\DashboardPeriod::PERIODS[$value] }}">{{ $label }}</button>
        @endforeach
      </div>

      @if(in_array($period->period, ['year', 'month']))
      <select class="fsel" name="year" onchange="this.form.submit()" aria-label="Year">
        @foreach($years as $y)
        <option value="{{ $y }}" {{ $period->from->year === $y ? 'selected' : '' }}>{{ $y }}</option>
        @endforeach
      </select>
      @endif

      @if($period->period === 'month')
      <select class="fsel" name="month" onchange="this.form.submit()" aria-label="Month">
        @foreach(range(1, 12) as $m)
        <option value="{{ $m }}" {{ $period->from->month === $m ? 'selected' : '' }}>{{ \Illuminate\Support\Carbon::create(2000, $m, 1)->format('F') }}</option>
        @endforeach
      </select>
      @endif

      @if($period->period === 'week')
      <label class="dash-period-lbl" for="dpWeek">Week of</label>
      <input id="dpWeek" type="date" class="date-in" name="week" value="{{ $period->from->toDateString() }}" onchange="this.form.submit()">
      @endif

      @if($period->period === 'range')
      <input id="dpFrom" type="date" class="date-in" name="from" value="{{ $period->from->toDateString() }}" onchange="this.form.submit()" aria-label="From">
      <label class="dash-period-lbl" for="dpTo">to</label>
      <input id="dpTo" type="date" class="date-in" name="to" value="{{ $period->to->toDateString() }}" onchange="this.form.submit()">
      @endif

      <noscript><button class="btn btn-outline btn-sm">Apply</button></noscript>
    </form>
    <span class="ph-sep" aria-hidden="true"></span>
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
  $csvData = [
    'categories' => $categories->map(fn ($c) => [$c->name, $c->total])->values(),
    'top'        => $topExhibits->values()->map(fn ($ex, $i) => [$i + 1, $ex->name, $ex->scans_count]),
    'low'        => $lowExhibits->values()->map(fn ($ex, $i) => [$i + 1, $ex->name, $ex->scans_count]),
  ];
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

function exportDashboardCSV() {
  const csvData = @json($csvData);
  const rows = [
    ['Period', @json($period->label())],
    [''],
    ['Metric','Value'],
    ['Visitors', @json($stats['visitors'])],
    ['Attendance', @json($stats['attendance'])],
    ['QR Scans', @json($stats['scans'])],
    ['Feedback', @json($stats['feedback'])],
    ['Average Rating', @json($stats['avg_rating'] ?: 'N/A')],
    [''],
    ['Scans by Category',''],
    ['Category','Scans'],
    ...csvData.categories,
    [''],
    ['Most Scanned Exhibits',''],
    ['Rank','Exhibit','Scans'],
    ...csvData.top,
    [''],
    ['Least Scanned Exhibits',''],
    ['Rank','Exhibit','Scans'],
    ...csvData.low,
  ];
  const csv = rows.map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download = 'dashboard_' + @json(\Illuminate\Support\Str::slug($period->label())) + '.csv';
  a.click();
  toast('CSV exported', 'gold');
}
</script>
@endpush
