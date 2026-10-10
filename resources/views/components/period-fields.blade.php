@props(['period'])

{{-- The chosen period as hidden fields, so a filter form beside the period
     bar (search, dropdowns) keeps the dates when it submits. --}}
@foreach($period->query() as $k => $v)
  <input type="hidden" name="{{ $k }}" value="{{ $v }}">
@endforeach
