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

  {{-- The panel: the period on top, then one row per report. Each row names
       the report and what it is for, with Open and CSV as the two things you
       can do to it - the old panel stacked bare green buttons of differing
       widths with the period floating above them. --}}
  <div id="{{ $id }}" class="rm-panel">

    <div class="rm-period">
      @if($mode === 'date')
        <label class="rm-lbl">Date</label>
        <input type="date" class="fi rm-date" value="{{ request('date', today()->toDateString()) }}">
      @else
        <div class="rm-range">
          <div>
            <label class="rm-lbl">From</label>
            <input type="date" class="fi rm-from" value="{{ today()->startOfMonth()->toDateString() }}">
          </div>
          <div>
            <label class="rm-lbl">To</label>
            <input type="date" class="fi rm-to" value="{{ today()->toDateString() }}">
          </div>
        </div>
      @endif
    </div>

    <div class="rm-list">
      @foreach($reports as $report)
        <div class="rm-row">
          <div class="rm-row-text">
            <div class="rm-row-name">{{ $report['label'] }}</div>
            @if(!empty($report['note']))<div class="rm-row-note">{{ $report['note'] }}</div>@endif
          </div>
          <div class="rm-row-actions">
            {{-- A download-only report has no printable page, so its button
                 fetches the file rather than opening an empty tab. --}}
            <button type="button" class="btn btn-green btn-xs"
                    onclick="openReport('{{ $id }}', '{{ $report['url'] }}', {{ !empty($report['download']) ? 'true' : 'false' }})">
              {{ !empty($report['download']) ? 'Download' : 'Open' }}
            </button>
            @if(!empty($report['csv']))
              <button type="button" class="btn btn-muted btn-xs" title="Download as a spreadsheet"
                      onclick="openReport('{{ $id }}', '{{ $report['csv'] }}', true)">CSV</button>
            @endif
          </div>
        </div>
      @endforeach
    </div>
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
