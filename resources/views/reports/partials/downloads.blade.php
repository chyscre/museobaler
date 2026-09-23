{{--
  Save, Print, and the format to save as.

  Three controls on one line. Picking a format previews it straight away -
  there is no Preview button, because having to ask for one was the hassle.

  Driven by ReportBuilder::FORMATS, so a report cannot offer a format it
  does not serve.

  $report  the report key
  $params  the filters on screen, carried into both the preview and the
           download, so the file covers the period actually being looked at
  $routes  optional override of the route names, for the audit trail, whose
           routes live behind the Tourism wall and take no {report} segment
--}}
@php
  $formats = \App\Support\Reports\ReportBuilder::FORMATS[$report] ?? [];
  $params  = $params ?? [];
  $routes  = ($routes ?? []) + ['export' => 'reports.export', 'preview' => 'reports.preview'];
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
  <a id="saveBtn" class="go" href="#" download>Save</a>
  <button type="button" class="print" onclick="window.print()">Print</button>

  <label class="fmt">
    <span>Save as</span>
    <select id="fmtPick">
      @foreach($order as $format)
        <option value="{{ $format }}"
                data-url="{{ route($routes['export'], $args($format)) }}"
                data-preview="{{ route($routes['preview'], $args($format)) }}">{{ $labels[$format] }}</option>
      @endforeach
    </select>
  </label>

  <script>
  (function () {
    // Deferred until the document is parsed.
    //
    // These controls are yielded into the toolbar, and the preview pane they
    // write into sits further down the page - so at the moment this script
    // runs, #prevBody and #prevWhat do not exist yet. Reading them here threw
    // on every load, which left the pane permanently empty and the Save link
    // pointing at "#".
    function boot() {
      var pick    = document.getElementById('fmtPick');
      var saveBtn = document.getElementById('saveBtn');
      var body    = document.getElementById('prevBody');
      var what    = document.getElementById('prevWhat');

      if (!pick || !saveBtn || !body || !what) {
        return;
      }

      function show() {
        var opt = pick.options[pick.selectedIndex];

        saveBtn.href     = opt.dataset.url;
        what.textContent = opt.textContent;
        body.innerHTML   = '<div class="loading">Building the preview…</div>';

        // A PDF is rendered by the browser itself; the rest are file formats
        // nothing can display, so the server returns an HTML stand-in built
        // from the same rows the real file is written from.
        if (opt.value === 'pdf') {
          body.className = 'body pdf';
          body.innerHTML = '<iframe title="PDF preview" src="' + opt.dataset.preview + '"></iframe>';
          return;
        }

        body.className = 'body';
        fetch(opt.dataset.preview, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
          .then(function (html) { body.innerHTML = html; })
          .catch(function () {
            body.innerHTML = '<div class="loading">The preview could not be built. Saving the file should still work.</div>';
          });
      }

      pick.addEventListener('change', show);
      show();
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  })();
  </script>
@endif
