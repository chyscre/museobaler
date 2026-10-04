{{-- The View Breakdown window for a party. Built by
     App\Support\VisitDetails::forGroup(); dropped into details-modal. --}}
@php
  $payments = $entries->where('kind', 'payment');
@endphp
<div class="dt-head">
  <div>
    <div class="dt-title">{{ $group->label }}</div>
    <div class="dt-sub">{{ $group->visit_date->format('l, F j, Y') }} · {{ $group->group_type }} · {{ $group->visitor_type }}</div>
  </div>
  <div class="dt-ref">
    @forelse($payments as $p)
      <code>{{ $p->reference }}</code>
    @empty
      <span class="dt-muted">No transaction number</span>
    @endforelse
  </div>
</div>

<dl class="dt-grid">
  <dt>Signed in by</dt>
  <dd>{{ $group->contact_name }}{{ $group->contact_phone ? ' · ' . $group->contact_phone : '' }}</dd>
  <dt>Headcount</dt>
  <dd>{{ $group->headcount }} · {{ $members->count() }} joined in the app{{ $notInApp ? ', ' . $notInApp . ' counted at the desk only' : '' }}</dd>
  <dt>Fee</dt>
  <dd>
    ₱{{ number_format((float) $group->total_fee, 2) }}
    <span class="badge {{ $group->payment_status === 'Paid' ? 'b-green' : ($group->payment_status === 'Unpaid' ? 'b-red' : 'b-gray') }}">{{ $group->payment_status }}</span>
    @if((float) $group->refunded_amount > 0)
      · ₱{{ number_format((float) $group->refunded_amount, 2) }} refunded
    @endif
  </dd>
  @if($group->join_code)
    <dt>Group code</dt>
    <dd><code>{{ $group->join_code }}</code>{{ $group->visit_date->isToday() ? '' : ' · expired' }}</dd>
  @endif
  <dt>Registered by</dt>
  <dd>{{ $group->registeredBy?->name ?? '—' }} · {{ $group->created_at->format('g:i A') }}</dd>
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

<div class="dt-section">Members who joined <span class="dt-muted">· {{ $members->count() }}</span></div>
@if($members->isEmpty())
  <p class="dt-muted" style="margin:4px 0 0">Nobody has joined with the group code. The party is counted by its headcount.</p>
@else
  <div class="dt-table-wrap">
    <table class="dt-table">
      <thead><tr><th>Name</th><th>Category</th><th>Status</th><th>Joined</th><th>Scans</th></tr></thead>
      <tbody>
        @foreach($members as $m)
          <tr>
            <td>{{ $m['visitor']->full_name }}</td>
            <td>{{ $m['category'] }}</td>
            <td><span class="badge {{ $m['status'] === 'Cleared' ? 'b-green' : 'b-red' }}">{{ $m['status'] }}</span></td>
            <td>{{ $m['joined'] ? $m['joined']->format('g:i A') : '—' }}</td>
            <td>
              @if($m['scans']->isEmpty())
                <span class="dt-muted">None</span>
              @else
                <details>
                  <summary>{{ $m['scans']->count() }} {{ $m['scans']->count() === 1 ? 'exhibit' : 'exhibits' }}</summary>
                  @include('records.partials.scan-log', ['scans' => $m['scans']])
                </details>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
