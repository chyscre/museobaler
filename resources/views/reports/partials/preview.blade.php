{{--
  The stand-in preview for a format the browser cannot display.

  Built from the same ReportDataset the real file is written from, so the
  rows and columns shown here are the rows and columns that land in the
  file. Only the chrome is an approximation, and the note says so rather
  than letting anyone assume this is a rendering of the actual document.

  Capped at PreviewLimit rows: this is a look before downloading, not the
  report itself - the page above it already shows everything.
--}}
@php
  $limit = 40;
@endphp

@if($format === 'csv')
  <div class="sheet-note">
    Plain rows, no letterhead and no totals — this is the file data tools read.
  </div>
  <pre>{{ $csv }}</pre>
  @if($truncated)
    <div class="more">… {{ number_format($remaining) }} more rows in the file.</div>
  @endif
@else
  <div class="sheet-note">
    @if($format === 'xlsx')
      {{ count($sections) > 1 ? count($sections) . ' sheets' : 'One sheet' }}, with the museum letterhead
      at the top and the figures stored as numbers so you can total them.
    @else
      A Word document with the letterhead in the page header, so it repeats if the tables run long.
    @endif
    The layout below is a stand-in; the content is exactly what the file contains.
  </div>

  @foreach($sections as $section)
    @if($section['heading'])
      <div style="font-weight:700;font-size:12px;margin:14px 0 7px">{{ $section['heading'] }}</div>
    @endif

    @if($section['rows'] === [])
      <div class="more">No entries for this period.</div>
    @else
      <table>
        <thead>
          <tr>@foreach($section['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr>
        </thead>
        <tbody>
          @foreach(array_slice($section['rows'], 0, $limit) as $row)
            <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
          @endforeach
        </tbody>
      </table>
      @if(count($section['rows']) > $limit)
        <div class="more">… {{ number_format(count($section['rows']) - $limit) }} more rows in the file.</div>
      @endif
    @endif
  @endforeach
@endif
