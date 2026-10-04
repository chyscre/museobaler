{{-- A day's scans, oldest first: when, what, and where it stands. --}}
@if($scans->isEmpty())
  <p class="dt-muted" style="margin:4px 0 0">No exhibits scanned that day.</p>
@else
  <ol class="dt-scans">
    @foreach($scans as $s)
      <li>
        <span class="dt-time">{{ $s->scanned_at->format('g:i A') }}</span>
        <span>
          <strong>{{ $s->exhibit?->name ?? 'Removed exhibit' }}</strong>
          @if($s->exhibit?->exhibit_code)<code>{{ $s->exhibit->exhibit_code }}</code>@endif
          <small>{{ collect([$s->exhibit?->hall, $s->exhibit?->floor])->filter()->implode(' · ') ?: 'No hall set' }}</small>
        </span>
      </li>
    @endforeach
  </ol>
@endif
