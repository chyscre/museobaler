{{-- An attendance row the fence made for a phone that never registered:
     there is no admission or scan history, only its arrival and stay. --}}
@php
  $tones = ['green' => 'b-green', 'gold' => 'b-gold', 'gray' => 'b-gray', 'red' => 'b-red'];
@endphp
<div class="dt-head">
  <div>
    <div class="dt-title">{{ $attendance->visitor_name ?: 'Anonymous' }}</div>
    <div class="dt-sub">{{ $day->format('l, F j, Y') }} · not yet registered</div>
  </div>
</div>

<div class="dt-section">Time on site</div>
<dl class="dt-grid">
  <dt>Geofence</dt>
  <dd><span class="badge {{ $tones[$presence['tone']] }}">{{ $presence['state'] }}</span></dd>
  <dt>Arrived</dt>
  <dd>{{ $presence['arrived'] ? $presence['arrived']->format('g:i A') : '—' }}</dd>
  <dt>Last seen on site</dt>
  <dd>{{ $presence['last_seen'] ? $presence['last_seen']->format('g:i A') : '—' }}</dd>
  <dt>Duration</dt>
  <dd>{{ $presence['duration'] ?? '—' }}</dd>
  <dt>Accuracy</dt>
  <dd>{{ $attendance->accuracy ? '±' . $attendance->accuracy . 'm' : '—' }}</dd>
</dl>
<p class="dt-note">No account, so there is no admission or scan history for this phone.</p>
