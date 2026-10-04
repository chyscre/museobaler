@props([
  'name'   => null,
  'value'  => null,
  'type'   => 'date',
  'submit' => false,
  'block'  => false,
  'label'  => null,
])

{{--
  The one date control in the panel.

  A button that reads "Select Date" until a date is chosen and then reads
  the date itself ("Oct 4, 2026", or "Oct 2026" for a month). The browser's
  own input sits invisibly inside it, so the calendar, validation and the
  submitted value are still the browser's; only its face is replaced. That
  face was the problem: an empty date input shows a raw "mm/dd/yyyy" mask,
  and every screen had styled the rest of it slightly differently.

  name, value   as on the input; value is Y-m-d (Y-m for a month)
  type          'date' or 'month'
  submit        submit the surrounding form as soon as the date changes
  block         full width, for a field in a form rather than a header
  label         accessible name when nothing visible labels the field

  Any other attribute (id, class, min, max, required, onchange) lands on the
  input itself, so scripts that read `.rm-from` or `#dtrMonth` still work.
--}}
@php
  $value = $value instanceof \DateTimeInterface
    ? $value->format($type === 'month' ? 'Y-m' : 'Y-m-d')
    : ($value ?: null);

  try {
    $text = $value
      ? \Illuminate\Support\Carbon::parse($type === 'month' ? $value . '-01' : $value)->format($type === 'month' ? 'M Y' : 'M j, Y')
      : null;
  } catch (\Throwable) {
    $text = null;
  }
@endphp

<label class="date-field{{ $block ? ' date-field-block' : '' }}{{ $text ? '' : ' is-empty' }}" data-date-field>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
  <span class="date-field-text">{{ $text ?? 'Select Date' }}</span>
  <input type="{{ $type }}"
         @if($name) name="{{ $name }}" @endif
         value="{{ $value }}"
         @if($label) aria-label="{{ $label }}" @endif
         @if($submit) data-submit @endif
         {{ $attributes->class(['date-field-input']) }}>
</label>

@once
<script>
  (function () {
    var fmt = {
      date:  { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' },
      month: { month: 'short', year: 'numeric', timeZone: 'UTC' }
    };

    // The field's own text follows its value. UTC on both ends, or a date
    // picked in Manila renders as the day before.
    function sync(input) {
      var field = input.closest('[data-date-field]');
      if (!field) return;
      var v = input.value, text = 'Select Date';
      if (v) {
        var d = new Date((input.type === 'month' ? v + '-01' : v) + 'T00:00:00Z');
        if (!isNaN(d)) text = d.toLocaleDateString('en-US', fmt[input.type] || fmt.date);
      }
      field.querySelector('.date-field-text').textContent = text;
      field.classList.toggle('is-empty', !v);
    }

    function open(input) {
      try { input.showPicker(); } catch (e) { input.focus(); input.click(); }
    }

    // Firefox and Safari have no month picker and quietly turn the input
    // into a text box. An invisible text box cannot be typed into, so those
    // fields drop the button face and show the plain input instead.
    function plainWhereUnsupported(root) {
      (root || document).querySelectorAll('[data-date-field] .date-field-input').forEach(function (i) {
        if (i.type !== 'date' && i.type !== 'month') i.closest('[data-date-field]').classList.add('date-field-plain');
      });
    }

    document.addEventListener('click', function (e) {
      var field = e.target.closest('[data-date-field]');
      if (!field || field.classList.contains('date-field-plain')) return;
      e.preventDefault();
      open(field.querySelector('.date-field-input'));
    });

    document.addEventListener('keydown', function (e) {
      var input = e.target.closest && e.target.closest('[data-date-field] .date-field-input');
      if (!input || input.closest('.date-field-plain')) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(input); }
    });

    document.addEventListener('change', function (e) {
      var input = e.target.closest && e.target.closest('[data-date-field] .date-field-input');
      if (!input) return;
      sync(input);
      // An emptied field submits too: on a filter, clearing the date is how
      // the filter is taken off.
      if (input.hasAttribute('data-submit') && input.form) input.form.submit();
    });

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { plainWhereUnsupported(); });
    } else {
      plainWhereUnsupported();
    }
  })();
</script>
@endonce
