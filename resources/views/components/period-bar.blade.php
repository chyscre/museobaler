@props(['period', 'action', 'keep' => []])

{{--
  Year / Month / Week / Range - the one period control, on the dashboard and
  over the Records and Logs tables.

  It sits in the page header beside Generate Report. The CSS draws the four
  buttons last, against Generate Report, with the inputs for the chosen
  period opening to their left: the header is right-aligned, so the buttons
  never move when the period changes. (Drawn first, they slid sideways with
  every switch, and Range used to push the control under the title.)

  period   App\Support\DashboardPeriod
  action   where the form submits
  keep     other query values to carry along (tab, search, filters)
--}}
@php
  $years = \App\Support\DashboardPeriod::years();
  $keep  = collect($keep)->filter(fn ($v) => $v !== null && $v !== '');
@endphp

<form method="GET" action="{{ $action }}" {{ $attributes->merge(['class' => 'period-bar']) }}>
  @foreach($keep as $k => $v)
    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
  @endforeach
  {{-- Carries the current period when a picker submits the form; a period
       button sends its own value after this one, and the last value of a
       repeated name is the one PHP keeps. --}}
  <input type="hidden" name="period" value="{{ $period->period }}">
  <div class="seg" role="group" aria-label="Period">
    @foreach(['year' => 'Year', 'month' => 'Month', 'week' => 'Week', 'range' => 'Range'] as $value => $label)
    <button type="submit" name="period" value="{{ $value }}" class="seg-btn {{ $period->period === $value ? 'on' : '' }}"
            aria-pressed="{{ $period->period === $value ? 'true' : 'false' }}"
            title="{{ \App\Support\DashboardPeriod::PERIODS[$value] }}">{{ $label }}</button>
    @endforeach
  </div>

  @if(in_array($period->period, ['year', 'month']))
  <select class="fsel" name="year" onchange="this.form.submit()" aria-label="Year">
    @foreach($years as $y)
    <option value="{{ $y }}" {{ $period->from->year === $y ? 'selected' : '' }}>{{ $y }}</option>
    @endforeach
  </select>
  @endif

  @if($period->period === 'month')
  <select class="fsel" name="month" onchange="this.form.submit()" aria-label="Month">
    @foreach(range(1, 12) as $m)
    <option value="{{ $m }}" {{ $period->from->month === $m ? 'selected' : '' }}>{{ \Illuminate\Support\Carbon::create(2000, $m, 1)->format('F') }}</option>
    @endforeach
  </select>
  @endif

  @if($period->period === 'week')
  <span class="period-lbl">Week of</span>
  <x-date-field name="week" :value="$period->from" submit label="Week of" />
  @endif

  @if($period->period === 'range')
  <x-date-field name="from" :value="$period->from" submit label="From" />
  <span class="period-lbl">to</span>
  <x-date-field name="to" :value="$period->to" submit label="To" />
  @endif

  {{ $slot }}

  <noscript><button class="btn btn-outline btn-sm">Apply</button></noscript>
</form>
