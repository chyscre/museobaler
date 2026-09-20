@extends('layouts.admin')
@section('title', 'Front Desk — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Front Desk</h2>
    <p>Register arrivals and collect admission — this replaces the logbook</p>
  </div>
  <div class="ph-right" style="display:flex;gap:8px;align-items:center">
    @include('partials.report-menu', [
      'id'      => 'deskReportMenu',
      'mode'    => 'date',
      'reports' => [
        ['label' => "Today's logbook", 'url' => route('reports.logbook'), 'csv' => route('reports.logbook.csv')],
      ],
    ])
    <a href="{{ route('desk.poster') }}" target="_blank" class="btn btn-outline btn-sm">Entrance poster</a>
    <a href="{{ route('tours.index') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><circle cx="12" cy="8" r="1"/></svg>
      Guided Tours
    </a>
  </div>
</div>

<div class="stats-row" style="margin-bottom:18px">
  <div class="stat">
    <div class="stat-ico green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($todayHeads) }}</div><div class="stat-lbl">People today</div></div>
  </div>
  <div class="stat">
    <div class="stat-ico blue">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    </div>
    <div><div class="stat-val">{{ number_format($todayCount) }}</div><div class="stat-lbl">Entries logged</div></div>
  </div>
</div>

{{-- The join code for the group just registered, in type the party can read
     from across the counter. Members type it into the app to be counted as
     part of this group instead of as extra visitors owing a second fee. It
     is still shown in the list below after this flash is gone. --}}
@if(session('join_code'))
  @php
    $jc = session('join_code');
  @endphp
  <div class="card" style="margin-bottom:18px;padding:18px 22px;border:1.5px solid #86efac;background:var(--green-pale);display:flex;align-items:center;gap:22px;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <div style="font-size:11px;font-weight:700;color:var(--green-dark);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px">Group code for {{ $jc['label'] }}</div>
      <div style="font-size:13px;color:var(--text-2);line-height:1.55">
        Read this out to the party. Anyone in it who opens the app and picks
        <strong>Group</strong> or <strong>School</strong> can enter it — they will be counted
        in this group, owe nothing themselves, and unlock when the group does.
      </div>
    </div>
    <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:34px;font-weight:700;letter-spacing:.18em;color:var(--green-dark);background:#fff;border:1.5px solid #86efac;border-radius:12px;padding:10px 22px;user-select:all">{{ $jc['code'] }}</div>
  </div>
@endif

{{-- The three things the desk does, each folded until it is needed. A
     validation error re-opens the form it came from so the message is not
     hidden behind a closed card. --}}
@php
  $groupErr = $errors->hasAny(['contact_name', 'headcount', 'group_type', 'paying_count']);
  $soloErr  = $errors->any() && !$groupErr;
@endphp

<div style="display:grid;gap:14px">

  {{-- ── One visitor ─────────────────────────────────────────── --}}
  <details class="fold" {{ $soloErr ? 'open' : '' }}>
    <summary>
      <div class="fold-ico">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div class="fold-text">
        <div class="fold-title">One visitor</div>
        <div class="fold-sub">Someone arriving on their own, or without a phone</div>
      </div>
      <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    </summary>

    <div class="fold-body">
      <form method="POST" action="{{ route('desk.visitors.store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <label class="fl">First name *</label>
            <input class="fi" name="first_name" required value="{{ old('first_name') }}" autocomplete="off">
          </div>
          <div>
            <label class="fl">Last name *</label>
            <input class="fi" name="last_name" required value="{{ old('last_name') }}" autocomplete="off">
          </div>
        </div>

        {{-- Three buttons rather than a dropdown: the desk taps this on every
             single arrival, so it has to be one motion, not two. --}}
        <div style="margin-top:14px">
          <label class="fl">Visitor type *</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:6px">
            {{-- Block form, never the one-line parenthesised form of this
                 directive: Blade pairs each opener with the next closer
                 non-greedily, before it strips comments, so a single inline
                 one in a file that also has blocks swallows everything up to
                 the next closer as raw PHP. That broke this page twice. --}}
            @php
              $feeHint = 'PHP ' . number_format(\App\Models\MuseumInfo::admissionFee(), 2);
            @endphp
            @foreach(['Local' => 'Free · check ID', 'Tourist' => $feeHint, 'Foreign' => $feeHint] as $type => $hint)
              <label class="vtype-opt">
                <input type="radio" name="visitor_type" value="{{ $type }}" required
                       {{ old('visitor_type') === $type ? 'checked' : '' }}>
                <span>
                  <strong>{{ $type }}</strong>
                  <small>{{ $hint }}</small>
                </span>
              </label>
            @endforeach
          </div>
        </div>

        {{-- Free admission is for Baler residents, so a local names a barangay
             (what their ID says) and the town is filled in as Baler; anyone
             else says where they are from. The two swap with the type buttons. --}}
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-top:14px">
          <div id="fromCity">
            <label class="fl">From (city or country)</label>
            <input class="fi" name="city" value="{{ old('city') }}" placeholder="Baler, Quezon City, Japan…" autocomplete="off">
          </div>
          <div id="fromBarangay" hidden>
            <label class="fl">Barangay</label>
            <select class="fi" name="barangay">
              <option value="">— Not stated —</option>
              @foreach(\App\Support\BalerBarangays::ALL as $brgy)
              <option value="{{ $brgy }}" @selected(old('barangay') === $brgy)>{{ $brgy }}</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="fl">Age</label>
            <input class="fi" name="age" type="number" min="1" max="120" value="{{ old('age') }}">
          </div>
        </div>

        <button type="submit" class="btn btn-green" style="width:100%;margin-top:18px;justify-content:center">Register visitor</button>
      </form>
    </div>
  </details>

  {{-- ── A group ─────────────────────────────────────────────── --}}
  <details class="fold" {{ $groupErr ? 'open' : '' }}>
    <summary>
      <div class="fold-ico">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div class="fold-text">
        <div class="fold-title">A group arriving together</div>
        <div class="fold-sub">Four or five from out of town, a family, or a school tour — one entry, one payment</div>
      </div>
      <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    </summary>

    <div class="fold-body">
      <form method="POST" action="{{ route('desk.groups.store') }}">
        @csrf
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">
          <div>
            <label class="fl">Who is signing in *</label>
            <input class="fi" name="contact_name" required value="{{ old('contact_name') }}" placeholder="Name of the person at the desk" autocomplete="off">
          </div>
          <div>
            <label class="fl">How many *</label>
            <input class="fi" name="headcount" type="number" min="1" max="500" required value="{{ old('headcount', 4) }}" id="gHead">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px">
          <div>
            <label class="fl">Group type *</label>
            <select class="fi" name="group_type">
              <option value="Group">Group of friends / co-travellers</option>
              <option value="Family">Family</option>
              <option value="School">School / educational tour</option>
              <option value="Tour">Tour agency</option>
            </select>
          </div>
          <div>
            <label class="fl">Visitor type *</label>
            <select class="fi" name="visitor_type" id="gType">
              <option value="Tourist">Tourist (other province)</option>
              <option value="Local">Local (Baler)</option>
              <option value="Foreign">Foreign</option>
            </select>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px">
          <div>
            <label class="fl">How many are from Baler? <span style="font-weight:400;text-transform:none;color:var(--text-3)">(free — check their IDs)</span></label>
            {{-- Mixed parties are the common case: out-of-town relatives
                 visiting with a local. This used to ask for "paying heads",
                 which nobody asks a party at a counter, so it defaulted to
                 everyone paying and the local got charged. "Anyone from
                 Baler?" is the question the desk actually asks. --}}
            <input class="fi" name="local_count" type="number" min="0" max="500" id="gLocal" value="{{ old('local_count', 0) }}">
          </div>
          <div>
            <label class="fl">From</label>
            <input class="fi" name="city" value="{{ old('city') }}" placeholder="City or country" autocomplete="off">
          </div>
        </div>

        <div id="gFee" style="margin-top:14px;padding:11px 14px;border-radius:8px;background:var(--green-pale);font-size:13px;font-weight:600;color:var(--green-dark)"></div>

        <button type="submit" class="btn btn-green" style="width:100%;margin-top:14px;justify-content:center">Register group</button>
      </form>
    </div>
  </details>

  {{-- ── Staff check-in code ─────────────────────────────────── --}}
  {{-- Lives on the desk page because a museum with one computer and no spare
       tablet has nowhere else to put it. Same 60-second rotation as the
       full-screen version, so nothing about the guarantee changes. --}}
  <details class="fold" id="checkinPanel">
    <summary>
      <div class="fold-ico">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3z"/><path d="M20 14v7h-3"/></svg>
      </div>
      <div class="fold-text">
        <div class="fold-title">Staff check-in code</div>
        <div class="fold-sub">Staff scan this on arrival with their own phone</div>
      </div>
      <svg class="fold-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    </summary>

    <div class="fold-body">
      <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap">
        <img id="deskQr" src="{{ route('attendance.kiosk.qr') }}?w=0" alt="Staff check-in code"
             style="width:180px;height:180px;border:1px solid var(--border);border-radius:12px;background:#fff;flex-shrink:0">

        <div style="flex:1;min-width:240px">
          <ol style="font-size:13px;color:var(--text-2);line-height:1.8;padding-left:18px;margin:0 0 14px">
            <li>Open the admin panel on your phone and sign in</li>
            <li>Tap <strong>My Attendance</strong></li>
            <li>Point your camera at this code</li>
          </ol>

          <div style="display:flex;align-items:center;gap:10px;max-width:280px">
            <div style="flex:1;height:6px;background:var(--border-light);border-radius:99px;overflow:hidden">
              <div id="deskQrFill" style="height:100%;background:var(--green-dark);width:100%;transition:width 1s linear"></div>
            </div>
            <span style="font-size:12px;color:var(--text-3);white-space:nowrap;min-width:48px;text-align:right">
              <span id="deskQrCount">--</span>s
            </span>
          </div>

          <div style="display:flex;align-items:center;gap:14px;margin-top:12px;font-size:12px;color:var(--text-3)">
            <span>Rotates every {{ \App\Services\AttendanceQrService::WINDOW_SECONDS }}s · location checked</span>
            <a href="{{ route('attendance.kiosk') }}" target="_blank" style="color:var(--green-dark);font-weight:600;text-decoration:none;white-space:nowrap">Open full screen →</a>
          </div>
        </div>
      </div>
    </div>
  </details>

</div>

{{-- Today's entries --}}
<div class="card card-p-lg" style="margin-top:18px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h3 class="sec-title" style="margin-bottom:0">Registered today</h3>
    <a href="{{ route('records.index') }}" style="font-size:12px;font-weight:600;color:var(--green-dark);text-decoration:none">All records →</a>
  </div>

  {{-- Groups first. Each row is the whole party: the code the members need,
       how many of them have joined from their phones, and the one payment
       that unlocks all of them. --}}
  @if($recentGroups->isNotEmpty())
    <table style="width:100%;border-collapse:collapse;margin-bottom:18px">
      <thead>
        <tr style="border-bottom:1.5px solid var(--border)">
          @foreach(['Time','Group','Code','Joined','From Baler','Fee','Payment',''] as $h)
            <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($recentGroups as $g)
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $g->created_at->format('g:i A') }}</td>
            <td style="padding:10px;font-size:13px">
              <div style="font-weight:600;color:var(--text)">{{ $g->label }}</div>
              <div style="font-size:11px;color:var(--text-3)">{{ $g->group_type }} · {{ $g->headcount }} people{{ $g->city ? ' · ' . $g->city : '' }}</div>
            </td>
            <td style="padding:10px">
              <code style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:14px;font-weight:700;letter-spacing:.1em;background:var(--border-light);border-radius:6px;padding:3px 8px;user-select:all">{{ $g->join_code ?? '—' }}</code>
            </td>
            <td style="padding:10px;font-size:13px;color:var(--text-2)" title="Members who entered the code in the app">{{ $g->visitors_count }} / {{ $g->headcount }}</td>
            <td style="padding:10px;white-space:nowrap">
              @php
                $unaccounted = $g->unaccountedLocals();
              @endphp
              @if($g->visitor_type === 'Local')
                <span class="badge b-green">All {{ $g->headcount }}</span>
              @else
                {{-- The correction lives on the row, because the moment the
                     desk learns a member is from Baler is the moment that
                     member is standing in front of them. --}}
                <form method="POST" action="{{ route('desk.groups.correct', $g) }}" style="display:flex;align-items:center;gap:6px">
                  @csrf
                  <input class="fi" name="local_count" type="number" min="0" max="{{ $g->headcount }}" value="{{ $g->local_count }}"
                         style="width:58px;padding:5px 8px;font-size:13px;text-align:center" title="How many of this party are from Baler">
                  <span style="font-size:12px;color:var(--text-3)">of {{ $g->headcount }}</span>
                  <button type="submit" class="btn btn-outline btn-xs" title="Re-price the group. If it already paid, the difference is recorded as a refund.">Correct</button>
                </form>
                @if($unaccounted > 0)
                  {{-- Someone joined from their phone saying they are from
                       Baler, and the desk is charging for them. --}}
                  <div style="margin-top:5px;font-size:11px;font-weight:600;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:3px 8px;display:inline-block">
                    {{ $unaccounted }} {{ $unaccounted === 1 ? 'local' : 'locals' }} joined in the app — being charged
                  </div>
                @endif
              @endif
            </td>
            <td style="padding:10px;font-size:13px;white-space:nowrap">
              {{ $g->total_fee > 0 ? '₱' . number_format((float) $g->total_fee, 2) : '—' }}
              @if((float) $g->refunded_amount > 0)
                <div style="font-size:11px;color:var(--text-3)">₱{{ number_format((float) $g->refunded_amount, 2) }} refunded</div>
              @endif
            </td>
            <td style="padding:10px">
              <span class="badge {{ $g->payment_status === 'Paid' ? 'b-green' : ($g->payment_status === 'Unpaid' ? 'b-red' : 'b-gray') }}">{{ $g->payment_status }}</span>
            </td>
            <td style="padding:10px;white-space:nowrap">
              @if($g->payment_status === 'Unpaid')
                <form method="POST" action="{{ route('desk.groups.paid', $g) }}" style="display:inline">
                  @csrf
                  <button type="submit" class="btn btn-green btn-xs">Mark Paid</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  @if($recent->isEmpty() && $recentGroups->isEmpty())
    <p style="font-size:13px;color:var(--text-3);padding:16px 0;text-align:center">Nobody registered yet today.</p>
  @elseif($recent->isNotEmpty())
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1.5px solid var(--border)">
          @foreach(['Time','Name','Type','How','Payment'] as $h)
            <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($recent as $v)
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $v->created_at->format('g:i A') }}</td>
            <td style="padding:10px;font-size:13px;font-weight:600;color:var(--text)">
              {{ $v->full_name }}
              @if($v->group)
                <div style="font-size:11px;font-weight:500;color:var(--text-3)">with {{ $v->group->label }}</div>
              @endif
            </td>
            <td style="padding:10px"><span class="badge {{ $v->visitor_type === 'Local' ? 'b-green' : ($v->visitor_type === 'Foreign' ? 'b-purple' : 'b-blue') }}">{{ $v->visitor_type }}</span></td>
            <td style="padding:10px;font-size:12px;color:var(--text-3)">{{ ucfirst($v->source) }}</td>
            <td style="padding:10px">
              @if($v->group)
                {{-- The member owes nothing; the group's payment is what
                     counts, and it is on the row above. --}}
                <span class="badge b-gray" title="Covered by the group's payment">Group</span>
              @else
                <span class="badge {{ $v->payment_status === 'Paid' ? 'b-green' : ($v->payment_status === 'Unpaid' ? 'b-red' : 'b-gray') }}">{{ $v->payment_status }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

@push('styles')
<style>
  /* Visitor-type picker: three big tap targets. */
  #fromBarangay[hidden], #fromCity[hidden] { display: none; }
  .vtype-opt { cursor: pointer; }
  .vtype-opt input { display: none; }
  .vtype-opt span {
    display: block; text-align: center; padding: 12px 6px;
    border: 1.5px solid var(--border); border-radius: 10px; transition: .12s;
  }
  .vtype-opt strong { display: block; font-size: 14px; font-weight: 700; color: var(--text); }
  .vtype-opt small  { display: block; font-size: 11px; color: var(--text-3); margin-top: 2px; }
  .vtype-opt input:checked + span {
    border-color: var(--green-dark);
    background: var(--green-pale);
    box-shadow: 0 0 0 3px rgba(22,101,52,.08);
  }
</style>
@endpush

@push('scripts')
<script>
  // Keeps the embedded check-in code in step with the server's window. Only
  // runs while the panel is open, so a desk that never expands it does not
  // poll all day.
  (function () {
    var panel = document.getElementById('checkinPanel');
    if (!panel) return;

    var ROTATE = {{ \App\Services\AttendanceQrService::WINDOW_SECONDS }};
    var img    = document.getElementById('deskQr');
    var fill   = document.getElementById('deskQrFill');
    var count  = document.getElementById('deskQrCount');
    var left   = ROTATE;
    var timer  = null;

    function paint() {
      fill.style.width = Math.max(0, (left / ROTATE) * 100) + '%';
      count.textContent = Math.max(0, left);
    }

    function sync() {
      fetch('{{ route("attendance.kiosk.tick") }}', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          left = d.expires_in;
          img.src = '{{ route("attendance.kiosk.qr") }}?w=' + d.window;
          paint();
        })
        .catch(function () {}); // offline: keep counting locally, retry next turn
    }

    panel.addEventListener('toggle', function () {
      if (panel.open) {
        sync();
        timer = setInterval(function () {
          left -= 1;
          if (left <= 0) { sync(); } else { paint(); }
        }, 1000);
      } else {
        clearInterval(timer);
      }
    });
  })();

  // Shows the desk what to collect before they hit submit, so the number in
  // their hand and the number in the system are decided at the same moment.
  (function () {
    const head  = document.getElementById('gHead');
    const local = document.getElementById('gLocal');
    const type  = document.getElementById('gType');
    const out   = document.getElementById('gFee');
    if (!out) return;

    // Walk-in form: a local names a barangay, everyone else a place.
    (function () {
      const radios = document.querySelectorAll('input[name="visitor_type"]');
      const city = document.getElementById('fromCity');
      const brgy = document.getElementById('fromBarangay');
      if (!radios.length || !city || !brgy) return;
      function swap() {
        const local = document.querySelector('input[name="visitor_type"]:checked')?.value === 'Local';
        city.hidden = local;
        brgy.hidden = !local;
      }
      radios.forEach(r => r.addEventListener('change', swap));
      swap();
    })();

    const FEE = {{ \App\Models\MuseumInfo::admissionFee() }};

    function render() {
      const isLocal = type.value === 'Local';
      const heads   = Math.max(0, parseInt(head.value || '0', 10));
      let   locals  = isLocal ? heads : Math.max(0, parseInt(local.value || '0', 10));

      // A Local group is all locals: say so in the box rather than letting
      // the desk type a number that means nothing.
      local.disabled = isLocal;
      if (isLocal) local.value = heads;

      if (locals > heads) {
        out.style.background = '#fef2f2';
        out.style.color      = '#991b1b';
        out.textContent      = 'More from Baler than people in the party — check the numbers.';
        return;
      }
      out.style.background = 'var(--green-pale)';
      out.style.color      = 'var(--green-dark)';

      const paying = heads - locals;
      const total  = paying * FEE;

      if (total > 0) {
        out.textContent = 'Collect PHP ' + total.toFixed(2) + ' — ' + paying + ' paying of ' + heads
          + (locals > 0 ? '. Check ' + locals + ' Baler ' + (locals === 1 ? 'ID' : 'IDs') + '.' : '.');
      } else {
        out.textContent = 'No fee. Locals enter free, but check their IDs.';
      }
    }

    [head, local, type].forEach(el => { el.addEventListener('input', render); el.addEventListener('change', render); });
    render();
  })();
</script>
@endpush
@endsection
