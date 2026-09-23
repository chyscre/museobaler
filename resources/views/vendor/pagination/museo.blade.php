{{--
  The panel's pager.

  Laravel ships Tailwind markup by default and this project has no Tailwind,
  so links() rendered a row of unstyled text where the buttons should have
  been - the stylesheet's .pagination rules never matched anything. This
  emits the markup those rules were written for.

  Day-by-day lists do not use this: they step through dates, so they have
  their own control (<x-day-nav>) that names the day each arrow leads to.
--}}
@if ($paginator->hasPages())
  @php
    $prev = 'Previous';
    $next = 'Next';
  @endphp
  <nav class="pagination" role="navigation" aria-label="Pagination">

    @if ($paginator->onFirstPage())
      <span class="page-item disabled"><span class="page-link">{{ $prev }}</span></span>
    @else
      <a class="page-item page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ $prev }}</a>
    @endif

    @foreach ($elements as $element)
        @if (is_string($element))
          <span class="page-item disabled"><span class="page-link">{{ $element }}</span></span>
        @endif
        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span class="page-item active"><span class="page-link" aria-current="page">{{ $page }}</span></span>
            @else
              <a class="page-item page-link" href="{{ $url }}">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach

    @if ($paginator->hasMorePages())
      <a class="page-item page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ $next }}</a>
    @else
      <span class="page-item disabled"><span class="page-link">{{ $next }}</span></span>
    @endif

  </nav>
@endif
