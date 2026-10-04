@props(['day', 'unit' => 'record'])

{{--
  Choosing which day of a logbook to read.

  One calendar rather than previous/next arrows: the arrows only stepped to
  the neighbouring day with something on it, a long walk to last month, and
  the calendar already reaches yesterday in one tap. It stops at today -
  the future has nothing in it yet.
--}}
@php
  // The tab and filters ride along with the picked date as hidden fields,
  // flattened the way a query string would carry them.
  $carried = collect(explode('&', http_build_query($day->carriedQuery())))
    ->filter()
    ->map(fn ($pair) => array_map('urldecode', array_pad(explode('=', $pair, 2), 2, '')));
@endphp

<div class="day-bar">
  <div class="day-bar-left">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    <div>
      <div class="day-bar-title">{{ $day->label() }}</div>
      <div class="day-bar-sub">
        {{ $day->date?->format('l, j F Y') ?: 'Nothing recorded yet' }}
        @if($day->date) · {{ number_format($day->count()) }} {{ Str::plural($unit, $day->count()) }} @endif
      </div>
    </div>
  </div>

  <div class="day-nav">
    <form class="day-pick" method="GET" action="{{ $day->pages->path() }}">
      @foreach($carried as [$k, $v])
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
      @endforeach
      <x-date-field name="day" :value="$day->date ?? today()" submit
                    label="Go to a date" :max="today()->toDateString()" />
    </form>
  </div>
</div>
