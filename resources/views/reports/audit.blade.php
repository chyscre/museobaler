@extends('layouts.print')
@section('title', 'Audit Trail')
@section('report-title', 'Audit Trail')
@section('report-meta', $from->format('F j, Y') . ' – ' . $to->format('F j, Y'))

@section('downloads')
  {{-- Its own routes, not reports.export/preview: the audit trail sits
       behind the Tourism wall and is not on the shared routes at all. --}}
  @include('reports.partials.downloads', [
    'report' => 'audit',
    'params' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
    'routes' => ['export' => 'reports.audit.export', 'preview' => 'reports.audit.preview'],
  ])
@endsection

{{-- The audit trail had no printed page: it was CSV only, because it is read
     by whoever is looking for one thing and knows how to filter. It has one
     now because "forward me the trail for that week" is a request the office
     actually gets, and a spreadsheet is not what you attach to a memo. --}}

@section('content')
  <div class="tot">
    <div><span class="k">Entries</span><span class="v">{{ number_format($total) }}</span></div>
    <div><span class="k">Period</span><span class="v">{{ $from->diffInDays($to) + 1 }} days</span></div>
  </div>

  @if($rows->isEmpty())
    <p class="note">No activity was recorded in this period.</p>
  @else
    <table>
      <thead>
        <tr>
          <th style="width:130px">Timestamp</th>
          <th>User</th>
          <th style="width:90px">Role</th>
          <th style="width:110px">Action</th>
          <th>Details</th>
          <th style="width:100px">IP</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $log)
          <tr>
            <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
            <td>{{ $log->user_name }}</td>
            <td><span class="tag t-gray">{{ $log->role }}</span></td>
            <td>{{ $log->action }}</td>
            <td>{{ $log->details }}</td>
            <td>{{ $log->ip_address }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    @if($truncated)
      <p class="note">
        Only the first {{ number_format($rows->count()) }} entries are shown. Narrow the
        date range, or export the CSV, to see the rest.
      </p>
    @endif
  @endif

  <div class="sign">
    <div><div class="line"></div><div class="role">Reviewed by</div></div>
    <div><div class="line"></div><div class="role">Date</div></div>
  </div>
@endsection
