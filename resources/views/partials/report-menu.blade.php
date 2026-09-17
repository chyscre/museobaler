{{--
  Report controls for a section header.

  Reports used to live in their own "Reports" hub, which meant knowing a
  report existed before you could find it. They sit here instead: the daily
  logbook is on Records because that is where the visitors are, the feedback
  report is on Feedback, the audit export is on Logs. You print from where
  you are already looking.

  Params:
    $reports  array of ['label' =>, 'url' =>, 'csv' => ?url]
    $mode     'date' for a single day, 'range' for from/to (default 'range')
    $id       unique per page — two menus on one page would collide
--}}
@php
  $mode ??= 'range';
  $id   ??= 'reportMenu';
@endphp

<div style="position:relative;display:inline-block">
  <button type="button" class="btn btn-outline btn-sm" onclick="toggleReportMenu('{{ $id }}')">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Generate Report
  </button>

  <div id="{{ $id }}" style="display:none;position:absolute;right:0;top:calc(100% + 6px);width:290px;background:var(--surface);border:1.5px solid var(--border);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.14);z-index:900;padding:14px">

    @if($mode === 'date')
      <label style="display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">Date</label>
      <input type="date" class="rm-date" value="{{ request('date', today()->toDateString()) }}"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface);margin-bottom:12px">
    @else
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">From</label>
          <input type="date" class="rm-from" value="{{ today()->startOfMonth()->toDateString() }}"
                 style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">To</label>
          <input type="date" class="rm-to" value="{{ today()->toDateString() }}"
                 style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
        </div>
      </div>
    @endif

    @foreach($reports as $report)
      <div style="display:flex;gap:6px;margin-bottom:7px">
        {{-- A download-only report has no printable page, so its main button
             fetches the file rather than opening an empty tab. --}}
        <button type="button" class="btn btn-green btn-sm" style="flex:1;justify-content:center"
                onclick="openReport('{{ $id }}', '{{ $report['url'] }}', {{ !empty($report['download']) ? 'true' : 'false' }})">
          {{ $report['label'] }}
        </button>
        @if(!empty($report['csv']))
          <button type="button" class="btn btn-outline btn-sm" title="Download as CSV"
                  onclick="openReport('{{ $id }}', '{{ $report['csv'] }}', true)">CSV</button>
        @endif
      </div>
    @endforeach

    <p style="font-size:11px;color:var(--text-3);margin-top:9px;line-height:1.5">
      Opens a printable page in a new tab. Use your browser's Print to save it as PDF.
    </p>
  </div>
</div>

@once
@push('scripts')
<script>
  function toggleReportMenu(id) {
    var el = document.getElementById(id);
    var open = el.style.display === 'block';
    // Close any other menu first so two never overlap.
    document.querySelectorAll('[id$="ReportMenu"], #reportMenu').forEach(function (m) { m.style.display = 'none'; });
    el.style.display = open ? 'none' : 'block';
  }

  function openReport(id, url, sameTab) {
    var menu = document.getElementById(id);
    var params = [];

    var d = menu.querySelector('.rm-date');
    if (d && d.value) params.push('date=' + encodeURIComponent(d.value));

    var f = menu.querySelector('.rm-from');
    var t = menu.querySelector('.rm-to');
    if (f && f.value) params.push('from=' + encodeURIComponent(f.value));
    if (t && t.value) params.push('to=' + encodeURIComponent(t.value));

    var full = url + (params.length ? (url.indexOf('?') === -1 ? '?' : '&') + params.join('&') : '');

    // A CSV is a download, not a page — opening it in a tab leaves a blank
    // window behind on most browsers.
    if (sameTab) { window.location = full; } else { window.open(full, '_blank'); }
    menu.style.display = 'none';
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[onclick^="toggleReportMenu"]') || e.target.closest('[id$="ReportMenu"]')) return;
    document.querySelectorAll('[id$="ReportMenu"]').forEach(function (m) { m.style.display = 'none'; });
  });
</script>
@endpush
@endonce
