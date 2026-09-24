@extends('layouts.admin')
@section('title','Edit Staff — Museo Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Edit Staff</h2>
  </div>
  <div class="ph-right">
    <a href="{{ route('staff.index') }}" class="btn btn-outline btn-sm">← Back</a>
  </div>
</div>
<div class="card card-p" style="max-width:480px">
  <form method="POST" action="{{ route('staff.update', $staff) }}">
    @csrf @method('PUT')
    <div class="fg"><label class="fl">Full Name</label><input class="fi" name="name" value="{{ $staff->name }}" required></div>
    <div class="fg"><label class="fl">Email</label><input class="fi" type="email" name="email" value="{{ $staff->email }}" required></div>
    <div class="fg"><label class="fl">Role</label>
      <select class="fi" name="role">
        @foreach($assignableRoles as $r)
        <option value="{{ $r }}" {{ $staff->role === $r ? 'selected' : '' }}>{{ \App\Models\Staff::ROLES[$r] }}</option>
        @endforeach
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
      <a href="{{ route('staff.index') }}" class="btn btn-outline">Cancel</a>
      <button type="submit" class="btn btn-green">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save Changes
      </button>
    </div>
  </form>
</div>

{{-- Separate from the form above on purpose: issuing a password is its own
     act with its own audit entry, and must not be something that happens as
     a side effect of saving a corrected surname. --}}
<div class="card card-p" style="max-width:480px;margin-top:18px">
  <h3 class="sec-title" style="margin-bottom:4px">Password</h3>
  <p class="sec-sub" style="margin-bottom:14px">
    @if($staff->password_changed_at)
      {{ $staff->name }} set their own password on {{ $staff->password_changed_at->format('M j, Y') }}. You cannot see it.
    @else
      Still using the temporary password issued to them. They will be asked to replace it the next time they sign in.
    @endif
  </p>
  <form method="POST" action="{{ route('staff.reset-password', $staff) }}"
        onsubmit="return confirm('Issue a new temporary password for {{ $staff->name }}? Their current password stops working immediately.')">
    @csrf
    <button type="submit" class="btn btn-outline btn-sm">Issue a temporary password</button>
  </form>
</div>
@endsection
