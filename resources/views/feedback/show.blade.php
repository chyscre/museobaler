@extends('layouts.admin')
@section('title','Feedback Detail — Museo Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Feedback Detail</h2>
  </div>
  <div class="ph-right">
    <a href="{{ route('feedback.index') }}" class="btn btn-outline btn-sm">← Back</a>
  </div>
</div>
<div class="card card-p" style="max-width:560px">
  <div class="fi-row">
    <div>
      <label class="fl">Name</label>
      <div class="fi-val">{{ $feedback->visitor?->full_name ?: '—' }}</div>
    </div>
    <div>
      <label class="fl">Rating</label>
      <div class="fi-val" style="color:var(--gold-dark);font-size:20px;letter-spacing:2px">{{ str_repeat('★', $feedback->rating) }}{{ str_repeat('☆', 5 - $feedback->rating) }}</div>
    </div>
  </div>
  <div class="fi-row">
    <div>
      <label class="fl">Country</label>
      <div class="fi-val">{{ $feedback->visitor?->country ?? '—' }}</div>
    </div>
    <div>
      <label class="fl">Date</label>
      <div class="fi-val">{{ $feedback->submitted_at->format('M j, Y g:i A') }}</div>
    </div>
  </div>
  @if($feedback->client_type || $feedback->region)
  <div class="fi-row">
    <div>
      <label class="fl">Client type</label>
      <div class="fi-val">{{ ucfirst($feedback->client_type ?? '—') }}</div>
    </div>
    <div>
      <label class="fl">Region</label>
      <div class="fi-val">{{ $feedback->region ?? '—' }}</div>
    </div>
  </div>
  @endif

  {{-- The ARTA survey answers, grouped as on the paper form. Feedback
       filed before the survey existed has none. --}}
  @foreach(['cc' => "Citizen's Charter", 'sqd' => 'Service Quality Dimensions', 'app' => 'Museum & app'] as $sec => $title)
    @php $rows = collect($answers)->where('section', $sec); @endphp
    @if($rows->isNotEmpty())
      <label class="fl" style="margin-top:12px">{{ $title }}</label>
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:8px">
        @foreach($rows as $a)
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:5px 6px 5px 0;color:var(--text-3);font-family:ui-monospace,monospace;font-size:11px;white-space:nowrap;vertical-align:top">{{ $a['code'] }}</td>
            <td style="padding:5px 6px;color:var(--text-2);vertical-align:top">{{ $a['text'] }}</td>
            <td style="padding:5px 0 5px 6px;white-space:nowrap;text-align:right;vertical-align:top;font-weight:600;color:{{ $a['value'] === null ? 'var(--text-3)' : ($a['value'] >= 4 ? 'var(--green-dark)' : ($a['value'] <= 2 ? '#b91c1c' : 'var(--text)')) }}">{{ $a['label'] }}</td>
          </tr>
        @endforeach
      </table>
    @endif
  @endforeach

  <label class="fl" style="margin-top:12px">Comment</label>
  <div class="fi-val" style="white-space:pre-line;min-height:60px;line-height:1.6">{{ $feedback->comment ?: 'No comment provided.' }}</div>
</div>
@endsection
