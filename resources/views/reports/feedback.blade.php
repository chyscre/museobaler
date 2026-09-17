@extends('layouts.print')
@section('title', 'Feedback Report')
@section('report-title', 'Visitor Feedback')
@section('report-meta', $from->format('F j, Y') . ' – ' . $to->format('F j, Y'))

@section('content')
<div class="tot">
  <div><span class="k">Responses</span><span class="v">{{ number_format($total) }}</span></div>
  <div><span class="k">Average rating</span><span class="v">{{ $average ? $average . ' / 5' : '—' }}</span></div>
  <div><span class="k">About the museum</span><span class="v">{{ number_format($unattributed) }}</span></div>
</div>

<h2>Rating breakdown</h2>
<table>
  <thead><tr><th style="width:100px">Stars</th><th style="width:100px">Count</th><th>Share</th></tr></thead>
  <tbody>
    @foreach($breakdown as $stars => $count)
      <tr>
        <td>{{ str_repeat('★', $stars) }}</td>
        <td>{{ $count }}</td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="flex:1;max-width:260px;height:8px;background:#f5f5f4;border-radius:99px;overflow:hidden">
              <div style="height:100%;background:#166534;width:{{ $total ? round($count / $total * 100) : 0 }}%"></div>
            </div>
            <span>{{ $total ? round($count / $total * 100, 1) : 0 }}%</span>
          </div>
        </td>
      </tr>
    @endforeach
  </tbody>
</table>

<h2>Feedback on guided tours</h2>
{{-- Reported as counts, never as a ranking. Guided tours at Museo de Baler
     are occasional, so a handful of ratings says almost nothing about how
     someone actually works — presenting it as a league table would invite
     exactly the wrong conclusion. --}}
@if($byGuide->isEmpty())
  <p class="note">
    No feedback in this range was tied to a guide. That is normal: most visitors here
    roam unguided, so the guide question is only asked when a tour was actually assigned.
  </p>
@else
  <table>
    <thead><tr><th>Guide</th><th style="width:100px">Ratings</th><th style="width:120px">Average</th><th>Note</th></tr></thead>
    <tbody>
      @foreach($byGuide as $row)
        <tr>
          <td><strong>{{ $row['staff']?->name ?? 'Unknown' }}</strong></td>
          <td>{{ $row['count'] }}</td>
          <td>{{ $row['avg'] }} / 5</td>
          <td>
            @if($row['sparse'])
              <span class="tag t-gold">Too few to draw conclusions</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <p class="note">
    Guided tours are occasional, so these figures are a record of what visitors said,
    not a performance ranking. Anything under five ratings is flagged.
  </p>
@endif

<h2>Recent comments</h2>
@php $withComments = $recent->filter(fn ($f) => filled($f->comment)); @endphp

@if($withComments->isEmpty())
  <p class="note">No written comments in this range.</p>
@else
  <table>
    <thead><tr><th style="width:110px">Date</th><th style="width:90px">Rating</th><th>Comment</th></tr></thead>
    <tbody>
      @foreach($withComments as $item)
        <tr>
          <td>{{ $item->submitted_at?->format('M j, Y') }}</td>
          <td>{{ str_repeat('★', (int) $item->rating) }}</td>
          <td>
            {{ $item->comment }}
            @if($item->staff)
              <br><span style="color:#78716c">Guided by {{ $item->staff->name }}</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

<p class="note">Generated {{ now()->format('F j, Y g:i A') }} by {{ auth()->user()->name }}.</p>
@endsection
