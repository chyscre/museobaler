{{-- The View Details window for one visitor on one day. Built by
     App\Support\VisitDetails::forVisitor(); dropped into details-modal. --}}
@php
  $tones = ['green' => 'b-green', 'gold' => 'b-gold', 'gray' => 'b-gray', 'red' => 'b-red'];
  $payments = $entries->where('kind', 'payment');
@endphp
<div class="dt-head">
  <div>
    <div class="dt-title">{{ $visitor->full_name }}</div>
    <div class="dt-sub">{{ $day->format('l, F j, Y') }}</div>
  </div>
  <div class="dt-ref">
    @forelse($payments as $p)
      <code>{{ $p->reference }}</code>
    @empty
      <span class="dt-muted">No transaction number</span>
    @endforelse
  </div>
</div>

<div class="dt-section">Admission</div>
<dl class="dt-grid">
  <dt>Account</dt>
  <dd>{{ $account }}</dd>
  <dt>Visitor type</dt>
  <dd>{{ $visit?->visitor_type ?? $visitor->visitor_type }}{{ $visitor->location ? ' · ' . $visitor->location : '' }}</dd>
  <dt>Category</dt>
  <dd>{{ $category ?? '—' }}</dd>
  <dt>Fee</dt>
  <dd>{{ $fee }}</dd>
  <dt>Status</dt>
  <dd><span class="badge {{ $status === 'Cleared' ? 'b-green' : ($day->isToday() ? 'b-red' : 'b-gray') }}">{{ $status }}</span></dd>
  @if($group)
    <dt>Group</dt>
    <dd>{{ $group->label }} · signed in by {{ $group->contact_name }}{{ $visit?->joined_group_at ? ' · joined ' . $visit->joined_group_at->format('g:i A') : '' }}</dd>
  @endif
  @if($companions)
    <dt>Brought along</dt>
    <dd>{{ collect($companions)->map(fn ($c) => $c['count'] . ' ' . $c['name'])->implode(', ') }} · free · {{ $headcount }} people in all</dd>
  @endif
</dl>

@foreach($entries as $p)
  @if(!empty($p->breakdown['lines']))
    <div class="dt-receipt">
      <div class="dt-receipt-hd">
        <code>{{ $p->reference }}</code>
        <span>{{ $p->kind === 'refund' ? 'Refund' : 'Payment' }} · {{ $p->recorded_at->format('g:i A') }}{{ $p->recordedBy ? ' · ' . $p->recordedBy->name : '' }}</span>
      </div>
      @foreach($p->breakdown['lines'] as $l)
        <div class="dt-line"><span>{{ $l['count'] }} × {{ $l['label'] }}</span><span>₱{{ number_format((float) $l['amount'], 2) }}</span></div>
      @endforeach
      <div class="dt-line dt-total"><span>{{ $p->kind === 'refund' ? 'Handed back' : 'Total' }}</span><span>₱{{ number_format((float) $p->amount, 2) }}</span></div>
    </div>
  @endif
@endforeach

<div class="dt-section">Time on site</div>
<dl class="dt-grid">
  <dt>Admitted at the desk</dt>
  <dd>{{ $admitted ? $admitted->format('g:i A') : '—' }}</dd>
  <dt>Geofence</dt>
  <dd><span class="badge {{ $tones[$presence['tone']] }}">{{ $presence['state'] }}</span></dd>
  <dt>Arrived</dt>
  <dd>{{ $presence['arrived'] ? $presence['arrived']->format('g:i A') : '—' }}</dd>
  <dt>Last seen on site</dt>
  <dd>{{ $presence['last_seen'] ? $presence['last_seen']->format('g:i A') : '—' }}</dd>
  <dt>Duration</dt>
  <dd>{{ $presence['duration'] ?? '—' }}</dd>
</dl>
<p class="dt-note">The app reports when it is put away as well as when it leaves the museum, so “last seen” is the last moment the phone confirmed they were inside.</p>

<div class="dt-section">Exhibits scanned <span class="dt-muted">· {{ $scans->count() }}</span></div>
@include('records.partials.scan-log', ['scans' => $scans])
