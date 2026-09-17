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
      <div class="fi-val">{{ ($feedback->visitor?->first_name ?? '') . ' ' . ($feedback->visitor?->last_name ?? '') ?: '—' }}</div>
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
  <label class="fl">Comment</label>
  <div class="fi-val" style="white-space:pre-line;min-height:80px;line-height:1.6">{{ $feedback->comment ?: 'No comment provided.' }}</div>
</div>
@endsection
