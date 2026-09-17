@extends('layouts.admin')
@section('title', $staff->name . ' — Work Schedule')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Work Schedule</h2>
    <p>{{ $staff->name }} · {{ $staff->role_label }}</p>
  </div>
  <div class="ph-right">
    <a href="{{ route('staff-attendance.show', $staff) }}" class="btn btn-outline btn-sm">Back to attendance</a>
  </div>
</div>

<div class="card card-p-lg">
  {{-- Without a shift there is nothing to be late against, so every status
       for this person reads "No Schedule" until this form is filled in. --}}
  <h3 class="sec-title">Shift per weekday</h3>
  <p class="sec-sub">Attendance is judged against these times. Leave a day blank to mark it as not scheduled.</p>

  <form method="POST" action="{{ route('staff-attendance.schedule.save', $staff) }}" style="margin-top:16px">
    @csrf

    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1.5px solid var(--border)">
          @foreach(['Day','Rest day','Start','End','Grace'] as $h)
            <th style="padding:10px;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;text-align:left">{{ $h }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $i => $label)
          @php $row = $schedules->get($i); @endphp
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:10px;font-size:13px;font-weight:600">{{ $label }}</td>
            <td style="padding:10px">
              <input type="hidden" name="days[{{ $i }}][is_rest_day]" value="0">
              <input type="checkbox" name="days[{{ $i }}][is_rest_day]" value="1"
                     {{ $row && $row->is_rest_day ? 'checked' : '' }} style="width:17px;height:17px">
            </td>
            <td style="padding:10px">
              <input type="time" name="days[{{ $i }}][shift_start]"
                     value="{{ $row && !$row->is_rest_day ? \Carbon\Carbon::parse($row->shift_start)->format('H:i') : '' }}"
                     style="padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
            </td>
            <td style="padding:10px">
              <input type="time" name="days[{{ $i }}][shift_end]"
                     value="{{ $row && !$row->is_rest_day ? \Carbon\Carbon::parse($row->shift_end)->format('H:i') : '' }}"
                     style="padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
            </td>
            <td style="padding:10px">
              <input type="number" name="days[{{ $i }}][grace_minutes]" min="0" max="120"
                     value="{{ $row->grace_minutes ?? 15 }}"
                     style="width:80px;padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface)">
              <span style="font-size:11px;color:var(--text-3);margin-left:4px">min</span>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div style="display:flex;gap:10px;margin-top:18px">
      <button type="submit" class="btn btn-green">Save schedule</button>
      <button type="button" class="btn btn-outline" onclick="fillWeekdays()">Fill Mon–Fri 8:00–17:00</button>
    </div>
  </form>
</div>

@push('scripts')
<script>
  // Most staff run the same weekday shift, and typing it ten times is how a
  // schedule ends up half-filled and every status reading "No Schedule".
  function fillWeekdays() {
    for (let d = 1; d <= 5; d++) {
      document.querySelector(`[name="days[${d}][shift_start]"]`).value = '08:00';
      document.querySelector(`[name="days[${d}][shift_end]"]`).value   = '17:00';
      document.querySelector(`[name="days[${d}][is_rest_day]"][type="checkbox"]`).checked = false;
    }
  }
</script>
@endpush
@endsection
