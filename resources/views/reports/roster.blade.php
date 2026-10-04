@extends('layouts.print')
@section('title', 'Employee Master List')
@section('report-title', 'Employee Master List')
@section('report-meta', 'All registered staff accounts as of ' . now()->format('F j, Y'))

@section('downloads')
  {{-- Its own route: the roster sits behind the Tourism wall with the
       accounts it lists, not on the shared report routes. --}}
  @include('reports.partials.downloads', [
    'report' => 'roster',
    'routes' => ['export' => 'staff.roster.export'],
  ])
@endsection

@section('content')
<div class="tot">
  <div><span class="k">Employees</span><span class="v">{{ $staff->count() }}</span></div>
  <div><span class="k">Active</span><span class="v">{{ $active }}</span></div>
  @if($staff->count() > $active)
    <div><span class="k">Inactive</span><span class="v">{{ $staff->count() - $active }}</span></div>
  @endif
  @foreach($byRole as $role => $n)
    <div><span class="k">{{ $role }}</span><span class="v">{{ $n }}</span></div>
  @endforeach
</div>

<table>
  <thead><tr>
    <th style="width:40px">No.</th><th>Name</th><th>Email</th>
    <th style="width:120px">Role</th><th style="width:80px">Status</th><th style="width:100px">Date added</th>
  </tr></thead>
  <tbody>
    @forelse($staff->values() as $i => $s)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $s->name }}</td>
        <td>{{ $s->email }}</td>
        <td>{{ $s->role_label }}</td>
        <td><span class="tag {{ $s->status ? 't-green' : 't-gray' }}">{{ $s->status ? 'Active' : 'Inactive' }}</span></td>
        <td>{{ $s->created_at?->format('M j, Y') }}</td>
      </tr>
    @empty
      <tr><td colspan="6">No staff accounts.</td></tr>
    @endforelse
  </tbody>
</table>

<div class="sign">
  <div><div class="line"></div><div class="role">Prepared by</div></div>
  <div><div class="line"></div><div class="role">Noted by</div></div>
</div>

<p class="note">Generated {{ now()->format('F j, Y g:i A') }}{{ auth()->check() ? ' by ' . auth()->user()->name : '' }}.</p>
@endsection
