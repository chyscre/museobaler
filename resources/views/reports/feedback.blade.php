@extends('layouts.print')
@section('title', 'Feedback Report')
@section('report-title', 'Visitor Feedback')
@section('report-meta', $from->format('F j, Y') . ' – ' . $to->format('F j, Y'))

@section('downloads')
  @include('reports.partials.downloads', ['report' => 'feedback', 'params' => [
    'from' => $from->toDateString(),
    'to'   => $to->toDateString(),
  ]])
@endsection

@section('content')
<div class="tot">
  <div><span class="k">Responses</span><span class="v">{{ number_format($total) }}</span></div>
  <div><span class="k">Average rating</span><span class="v">{{ $average ? $average . ' / 5' : '—' }}</span></div>
  <div><span class="k">CSM respondents</span><span class="v">{{ number_format($csm['respondents']) }}</span></div>
  <div><span class="k">Overall SQD score</span><span class="v">{{ $csm['sqd_score'] !== null ? $csm['sqd_score'] . '%' : '—' }}{{ $csmRating ? ' · ' . $csmRating : '' }}</span></div>
  <div><span class="k">CC awareness</span><span class="v">{{ $csm['cc_awareness'] !== null ? $csm['cc_awareness'] . '%' : '—' }}</span></div>
</div>

{{-- ── ARTA Client Satisfaction Measurement ──────────────────────────
     Computed as the ARTA guidelines define them: the overall score is the
     share of Agree + Strongly Agree across every SQD answer, N/A excluded;
     CC awareness is the share of CC1 answers that were 1, 2 or 3. Anyone
     who filed feedback from the old star-only sheet is in the totals above
     but not in these tables. --}}
<h2>Client Satisfaction Measurement (ARTA)</h2>

@if($csm['respondents'] === 0)
  <p class="note">No survey responses in this range.</p>
@else
  @if($csm['client_types']->isNotEmpty() || $csm['regions']->isNotEmpty())
    <table>
      <thead><tr><th>Client type</th><th style="width:90px">Count</th><th>Region</th><th style="width:90px">Count</th></tr></thead>
      <tbody>
        @php
          $ct = $csm['client_types']->map(fn ($n, $k) => [ucfirst($k), $n])->values();
          $rg = $csm['regions']->map(fn ($n, $k) => [$k, $n])->values();
        @endphp
        @for($i = 0; $i < max($ct->count(), $rg->count()); $i++)
          <tr>
            <td>{{ $ct[$i][0] ?? '' }}</td><td>{{ $ct[$i][1] ?? '' }}</td>
            <td>{{ $rg[$i][0] ?? '' }}</td><td>{{ $rg[$i][1] ?? '' }}</td>
          </tr>
        @endfor
      </tbody>
    </table>
  @endif

  <h2>Citizen's Charter</h2>
  @foreach($csm['cc'] as $cc)
    <table>
      <thead><tr><th colspan="3">{{ $cc['code'] }}. {{ $cc['text'] }}</th></tr></thead>
      <tbody>
        @foreach($cc['counts'] as $c)
          <tr>
            <td>{{ $c['label'] }}</td>
            <td style="width:90px">{{ $c['count'] }}</td>
            <td style="width:90px">{{ $cc['total'] ? round($c['count'] / $cc['total'] * 100, 1) : 0 }}%</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach

  <h2>Service Quality Dimensions</h2>
  <table>
    <thead>
      <tr>
        <th>Item</th>
        <th style="width:52px" title="Strongly disagree">SD</th>
        <th style="width:52px" title="Disagree">D</th>
        <th style="width:52px" title="Neither">N</th>
        <th style="width:52px" title="Agree">A</th>
        <th style="width:52px" title="Strongly agree">SA</th>
        <th style="width:52px">N/A</th>
        <th style="width:80px">Responses</th>
        <th style="width:80px">Score</th>
      </tr>
    </thead>
    <tbody>
      @foreach($csm['sqd'] as $row)
        <tr>
          <td><strong>{{ $row['code'] }}</strong> {{ $row['text'] }}@if(!$row['active']) <span class="tag t-gold">retired</span>@endif</td>
          @foreach([1,2,3,4,5] as $v)<td>{{ $row['counts'][$v] }}</td>@endforeach
          <td>{{ $row['na'] }}</td>
          <td>{{ $row['responses'] }}</td>
          <td>{{ $row['score'] !== null ? $row['score'] . '%' : '—' }}</td>
        </tr>
      @endforeach
      <tr>
        <td><strong>Overall</strong></td>
        <td colspan="6"></td>
        <td><strong>{{ $csm['sqd_responses'] }}</strong></td>
        <td><strong>{{ $csm['sqd_score'] !== null ? $csm['sqd_score'] . '%' : '—' }}</strong></td>
      </tr>
    </tbody>
  </table>
  <p class="note">
    Score = share of Agree and Strongly Agree answers, with N/A left out of the denominator.
    ARTA bands: 95%+ Outstanding · 90–94.9% Very Satisfactory · 80–89.9% Satisfactory · 60–79.9% Fair · below 60% Poor.
  </p>

  @if(count($csm['app']))
    <h2>About the museum and the app</h2>
    <table>
      <thead>
        <tr>
          <th>Item</th>
          <th style="width:52px">SD</th><th style="width:52px">D</th><th style="width:52px">N</th><th style="width:52px">A</th><th style="width:52px">SA</th>
          <th style="width:52px">N/A</th><th style="width:80px">Responses</th><th style="width:80px">Score</th>
        </tr>
      </thead>
      <tbody>
        @foreach($csm['app'] as $row)
          <tr>
            <td><strong>{{ $row['code'] }}</strong> {{ $row['text'] }}@if(!$row['active']) <span class="tag t-gold">retired</span>@endif</td>
            @foreach([1,2,3,4,5] as $v)<td>{{ $row['counts'][$v] }}</td>@endforeach
            <td>{{ $row['na'] }}</td>
            <td>{{ $row['responses'] }}</td>
            <td>{{ $row['score'] !== null ? $row['score'] . '%' : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <p class="note">The museum's own questions, scored the same way. They are not part of the ARTA submission.</p>
  @endif
@endif

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
