@extends('layouts.print')
@section('title', 'Exhibit Engagement')
@section('report-title', 'Exhibit Engagement')
@section('report-meta', $from->format('F j, Y') . ' – ' . $to->format('F j, Y'))

@section('downloads')
  @include('reports.partials.downloads', ['report' => 'exhibits', 'params' => [
    'from' => $from->toDateString(),
    'to'   => $to->toDateString(),
  ]])
@endsection

@section('content')
<div class="tot">
  <div><span class="k">Exhibit scans</span><span class="v">{{ number_format($total) }}</span></div>
  <div><span class="k">Exhibits reached</span><span class="v">{{ $byExhibit->count() }}</span></div>
  <div><span class="k">Guided tours</span><span class="v">{{ number_format($tours) }}</span></div>
</div>

<h2>Most scanned</h2>
@if($byExhibit->isEmpty())
  <p class="note">No exhibit scans in this range.</p>
@else
  <table>
    <thead><tr><th style="width:44px">#</th><th>Exhibit</th><th style="width:90px">Scans</th><th>Share</th></tr></thead>
    <tbody>
      @foreach($byExhibit as $i => $row)
        <tr>
          <td>{{ $i + 1 }}</td>
          <td><strong>{{ $row['exhibit']->name ?? 'Removed exhibit' }}</strong></td>
          <td>{{ number_format($row['count']) }}</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;max-width:220px;height:8px;background:#f5f5f4;border-radius:99px;overflow:hidden">
                <div style="height:100%;background:#166534;width:{{ $total ? round($row['count'] / $total * 100) : 0 }}%"></div>
              </div>
              <span>{{ $total ? round($row['count'] / $total * 100, 1) : 0 }}%</span>
            </div>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

<h2>Language chosen</h2>
{{-- Worth watching for its own sake: a steady share of non-English scans is
     the argument for keeping the translations and audio guides funded. --}}
@if($byLanguage->isEmpty())
  <p class="note">No language data recorded in this range.</p>
@else
  <table>
    <thead><tr><th>Language</th><th style="width:100px">Scans</th><th style="width:120px">Share</th></tr></thead>
    <tbody>
      @foreach($byLanguage as $code => $count)
        <tr>
          <td>{{ $code ?: 'Not recorded' }}</td>
          <td>{{ number_format($count) }}</td>
          <td>{{ $total ? round($count / $total * 100, 1) : 0 }}%</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

<p class="note">
  Scans are counted when a visitor opens an exhibit from its QR code in the visitor app,
  so this measures what people actually stopped to read, not what they walked past.
  Generated {{ now()->format('F j, Y g:i A') }} by {{ auth()->user()->name }}.
</p>
@endsection
