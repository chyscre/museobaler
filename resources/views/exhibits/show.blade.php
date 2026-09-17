@extends('layouts.admin')
@section('title', $exhibit->name . ' — Museo Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>{{ $exhibit->name }}</h2>
    <p>Exhibit Details</p>
  </div>
  <div class="ph-right">
    <a href="{{ route('exhibits.index') }}" class="btn btn-outline btn-sm">← Back</a>
    <a href="{{ route('exhibits.edit', $exhibit) }}" class="btn btn-green btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Edit
    </a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
  <div>
    @if($exhibit->image)
    <img src="{{ $exhibit->image_url }}" style="width:100%;border-radius:var(--r);margin-bottom:16px;max-height:260px;object-fit:cover">
    @endif
    <div class="card card-p">
      <label class="fl">Exhibit Code</label><div class="fi-val" style="margin-bottom:12px">{{ $exhibit->exhibit_code }}</div>
      <label class="fl">Name</label><div class="fi-val" style="margin-bottom:12px">{{ $exhibit->name }}</div>
      <label class="fl">Category</label><div class="fi-val" style="margin-bottom:12px"><span class="badge b-gold">{{ $exhibit->category?->name ?? '—' }}</span></div>
      <label class="fl">Floor / Hall</label><div class="fi-val" style="margin-bottom:12px">{{ $exhibit->floor }} · {{ $exhibit->hall }}</div>
      <label class="fl">Authors</label><div class="fi-val" style="margin-bottom:12px">{{ $exhibit->authors ?? '—' }}</div>
      <label class="fl">Status</label><div class="fi-val" style="margin-bottom:12px"><span class="badge {{ $exhibit->status ? 'b-green' : 'b-red' }}">{{ $exhibit->status ? 'Active' : 'Archived' }}</span></div>
      <label class="fl">Total Scans</label><div class="fi-val" style="font-weight:700;color:var(--green-dark)">{{ number_format($exhibit->scans_count) }}</div>
    </div>
  </div>
  <div>
    <div class="card card-p" style="margin-bottom:16px">
      <label class="fl">Description</label>
      <div class="fi-val" style="white-space:pre-line;line-height:1.7;margin-bottom:12px">{{ $exhibit->description ?? '—' }}</div>
      @if($exhibit->fun_facts)
      <label class="fl">Fun Facts</label>
      <div class="fi-val" style="white-space:pre-line;line-height:1.7">{{ $exhibit->fun_facts }}</div>
      @endif
    </div>
    <div class="card card-p">
      <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:12px">Translations</div>
      @forelse($exhibit->translations as $t)
      <div style="background:#f9f5f0;border-radius:8px;padding:10px 12px;margin-bottom:8px">
        <div style="font-size:12px;font-weight:700;color:var(--text)">{{ $t->language_label }} <span style="color:var(--text-3);font-weight:400">({{ $t->language_code }})</span></div>
        <div style="font-size:12px;color:var(--text-3);margin-top:4px">{{ Str::limit($t->title, 60) }}</div>
        @if($t->audio_file)
        <audio controls style="height:28px;width:100%;margin-top:6px"><source src="{{ $t->audio_url }}"></audio>
        @endif
      </div>
      @empty
      <p style="font-size:13px;color:var(--text-3)">No translations yet.</p>
      @endforelse
    </div>
  </div>
</div>
@endsection
