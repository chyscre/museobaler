{{--
  Save, Print, and the format to save as.

  Three controls on one line. Save downloads the chosen format straight
  away. There is no preview step: it rendered a full PDF on every page load
  and on every change of the dropdown. That was the slowest thing on these
  screens, and it doubled the work, because the file was rendered again
  when it was saved.

  Driven by ReportBuilder::FORMATS, so a report cannot offer a format it
  does not serve.

  $report  the report key
  $params  the filters on screen, carried into the download so the file
           covers the period actually being looked at
  $routes  optional override of the route names, for the audit trail, whose
           routes live behind the Tourism wall and take no {report} segment
--}}
@php
  $formats = \App\Support\Reports\ReportBuilder::FORMATS[$report] ?? [];
  $params  = $params ?? [];
  $routes  = ($routes ?? []) + ['export' => 'reports.export'];
  $labels  = ['pdf' => 'PDF', 'xlsx' => 'Excel', 'docx' => 'Word', 'csv' => 'CSV'];

  // The shared routes carry the report in the path; the audit ones name it
  // in the route itself, so passing it would append a stray query string.
  $args = fn (string $format) => array_merge(
      $routes['export'] === 'reports.export' ? ['report' => $report] : [],
      ['format' => $format],
      $params
  );

  // PDF first where it exists: it is what the page already looks like, so
  // it is the one that needs the least explaining.
  $order = array_values(array_filter(['pdf', 'xlsx', 'docx', 'csv'], fn ($f) => in_array($f, $formats, true)));
@endphp

@if($order !== [])
  {{-- The href is the first format's real URL, not "#", so Save works
       before the script runs and with no script at all. --}}
  <a id="saveBtn" class="go" href="{{ route($routes['export'], $args($order[0])) }}" download>Save</a>
  <button type="button" class="print" onclick="window.print()">Print</button>

  <label class="fmt">
    <span>Save as</span>
    <select id="fmtPick">
      @foreach($order as $format)
        <option value="{{ $format }}"
                data-url="{{ route($routes['export'], $args($format)) }}">{{ $labels[$format] }}</option>
      @endforeach
    </select>
  </label>

  <script>
  (function () {
    var pick    = document.getElementById('fmtPick');
    var saveBtn = document.getElementById('saveBtn');

    if (!pick || !saveBtn) {
      return;
    }

    // Only points Save at the chosen format. Nothing is fetched until
    // somebody clicks Save.
    pick.addEventListener('change', function () {
      saveBtn.href = pick.options[pick.selectedIndex].dataset.url;
    });
  })();
  </script>
@endif
