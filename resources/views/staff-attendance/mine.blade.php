@extends($layout)
@section('title', 'My Attendance — Museo de Baler')

@section('content')
@if($layout === 'layouts.admin')
<div class="ph">
  <div class="ph-left">
    <h2>My Attendance</h2>
  </div>
</div>
@endif

@if($geofenceOff)
  {{-- ATTENDANCE_GEOFENCE=false in .env. Never true in production - the
       service refuses to read the setting there - so this banner only ever
       appears on a machine that is being worked on. --}}
  <div style="margin-bottom:16px;padding:11px 14px;border-radius:10px;background:#fffbeb;border:1.5px solid #fcd34d;font-size:12.5px;color:#92400e;line-height:1.55;display:flex;gap:9px;align-items:flex-start">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
      <strong>Testing mode — the location check is off.</strong>
      Scans from anywhere are being accepted. Remove <code>ATTENDANCE_GEOFENCE=false</code> from <code>.env</code> before this is used for real.
    </div>
  </div>
@endif

{{-- Today --}}
<div class="card card-p-lg" style="margin-bottom:22px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div>
      <h3 class="sec-title" style="margin-bottom:2px">{{ now()->format('l, F j') }}</h3>
      <p style="font-size:13px;color:var(--text-3)">
        @if($today['in'])
          Checked in {{ $today['in']->scanned_at->format('g:i A') }}
          @if($today['out']) · out {{ $today['out']->scanned_at->format('g:i A') }} @endif
        @else
          Not checked in yet
        @endif
      </p>
    </div>
    <span class="badge {{ $today['status'] === 'Present' ? 'b-green' : ($today['status'] === 'Late' ? 'b-gold' : 'b-gray') }}">
      {{ $today['status'] }}
    </span>
  </div>

  @if(!$today['out'])
    <button id="scanBtn" class="btn btn-green" style="width:100%;margin-top:16px">
      {{ $today['in'] ? 'Scan to check out' : 'Scan to check in' }}
    </button>
  @else
    <div style="margin-top:16px;padding:12px;border-radius:10px;background:var(--green-pale);font-size:13px;font-weight:600;color:var(--green-dark);text-align:center">
      Your day is recorded. Worked {{ intdiv($today['worked_minutes'] ?? 0, 60) }}h {{ ($today['worked_minutes'] ?? 0) % 60 }}m.
    </div>
  @endif

  <div id="scanArea" style="display:none;margin-top:16px">
    <div id="reader" style="width:100%;border-radius:12px;overflow:hidden"></div>
    <p id="scanMsg" style="font-size:13px;color:var(--text-3);text-align:center;margin-top:10px">Point your camera at the screen in the staff room.</p>
    <button id="cancelBtn" class="btn btn-outline btn-sm" style="width:100%;margin-top:10px">Cancel</button>
  </div>

  <div id="result" style="display:none;margin-top:16px;padding:14px;border-radius:10px;font-size:14px;font-weight:600;text-align:center"></div>
</div>

{{-- The museum pin, set from here rather than the desktop: this is the one
     screen a phone reaches, and a phone is the only thing with a GPS. --}}
<div class="card card-p-lg" style="margin-bottom:16px">
  <h3 class="sec-title">Museum pin</h3>
  <p class="sec-sub">If check-in says you are hundreds of metres away while you are standing at the door, the pin is wrong. Stand at the entrance and set it from this phone.</p>
  <button type="button" id="setPinBtn" class="btn btn-outline btn-sm">Set museum pin to my location</button>
  <div id="pinResult" style="display:none;margin-top:12px;padding:12px;border-radius:10px;font-size:13px;font-weight:600"></div>
</div>

{{-- This month --}}
<div class="card card-p-lg">
  <h3 class="sec-title">{{ $monthFrom->format('F Y') }}</h3>
  <p class="sec-sub">Your own record. Corrections are filed by the museum administrator.</p>

  <div class="tbl-scroll">
  <table style="width:100%;border-collapse:collapse;margin-top:14px">
    <thead>
      <tr style="border-bottom:1.5px solid var(--border)">
        @foreach(['Date','In','Out','Worked','Status'] as $h)
          <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($days as $day)
        <tr style="border-bottom:1px solid var(--border-light)">
          <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $day['date']->format('D, M j') }}</td>
          <td style="padding:10px;font-size:13px">{{ $day['in']?->scanned_at->format('g:i A') ?? '—' }}</td>
          <td style="padding:10px;font-size:13px">{{ $day['out']?->scanned_at->format('g:i A') ?? '—' }}</td>
          <td style="padding:10px;font-size:13px">
            @if($day['worked_minutes'] !== null)
              {{ intdiv($day['worked_minutes'], 60) }}h {{ $day['worked_minutes'] % 60 }}m
            @else — @endif
          </td>
          <td style="padding:10px">
            <span class="badge {{ $day['status'] === 'Present' ? 'b-green' : ($day['status'] === 'Late' ? 'b-gold' : ($day['status'] === 'Absent' ? 'b-red' : 'b-gray')) }}">
              {{ $day['status'] }}@if($day['late_minutes'] > 0) · {{ $day['late_minutes'] }}m @endif
            </span>
            @if($day['is_manual'])
              <span class="badge b-gray" title="Entered by hand and approved by the Tourism office">Manual</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>

@push('scripts')
{{-- Served locally: the museum network could not reach unpkg, and a scanner
     that fails to load means nobody can check in. html5-qrcode 2.3.8. --}}
<script src="{{ asset('js/vendor/html5-qrcode.min.js') }}"></script>
<script>
(function () {
  const scanBtn   = document.getElementById('scanBtn');
  const cancelBtn = document.getElementById('cancelBtn');
  const area      = document.getElementById('scanArea');
  const result    = document.getElementById('result');
  const msg       = document.getElementById('scanMsg');

  if (!scanBtn) return;

  let scanner = null;
  let busy    = false;

  // Say so on arrival, not after the tap. Somebody standing in the staff
  // room at 7:58 should not have to press a button to learn the address bar
  // is wrong.
  if (!window.isSecureContext) {
    result.style.display    = 'block';
    result.style.background = '#fef2f2';
    result.style.color      = '#991b1b';
    result.style.fontWeight = '500';
    result.textContent      = 'This page was opened over http://, so the camera will not work. Open it using the https:// address instead.';
  }

  function show(text, ok) {
    result.style.display = 'block';
    result.textContent   = text;
    result.style.background = ok ? 'var(--green-pale)' : '#fef2f2';
    result.style.color      = ok ? 'var(--green-dark)' : '#991b1b';
  }

  // Location is requested up front, not at submit time: the browser prompt
  // takes a few seconds the first time, and a code only lives for 60.
  function position() {
    return new Promise(resolve => {
      if (!navigator.geolocation) return resolve(null);
      navigator.geolocation.getCurrentPosition(
        p => resolve(p),
        () => resolve(null),
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
      );
    });
  }

  async function submit(code) {
    if (busy) return;
    busy = true;
    msg.textContent = 'Checking your location…';

    const pos = await position();

    try {
      const res = await fetch('{{ route('my.attendance.scan') }}', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
          code,
          latitude:  pos ? pos.coords.latitude  : null,
          longitude: pos ? pos.coords.longitude : null,
          accuracy:  pos ? Math.round(pos.coords.accuracy) : null,
        }),
      });

      const data = await res.json();
      show(data.message || 'Something went wrong.', data.ok === true);

      if (data.ok && !data.noop) {
        await stop();
        setTimeout(() => window.location.reload(), 1600);
        return;
      }
    } catch (e) {
      show('Could not reach the server. Check your connection.', false);
    }

    // Left running on failure so an expired code can simply be rescanned.
    busy = false;
    msg.textContent = 'Point your camera at the screen in the staff room.';
  }

  async function stop() {
    if (!scanner) return;
    try { await scanner.stop(); } catch (e) {}
    scanner = null;
    area.style.display = 'none';
  }

  // Why the camera would not open, in words the person can act on. One
  // generic "allow camera access" line used to cover all of these, and the
  // commonest cause - the page was opened over plain http, where browsers
  // refuse the camera outright - has nothing to do with permission and no
  // amount of tapping Allow will fix it.
  function cameraProblem(e) {
    if (!window.isSecureContext) {
      return 'The camera only works over a secure connection. Open this page using the https:// address, not http://.';
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return 'This browser cannot use the camera from a web page. Try Chrome or Safari.';
    }
    const name = (e && (e.name || e)) + '';
    if (/NotAllowed|Permission/i.test(name)) {
      return 'Camera access was refused. Allow it for this site in your browser settings and try again.';
    }
    if (/NotFound|Overconstrained|DevicesNotFound/i.test(name)) {
      return 'No rear camera was found on this device.';
    }
    if (/NotReadable|TrackStart|AbortError/i.test(name)) {
      return 'The camera is busy in another app. Close it and try again.';
    }
    return 'Cannot open the camera. Allow camera access and try again.';
  }

  scanBtn.addEventListener('click', async () => {
    result.style.display = 'none';

    // Fail before showing an empty viewfinder, and before touching the
    // library: on plain http the answer is already known.
    if (!window.isSecureContext) {
      show(cameraProblem(null), false);
      return;
    }

    if (typeof Html5Qrcode === 'undefined') {
      show('The scanner could not load. Check the phone has an internet connection and reload.', false);
      return;
    }

    area.style.display = 'block';

    scanner = new Html5Qrcode('reader');
    try {
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 220, height: 220 } },
        text => submit(text),
        () => {}
      );
    } catch (e) {
      show(cameraProblem(e), false);
      area.style.display = 'none';
      scanner = null;
    }
  });

  cancelBtn.addEventListener('click', stop);

  // Museum pin
  const pinBtn = document.getElementById('setPinBtn');
  const pinOut = document.getElementById('pinResult');
  function pinSay(text, ok) {
    pinOut.style.display    = 'block';
    pinOut.textContent      = text;
    pinOut.style.background = ok ? 'var(--green-pale)' : '#fef2f2';
    pinOut.style.color      = ok ? 'var(--green-dark)' : '#991b1b';
  }
  pinBtn.addEventListener('click', async () => {
    if (!confirm('Move the museum pin to where this phone is right now? Do this standing at the museum entrance.')) return;
    pinBtn.disabled = true;
    pinSay('Getting a GPS fix…', true);
    const pos = await position();
    if (!pos) { pinSay('Could not get a location. Allow location access and try again outdoors.', false); pinBtn.disabled = false; return; }
    try {
      const res = await fetch('{{ route('my.attendance.pin') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: Math.round(pos.coords.accuracy) }),
      });
      const data = await res.json();
      pinSay(data.message || 'Something went wrong.', data.ok === true);
    } catch (e) {
      pinSay('Could not reach the server. Check your connection.', false);
    }
    pinBtn.disabled = false;
  });
})();
</script>
@endpush
@endsection
