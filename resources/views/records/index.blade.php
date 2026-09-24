@extends('layouts.admin')
@section('title','Records — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Records</h2>
  </div>
  <div class="ph-right">
    @include('partials.report-menu', [
      'id'      => 'recordsReportMenu',
      'mode'    => 'range',
      'reports' => [
        ['label' => 'Daily logbook',        'note' => 'Who came in, hour by hour',        'url' => route('reports.logbook'), 'csv' => route('reports.logbook.csv')],
        ['label' => 'Visitors & admission', 'note' => 'Headcount and fees collected',     'url' => route('reports.visitors')],
        ['label' => 'Exhibit engagement',   'note' => 'Which exhibits were scanned most', 'url' => route('reports.exhibits')],
      ],
    ])
  </div>
</div>

<div class="tab-bar">
  <button class="tab-btn {{ $activeTab === 'visitors' ? 'active' : '' }}" onclick="switchTab('visitors',this)">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;display:inline;vertical-align:middle;margin-right:5px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Visitor Records
  </button>
  <button class="tab-btn {{ $activeTab === 'groups' ? 'active' : '' }}" onclick="switchTab('groups',this)">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;display:inline;vertical-align:middle;margin-right:5px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    Groups
    @if($groupStats['unpaid'] > 0)
      <span style="background:var(--red);color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:99px;margin-left:4px" title="Groups still owing">{{ $groupStats['unpaid'] }}</span>
    @endif
  </button>
  <button class="tab-btn {{ $activeTab === 'scans' ? 'active' : '' }}" onclick="switchTab('scans',this)">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;display:inline;vertical-align:middle;margin-right:5px"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    Scan Records
  </button>
  <button class="tab-btn {{ $activeTab === 'attendance' ? 'active' : '' }}" onclick="switchTab('attendance',this)">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;display:inline;vertical-align:middle;margin-right:5px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
    Attendance
    @if($todayCount > 0)
      <span style="background:var(--green);color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:99px;margin-left:4px">{{ $todayCount }}</span>
    @endif
  </button>
</div>

<!-- Visitor Records -->
<div id="tab-visitors" class="inner-panel {{ $activeTab === 'visitors' ? 'active' : '' }}">
  <div class="stats-row stats-3">
    <div class="stat">
      <div class="stat-ico green">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($stats['total']) }}</div><div class="stat-lbl">Total Visitors</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico blue">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($stats['local']) }}</div><div class="stat-lbl">Local</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico gold">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($stats['tourist']) }}</div><div class="stat-lbl">Tourist</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico purple">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($stats['foreign']) }}</div><div class="stat-lbl">Foreign</div></div>
    </div>
    {{-- Admission follow-ups for the entrance desk --}}
    <div class="stat">
      <div class="stat-ico gold">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($stats['unpaid']) }}</div><div class="stat-lbl">Fees Unpaid</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico blue">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M15 8h3M15 12h3M5.5 16c.6-1.5 2-2.2 3.5-2.2s2.9.7 3.5 2.2"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($stats['unverified']) }}</div><div class="stat-lbl">IDs to Verify</div></div>
    </div>
  </div>

  {{-- The visitor app stays locked until staff clears the admission, so anyone
       in this queue is standing at the entrance unable to get in. --}}
  @if($stats['pending'] > 0)
  <div style="display:flex;align-items:center;gap:12px;background:var(--gold-pale);border:1px solid var(--gold);border-radius:var(--r);padding:12px 16px;margin-bottom:18px">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;color:var(--gold-dark);flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:700;color:var(--gold-dark)">
        {{ $stats['pending'] }} {{ Str::plural('visitor', $stats['pending']) }} waiting to be let in
      </div>
      <div style="font-size:12px;color:var(--text-2)">
        They cannot open the museum app until the desk collects the fee or checks their ID.
      </div>
    </div>
    <a href="{{ route('records.index', ['tab' => 'visitors', 'adm' => 'pending']) }}" class="btn btn-gold btn-sm">Show queue</a>
  </div>
  @endif

  <form method="GET" action="{{ route('records.index') }}">
    <div class="fbar">
      <div class="search-box">
        <svg class="si" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name or country…">
      </div>
      <x-fctl icon="user">
        <select class="fsel" name="vtype" onchange="this.form.submit()">
          <option value="">All Visitor Types</option>
          @foreach(['Local','Tourist','Foreign'] as $t)
          <option {{ request('vtype') === $t ? 'selected' : '' }}>{{ $t }}</option>
          @endforeach
        </select>
      </x-fctl>
      <x-fctl icon="users">
        <select class="fsel" name="visit" onchange="this.form.submit()">
          <option value="">All Visit Types</option>
          @foreach(['Solo','Group','School','Family'] as $t)
          <option {{ request('visit') === $t ? 'selected' : '' }}>{{ $t }}</option>
          @endforeach
        </select>
      </x-fctl>
      <x-fctl icon="ticket">
        <select class="fsel" name="adm" onchange="this.form.submit()">
          <option value="">All Admissions</option>
          <option value="pending"    {{ request('adm')==='pending'?'selected':'' }}>Waiting for clearance</option>
          <option value="unpaid"     {{ request('adm')==='unpaid'?'selected':'' }}>Fee Unpaid</option>
          <option value="paid"       {{ request('adm')==='paid'?'selected':'' }}>Fee Paid</option>
          <option value="unverified" {{ request('adm')==='unverified'?'selected':'' }}>ID Not Verified</option>
        </select>
      </x-fctl>
      <x-fctl icon="sort">
        <select class="fsel" name="vsort" onchange="this.form.submit()">
          <option value="newest" {{ request('vsort','newest')==='newest'?'selected':'' }}>Sort: Newest First</option>
          <option value="oldest" {{ request('vsort')==='oldest'?'selected':'' }}>Sort: Oldest First</option>
          <option value="name"   {{ request('vsort')==='name'?'selected':'' }}>Sort: Name A–Z</option>
        </select>
      </x-fctl>
    </div>
  </form>

  <x-day-nav :day="$visitorDay" unit="visitor" />

  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Name</th><th>Age</th><th>Sex</th>
        <th>Visit Type</th><th>Visitor Type</th><th>Last Visit</th><th>Location</th>
        <th>Admission</th><th></th>
      </tr></thead>
      <tbody>
      @forelse($visitors as $v)
      <tr>
        <td style="white-space:nowrap;font-weight:600">{{ $v->full_name }}</td>
        <td>{{ $v->age ?? '—' }}</td>
        <td>{{ $v->sex ?? '—' }}</td>
        <td><span class="badge b-blue">{{ $v->visit_type }}</span></td>
        <td><span class="badge {{ $v->visitor_type==='Local'?'b-green':($v->visitor_type==='Tourist'?'b-gold':'b-purple') }}">{{ $v->visitor_type }}</span></td>
        <td>{{ $v->last_visit ? \Carbon\Carbon::parse($v->last_visit)->format('M j, Y') : '—' }}</td>
        <td style="white-space:nowrap">{{ $v->location ?: '—' }}</td>
        {{-- One column, because "has the fee been settled" and "will the app
             open for them" are the same question. The badge answers it; the
             line under it says what was owed. --}}
        <td style="white-space:nowrap">
          @if($v->isCleared())
            <span class="badge b-green">Unlocked</span>
          @else
            <span class="badge b-red">{{ $v->clearanceLabel() }}</span>
          @endif
          <div style="font-size:11px;color:var(--text-3);margin-top:3px">
            @if($v->group)
              With {{ $v->group->label }}
            @elseif($v->payment_status === 'Free')
              {{-- Ternary, not a nested @if: Blade will not compile a directive
                   that follows a word character ("entry@if"), so it printed raw. --}}
              Free entry{{ $v->id_verified ? ' · ID checked' : '' }}
            @else
              {{ $v->payment_status }} · ₱{{ number_format((float) $v->admission_fee, 2) }}
            @endif
          </div>
        </td>
        <td style="white-space:nowrap">
          {{-- Collecting a fee and sighting an ID happen at the counter. The
               Tourism office reads this list from the municipal building and
               does neither, so she gets no buttons — the routes behind them
               are museum-only in any case. --}}
          @if(auth()->user()->isTourismHead())
            <span style="color:var(--text-4);font-size:12px">—</span>
          @elseif($v->group && $v->group->payment_status === 'Unpaid' && ($v->isWithGroupToday() || $v->payment_status === 'Free'))
          {{-- A group member's fee is the party's fee, so Mark Paid here
               settles the group — and unlocks every member at once. --}}
          <form method="POST" action="{{ route('desk.groups.paid', $v->group) }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-green btn-xs" title="Collects the fee for the whole party">Mark Paid</button>
          </form>
          @elseif($v->payment_status === 'Unpaid')
          <form method="POST" action="{{ route('visitors.mark-paid', $v) }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-green btn-xs">Mark Paid</button>
          </form>
          @elseif($v->visitor_type === 'Local' && !$v->id_verified)
          <form method="POST" action="{{ route('visitors.verify-id', $v) }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-outline btn-xs">Verify ID</button>
          </form>
          @else
            <span style="color:var(--text-4);font-size:12px">—</span>
          @endif
        </td>
      </tr>
      @empty
      <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-3)">No visitors registered on this day.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<!-- Groups -->
{{-- A party the desk registered as one record: one headcount, one payment.
     This is where that payment gets recorded. Members who joined from their
     own phones are counted here, not in the visitor list, so a group of five
     with three app users is five people, not eight. --}}
<div id="tab-groups" class="inner-panel {{ $activeTab === 'groups' ? 'active' : '' }}">
  <div class="stats-row stats-3">
    <div class="stat">
      <div class="stat-ico green">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($groupStats['today']) }}</div><div class="stat-lbl">Groups today · {{ number_format($groupStats['heads_today']) }} people</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico red">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($groupStats['unpaid']) }}</div><div class="stat-lbl">Still owing</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico gold">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      </div>
      <div><div class="stat-val">₱{{ number_format($groupStats['outstanding'], 2) }}</div><div class="stat-lbl">Outstanding from groups</div></div>
    </div>
  </div>

  <form method="GET" action="{{ route('records.index') }}" class="fbar">
    <input type="hidden" name="tab" value="groups">
    <input type="date" class="fsel" name="gdate" value="{{ $gdate ?? '' }}" onchange="this.form.submit()">
    <select class="fsel" name="gpay" onchange="this.form.submit()">
      <option value="">All payments</option>
      <option value="unpaid" {{ request('gpay') === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
      <option value="paid"   {{ request('gpay') === 'paid'   ? 'selected' : '' }}>Paid</option>
      <option value="free"   {{ request('gpay') === 'free'   ? 'selected' : '' }}>Free (locals)</option>
    </select>
    @if(request()->hasAny(['gdate', 'gpay']))
      <a href="{{ route('records.index', ['tab' => 'groups']) }}" class="btn btn-outline btn-sm">Clear</a>
    @endif
  </form>

  <div class="tbl-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Group</th><th>Signed in by</th><th>Type</th><th>People</th><th>Joined in app</th><th>Code</th><th>Fee</th><th>Payment</th><th>Registered by</th><th></th>
        </tr>
      </thead>
      <tbody>
      @forelse($groups as $g)
      <tr>
        <td style="white-space:nowrap">{{ $g->visit_date->format('M j, Y') }}<div style="font-size:11px;color:var(--text-3)">{{ $g->created_at->format('g:i A') }}</div></td>
        <td style="font-weight:600;white-space:nowrap">{{ $g->label }}<div style="font-size:11px;font-weight:500;color:var(--text-3)">{{ $g->group_type }}{{ $g->city ? ' · ' . $g->city : '' }}</div></td>
        <td>{{ $g->contact_name }}@if($g->contact_phone)<div style="font-size:11px;color:var(--text-3)">{{ $g->contact_phone }}</div>@endif</td>
        <td><span class="badge {{ $g->visitor_type==='Local'?'b-green':($g->visitor_type==='Tourist'?'b-gold':'b-purple') }}">{{ $g->visitor_type }}</span></td>
        <td>
          {{ $g->headcount }}
          @if($g->visitor_type !== 'Local' && $g->local_count > 0)
            <div style="font-size:11px;color:var(--text-3)">{{ $g->local_count }} from Baler · {{ $g->paying_count }} paying</div>
          @endif
          @php
            $unaccounted = $g->unaccountedLocals();
          @endphp
          @if($unaccounted > 0)
            <div style="margin-top:3px;font-size:11px;font-weight:600;color:#b45309" title="Joined in the app as Local but the group is paying for them">{{ $unaccounted }} {{ $unaccounted === 1 ? 'local' : 'locals' }} being charged</div>
          @endif
        </td>
        <td title="Members who entered the group code in the visitor app">{{ $g->visitors_count }} / {{ $g->headcount }}</td>
        <td>
          @if($g->visit_date->isToday() && $g->join_code)
            <code style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;font-weight:700;letter-spacing:.1em;background:var(--border-light);border-radius:6px;padding:2px 7px;user-select:all">{{ $g->join_code }}</code>
          @else
            <span style="color:var(--text-4);font-size:12px">—</span>
          @endif
        </td>
        <td style="white-space:nowrap">
          {{ $g->total_fee > 0 ? '₱' . number_format((float) $g->total_fee, 2) : '—' }}
          @if((float) $g->refunded_amount > 0)
            <div style="font-size:11px;color:var(--text-3)" title="Handed back after a correction{{ $g->refunded_at ? ' at ' . $g->refunded_at->format('g:i A') : '' }}">₱{{ number_format((float) $g->refunded_amount, 2) }} refunded</div>
          @endif
        </td>
        <td style="white-space:nowrap">
          <span class="badge {{ $g->payment_status==='Paid'?'b-green':($g->payment_status==='Unpaid'?'b-red':'b-gray') }}">{{ $g->payment_status }}</span>
          @if($g->paid_at)<div style="font-size:11px;color:var(--text-3);margin-top:3px">{{ $g->paid_at->format('g:i A') }}</div>@endif
        </td>
        <td style="font-size:12px;color:var(--text-3)">{{ $g->registeredBy?->name ?? '—' }}</td>
        <td style="white-space:nowrap">
          {{-- Collecting money is desk work; the Tourism office reads this
               tab but does not stand at the counter. Same rule as the
               individual Mark Paid, whose route is museum-only too. --}}
          @if(!auth()->user()->isTourismHead())
            @if($g->payment_status === 'Unpaid')
              <form method="POST" action="{{ route('desk.groups.paid', $g) }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-green btn-xs">Mark Paid</button>
              </form>
            @endif
            @if($g->visit_date->isToday() && $g->visitor_type !== 'Local')
              {{-- Corrections are today-only: yesterday's takings have been
                   counted, and changing them is the Tourism office's call. --}}
              <form method="POST" action="{{ route('desk.groups.correct', $g) }}" style="display:inline-flex;align-items:center;gap:5px;margin-left:6px">
                @csrf
                <input class="fi" name="local_count" type="number" min="0" max="{{ $g->headcount }}" value="{{ $g->local_count }}"
                       style="width:52px;padding:4px 6px;font-size:12px;text-align:center" title="How many of this party are from Baler">
                <button type="submit" class="btn btn-outline btn-xs" title="Re-price for this many locals. If already paid, the difference is recorded as a refund.">Locals</button>
              </form>
            @endif
          @endif
          @if(auth()->user()->isTourismHead() || ($g->payment_status !== 'Unpaid' && (!$g->visit_date->isToday() || $g->visitor_type === 'Local')))
            <span style="color:var(--text-4);font-size:12px">—</span>
          @endif
        </td>
      </tr>
      @empty
      <tr><td colspan="11" style="text-align:center;padding:32px;color:var(--text-3)">No groups registered{{ request()->hasAny(['gdate','gpay']) ? ' for that filter' : ' yet' }}.</td></tr>
      @endforelse
      </tbody>
    </table>
    <div class="tbl-foot">
      <span class="tbl-count">{{ $groups->total() }} groups</span>
      <div>{{ $groups->links() }}</div>
    </div>
  </div>
</div>

<!-- Scan Records -->
<div id="tab-scans" class="inner-panel {{ $activeTab === 'scans' ? 'active' : '' }}">
  <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Exhibit Name</th><th>Category</th><th>Floor</th><th>Hall</th><th>Total Scans</th>
      </tr></thead>
      <tbody>
      @foreach($scanStats as $ex)
      <tr>
        <td>{{ $ex->name }}</td>
        <td><span class="badge b-gold">{{ $ex->category?->name ?? '—' }}</span></td>
        <td>{{ $ex->floor }}</td>
        <td>{{ $ex->hall }}</td>
        <td style="font-weight:700;color:var(--green-dark)">{{ number_format($ex->scans_count) }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>

<!-- Attendance -->
<div id="tab-attendance" class="inner-panel {{ $activeTab === 'attendance' ? 'active' : '' }}">

  <div class="stats-row" style="margin-bottom:18px">
    <div class="stat">
      <div class="stat-ico green">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($todayCount) }}</div><div class="stat-lbl">Today's Attendance</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico blue">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($totalAtt) }}</div><div class="stat-lbl">Registered Visits</div></div>
    </div>
    <div class="stat">
      <div class="stat-ico gold">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/><line x1="12" y1="3" x2="12" y2="1"/></svg>
      </div>
      <div><div class="stat-val">{{ number_format($anonAtt) }}</div><div class="stat-lbl">Anonymous Visits</div></div>
    </div>
  </div>

  {{-- 7-day chart --}}
  <div class="card card-p-lg" style="margin-bottom:18px">
    <h3 class="sec-title">Last 7 Days</h3>
    <div class="chart-wrap"><canvas id="chartAttendance"></canvas></div>
  </div>

  {{-- Date filter + table --}}
  <div class="card card-p-lg">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <div>
        <h3 class="sec-title" style="margin-bottom:0">Attendance Log</h3>
        <p style="font-size:12px;color:var(--text-3)">Showing: {{ \Carbon\Carbon::parse($attDate)->format('F j, Y') }}</p>
      </div>
      <form method="GET" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="tab" value="attendance">
        <input type="date" name="att_date" value="{{ $attDate }}"
          style="padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)"
          onchange="this.form.submit()">
        <span class="badge b-green">{{ $attendances->total() }} records</span>
      </form>
    </div>
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1.5px solid var(--border)">
          <th style="padding:9px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;text-align:left">#</th>
          <th style="padding:9px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;text-align:left">Visitor</th>
          <th style="padding:9px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;text-align:left">Method</th>
          <th style="padding:9px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;text-align:left">Accuracy</th>
          <th style="padding:9px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;text-align:left">Time In</th>
          <th style="padding:9px 10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;text-align:left">Duration</th>
        </tr>
      </thead>
      <tbody>
      @forelse($attendances as $i => $a)
        <tr style="border-bottom:1px solid var(--border-light)">
          <td style="padding:9px 10px;font-size:13px;color:var(--text-3)">{{ $attendances->firstItem() + $i }}</td>
          <td style="padding:9px 10px">
            @if($a->visitor)
              <div style="font-size:13px;font-weight:600;color:var(--text)">{{ $a->visitor->full_name }}</div>
              <div style="font-size:11px;color:var(--text-3)">{{ $a->visitor->visitor_type }} · {{ $a->visitor->city ?? 'Unknown' }}</div>
            @elseif($a->visitor_name)
              <div style="font-size:13px;font-weight:600;color:var(--text)">{{ $a->visitor_name }}</div>
              <div style="font-size:11px;color:var(--text-3)">Registered visitor</div>
            @else
              <div style="font-size:13px;font-weight:600;color:var(--text-3)">Anonymous</div>
              <div style="font-size:11px;color:var(--text-4)">Not yet registered</div>
            @endif
          </td>
          <td style="padding:9px 10px">
            <span class="badge {{ $a->method === 'registered' ? 'b-blue' : 'b-green' }}">
              {{ $a->method === 'registered' ? '👤 Registered' : '📍 Geofence' }}
            </span>
          </td>
          <td style="padding:9px 10px;font-size:13px;color:var(--text-3)">{{ $a->accuracy ? '±'.$a->accuracy.'m' : '—' }}</td>
          <td style="padding:9px 10px;font-size:13px;color:var(--text-3)">{{ $a->created_at->format('h:i A') }}</td>
          <td style="padding:9px 10px;font-size:13px;color:var(--text-3)">
            @if($a->duration_mins !== null)
              {{ $a->duration_mins }} min
            @elseif($a->exited_at)
              {{ \Carbon\Carbon::parse($a->created_at)->diffInMinutes($a->exited_at) }} min
            @else
              <span style="color:var(--green-dark);font-size:11px">● Still inside</span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="5" style="padding:32px;text-align:center;color:var(--text-3);font-size:13px">No attendance records for this date.</td></tr>
      @endforelse
      </tbody>
    </table>
    @if($attendances->hasPages())
      <div style="margin-top:14px">{{ $attendances->links() }}</div>
    @endif
  </div>
</div>
@endsection

@push('scripts')
<script>
function switchTab(tab, btn){
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.inner-panel').forEach(p=>p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-'+tab).classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', tab);
  history.replaceState(null, '', url);
  // Clear attendance badge when tab is opened
  if (tab === 'attendance') {
    var badge = btn.querySelector('span');
    if (badge) badge.remove();
  }
}

// Attendance chart
var chartEl = document.getElementById('chartAttendance');
if (chartEl) {
  new Chart(chartEl, {
    type: 'bar',
    data: {
      labels: @json($chartLabels),
      datasets: [{ label: 'Visitors', data: @json($chartValues), backgroundColor: 'rgba(34,197,94,.7)', borderRadius: 6 }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
  });
}
</script>
@endpush
