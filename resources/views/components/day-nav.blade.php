@props(['day', 'unit' => 'record'])

{{--
  Stepping through the days of a logbook.

  The arrows carry the date they lead to rather than a word for a direction:
  "22 Sep" says where you land, where "Older day" only said which way you
  were facing, and read like a machine talking about its own pagination.
--}}
@php
  $short = fn ($d) => $d?->isToday() ? 'Today' : ($d?->isYesterday() ? 'Yesterday' : $d?->format('j M'));
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

  @if($day->previous || $day->next)
    <div class="day-nav">
      @if($day->previous)
        <a class="day-nav-btn" href="{{ $day->urlFor($day->previous) }}" rel="prev">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
          {{ $short($day->previous) }}
        </a>
      @else
        <span class="day-nav-btn is-off">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </span>
      @endif

      @if($day->next)
        <a class="day-nav-btn" href="{{ $day->urlFor($day->next) }}" rel="next">
          {{ $short($day->next) }}
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      @else
        <span class="day-nav-btn is-off">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
      @endif
    </div>
  @endif
</div>
