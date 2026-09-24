@extends('layouts.admin')
@section('title', 'Attendance Corrections — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Attendance Corrections</h2>
  </div>
  <div class="ph-right">
    <form method="GET">
      <select name="status" onchange="this.form.submit()"
              style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
        @foreach(['Pending','Approved','Rejected','All'] as $s)
          <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ $s }}</option>
        @endforeach
      </select>
    </form>
  </div>
</div>

{{-- File a request — museum staff only. The Tourism head reviews what the
     museum files; she does not file, or one person would hold both halves
     of the two-signature rule. --}}
@if(auth()->user()->isTourismHead())
<div class="card card-p" style="margin-bottom:22px;display:flex;align-items:center;gap:12px">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;color:var(--text-3);flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
  <div style="font-size:13px;color:var(--text-2);line-height:1.55">
    Corrections are filed by museum staff for a colleague who worked but could not scan.
    Your part is to <strong>approve or reject</strong> them below — each approved one becomes an
    attendance row carrying both your name and the filer's.
  </div>
</div>
@else
<div class="card card-p-lg" style="margin-bottom:22px">
  <h3 class="sec-title">File a correction</h3>
  <p class="sec-sub">
    For a day someone worked but could not scan — dead phone, no GPS fix, kiosk offline.
    It does not count until the Tourism office approves it.
  </p>

  <form method="POST" action="{{ route('corrections.store') }}" style="margin-top:14px">
    @csrf
    <div style="display:grid;grid-template-columns:2fr 1.2fr 1fr 1fr;gap:10px">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Staff member</label>
        <select name="staff_id" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px;background:var(--surface)">
          <option value="">Choose…</option>
          @foreach($staffList as $member)
            {{-- Nobody files their own: that is the separation this exists for. --}}
            @if($member->staff_id !== auth()->id())
              <option value="{{ $member->staff_id }}">{{ $member->name }}</option>
            @endif
          @endforeach
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Date</label>
        <input type="date" name="work_date" required max="{{ today()->toDateString() }}"
               style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px;background:var(--surface)">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Type</label>
        <select name="type" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px;background:var(--surface)">
          <option value="in">Check in</option>
          <option value="out">Check out</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Time</label>
        <input type="time" name="requested_time" required
               style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px;background:var(--surface)">
      </div>
    </div>

    <div style="margin-top:12px">
      <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Reason</label>
      <input name="reason" required minlength="10" maxlength="500"
             placeholder="What happened, and how you know they were here"
             style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px">
    </div>

    <button type="submit" class="btn btn-green" style="margin-top:14px">File correction</button>
  </form>
</div>
@endif

{{-- List --}}
<div class="card card-p-lg">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h3 class="sec-title" style="margin-bottom:0">{{ $status }} requests</h3>
    @if($pendingCount)<span class="badge b-gold">{{ $pendingCount }} awaiting the Tourism office</span>@endif
  </div>

  @if($corrections->isEmpty())
    <p style="font-size:13px;color:var(--text-3);padding:20px 0;text-align:center">Nothing here.</p>
  @else
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1.5px solid var(--border)">
          @foreach(['Staff','Date','Entry','Reason','Filed by','Status',''] as $h)
            <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($corrections as $c)
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:10px;font-size:13px;font-weight:600">{{ $c->staff->name }}</td>
            <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $c->work_date->format('M j, Y') }}</td>
            <td style="padding:10px;font-size:13px">
              {{ $c->type === 'in' ? 'In' : 'Out' }} · {{ \Carbon\Carbon::parse($c->requested_time)->format('g:i A') }}
            </td>
            <td style="padding:10px;font-size:12px;color:var(--text-2);max-width:280px">{{ $c->reason }}</td>
            <td style="padding:10px;font-size:12px;color:var(--text-3)">
              {{ $c->requestedBy?->name }}<br>
              <span style="font-size:11px">{{ $c->requested_at->format('M j, g:i A') }}</span>
            </td>
            <td style="padding:10px">
              <span class="badge {{ $c->status === 'Approved' ? 'b-green' : ($c->status === 'Rejected' ? 'b-red' : 'b-gold') }}">{{ $c->status }}</span>
              @if($c->reviewedBy)
                <div style="font-size:11px;color:var(--text-3);margin-top:3px">by {{ $c->reviewedBy->name }}</div>
              @endif
            </td>
            <td style="padding:10px;text-align:right;white-space:nowrap">
              {{-- Only the Tourism office decides, and never on a request it
                   filed itself — the controller enforces both. --}}
              @if($c->status === 'Pending' && auth()->user()->isTourismHead() && $c->requested_by !== auth()->id())
                <form method="POST" action="{{ route('corrections.review', $c) }}" style="display:inline">
                  @csrf
                  <input type="hidden" name="decision" value="Approved">
                  <button class="btn btn-green btn-xs">Approve</button>
                </form>
                <form method="POST" action="{{ route('corrections.review', $c) }}" style="display:inline">
                  @csrf
                  <input type="hidden" name="decision" value="Rejected">
                  <button class="btn btn-red btn-xs">Reject</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="pagination" style="margin-top:16px">{{ $corrections->links() }}</div>
  @endif
</div>
@endsection
