@extends('layouts.admin')
@section('title', 'Guided Tours — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Guided Tours</h2>
    <p>Most visitors roam on their own — assign a guide when one is asked for, for a foreign visitor, or for a school</p>
  </div>
  <div class="ph-right" style="display:flex;gap:8px;align-items:center">
    <a href="{{ route('desk.register') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Front Desk
    </a>
    <form method="GET" style="display:flex;gap:8px;align-items:center">
      <input type="date" name="from" value="{{ $from->toDateString() }}" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
      <span style="font-size:12px;color:var(--text-3)">to</span>
      <input type="date" name="to" value="{{ $to->toDateString() }}" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
      <button class="btn btn-outline btn-sm">Show</button>
    </form>
  </div>
</div>

{{-- In progress --}}
@if($active->isNotEmpty())
  <div class="card card-p-lg" style="margin-bottom:22px">
    <h3 class="sec-title">In progress</h3>
    <table style="width:100%;border-collapse:collapse;margin-top:12px">
      <tbody>
        @foreach($active as $tour)
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:10px;font-size:13px;font-weight:600">{{ $tour->guide?->name ?? 'Unassigned' }}</td>
            <td style="padding:10px"><span class="badge b-blue">{{ $tour->tour_type }}</span></td>
            <td style="padding:10px;font-size:13px;color:var(--text-2)">
              {{ $tour->group?->contact_name ?? $tour->visitor?->full_name ?? '—' }} · {{ $tour->headcount }} pax
            </td>
            <td style="padding:10px;font-size:13px;color:var(--text-3)">Started {{ $tour->started_at->format('g:i A') }}</td>
            <td style="padding:10px;text-align:right">
              <form method="POST" action="{{ route('tours.end', $tour) }}">
                @csrf
                <button class="btn btn-outline btn-xs">End tour</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif

{{-- Assign --}}
<div class="card card-p-lg" style="margin-bottom:22px">
  <h3 class="sec-title">Assign a guide</h3>
  <p class="sec-sub">Only staff who have checked in today can be assigned.</p>

  @if($guides->isEmpty())
    <div class="alert" style="background:#fffbeb;color:#92400e;margin-top:12px">
      No guide has checked in today, so there is nobody to assign. Check the staff attendance board.
    </div>
  @else
    <form method="POST" action="{{ route('tours.store') }}" style="margin-top:14px">
      @csrf
      <div style="display:grid;grid-template-columns:1.2fr 1fr 2fr 0.7fr;gap:10px">
        <div>
          <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Guide</label>
          <select name="guide_staff_id" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px;background:var(--surface)">
            @foreach($guides as $guide)
              <option value="{{ $guide->staff_id }}">{{ $guide->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Why</label>
          <select name="tour_type" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px;background:var(--surface)">
            <option value="Requested">Visitor asked</option>
            <option value="Foreign">Foreign visitor</option>
            <option value="Educational">Educational tour</option>
          </select>
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">For whom</label>
          <select name="subject" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px;background:var(--surface)">
            <option value="">Choose…</option>
            @if($todayGroups->isNotEmpty())
              <optgroup label="Groups today">
                @foreach($todayGroups as $group)
                  <option value="group:{{ $group->group_id }}">
                    {{ $group->group_name ?: $group->contact_name }} ({{ $group->headcount }} pax)
                  </option>
                @endforeach
              </optgroup>
            @endif
            @if($todayVisitors->isNotEmpty())
              <optgroup label="Visitors today">
                @foreach($todayVisitors as $visitor)
                  <option value="visitor:{{ $visitor->visitor_id }}">
                    {{ $visitor->full_name }} ({{ $visitor->visitor_type }})
                  </option>
                @endforeach
              </optgroup>
            @endif
          </select>
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Pax</label>
          <input name="headcount" type="number" min="1" max="500" placeholder="auto"
                 style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;margin-top:4px">
        </div>
      </div>
      <button type="submit" class="btn btn-green" style="margin-top:14px">Start tour</button>
    </form>
  @endif
</div>

{{-- History --}}
<div class="card card-p-lg">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h3 class="sec-title" style="margin-bottom:0">Tours {{ $from->format('M j') }} – {{ $to->format('M j, Y') }}</h3>
    <span class="badge b-gray">{{ $tours->total() }} total</span>
  </div>

  @if($tours->isEmpty())
    <p style="font-size:13px;color:var(--text-3);padding:20px 0;text-align:center">No guided tours in this range.</p>
  @else
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1.5px solid var(--border)">
          @foreach(['Date','Guide','Type','For','Pax','Duration'] as $h)
            <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($tours as $tour)
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:10px;font-size:13px;color:var(--text-2)">{{ $tour->started_at?->format('M j, g:i A') }}</td>
            <td style="padding:10px;font-size:13px;font-weight:600">{{ $tour->guide?->name ?? '—' }}</td>
            <td style="padding:10px"><span class="badge b-blue">{{ $tour->tour_type }}</span></td>
            <td style="padding:10px;font-size:13px;color:var(--text-2)">
              {{ $tour->group?->contact_name ?? $tour->visitor?->full_name ?? '—' }}
            </td>
            <td style="padding:10px;font-size:13px">{{ $tour->headcount }}</td>
            <td style="padding:10px;font-size:13px;color:var(--text-3)">
              @if($tour->started_at && $tour->ended_at)
                {{ $tour->started_at->diffInMinutes($tour->ended_at) }} min
              @else
                <span class="badge b-gold">Open</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="pagination" style="margin-top:16px">{{ $tours->links() }}</div>
  @endif
</div>
@endsection
