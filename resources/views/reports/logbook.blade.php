@extends('layouts.print')
@section('title', 'Daily Logbook — ' . $date->format('M j, Y'))
@section('report-title', 'Daily Visitor Logbook')
@section('report-meta', $date->format('l, F j, Y'))

@section('actions')
  <a href="{{ route('reports.logbook.csv') }}?date={{ $date->toDateString() }}">Download CSV</a>
@endsection

@section('content')
<div class="tot">
  <div><span class="k">Total people</span><span class="v">{{ number_format($headcount) }}</span></div>
  <div><span class="k">Entries</span><span class="v">{{ number_format($visitors->count() + $groups->count()) }}</span></div>
  <div><span class="k">Collected</span><span class="v">PHP {{ number_format($collected, 2) }}</span></div>
  @if($outstanding > 0)
    <div><span class="k">Outstanding</span><span class="v" style="color:#991b1b">PHP {{ number_format($outstanding, 2) }}</span></div>
  @endif
</div>

@if($groups->isNotEmpty())
  <h2>Groups</h2>
  <table>
    <thead>
      <tr>
        <th style="width:70px">Time</th>
        <th>Group / contact</th>
        <th style="width:80px">Type</th>
        <th style="width:50px">Pax</th>
        <th>From</th>
        <th style="width:80px">Fee</th>
        <th style="width:70px">Payment</th>
      </tr>
    </thead>
    <tbody>
      @foreach($groups as $group)
        <tr>
          <td>{{ $group->created_at->format('g:i A') }}</td>
          <td>
            <strong>{{ $group->group_name ?: $group->contact_name }}</strong>
            @if($group->group_name)<br><span style="color:#78716c">{{ $group->contact_name }}</span>@endif
          </td>
          <td>{{ $group->visitor_type }}</td>
          <td>{{ $group->headcount }}</td>
          <td>{{ $group->city ?: $group->country }}</td>
          <td>{{ $group->total_fee > 0 ? number_format($group->total_fee, 2) : '—' }}</td>
          <td>
            <span class="tag {{ $group->payment_status === 'Paid' ? 't-green' : ($group->payment_status === 'Unpaid' ? 't-red' : 't-gray') }}">
              {{ $group->payment_status }}
            </span>
          </td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3">Group subtotal</td>
        <td>{{ $groups->sum('headcount') }}</td>
        <td></td>
        <td>{{ number_format($groups->sum('total_fee'), 2) }}</td>
        <td></td>
      </tr>
    </tfoot>
  </table>
@endif

<h2>Individual visitors</h2>
@if($visitors->isEmpty())
  <p class="note">No individual visitors recorded on this date.</p>
@else
  <table>
    <thead>
      <tr>
        <th style="width:70px">Time</th>
        <th>Name</th>
        <th style="width:80px">Type</th>
        <th style="width:50px">Age</th>
        <th>From</th>
        <th style="width:70px">Entered</th>
        <th style="width:80px">Fee</th>
        <th style="width:70px">Payment</th>
      </tr>
    </thead>
    <tbody>
      @foreach($visitors as $visitor)
        <tr>
          <td>{{ $visitor->created_at->format('g:i A') }}</td>
          <td><strong>{{ $visitor->full_name }}</strong></td>
          <td>{{ $visitor->visitor_type }}</td>
          <td>{{ $visitor->age ?: '—' }}</td>
          <td>{{ $visitor->city ?: $visitor->country }}</td>
          {{-- Says how the record got in, so a run of "recovered" rows after
               a brownout is visible rather than looking like normal traffic. --}}
          <td>{{ ucfirst($visitor->source) }}</td>
          <td>{{ $visitor->admission_fee > 0 ? number_format($visitor->admission_fee, 2) : '—' }}</td>
          <td>
            <span class="tag {{ $visitor->payment_status === 'Paid' ? 't-green' : ($visitor->payment_status === 'Unpaid' ? 't-red' : 't-gray') }}">
              {{ $visitor->payment_status }}
            </span>
            @if($visitor->visitor_type === 'Local' && !$visitor->id_verified)
              <span class="tag t-gold">ID?</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

<p class="note">
  Generated {{ now()->format('F j, Y g:i A') }} by {{ auth()->user()->name }}.
  Figures are taken from the visitor records for this date; "Entered" shows whether the
  visitor registered on their own phone, on the counter tablet, or was keyed in by staff.
</p>

<div class="sign">
  <div>
    <div class="line"></div>
    <div>Prepared by</div>
    <div class="role">Front desk</div>
  </div>
  <div>
    <div class="line"></div>
    <div>Verified by</div>
    <div class="role">Museum Administrator</div>
  </div>
  <div>
    <div class="line"></div>
    <div>Noted by</div>
    <div class="role">Municipal Tourism Office</div>
  </div>
</div>
@endsection
