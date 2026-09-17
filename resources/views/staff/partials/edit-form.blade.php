<form method="POST" action="{{ route('staff.update', $staff) }}">
  @csrf @method('PUT')
  <div class="fg"><label class="fl">Full Name</label><input class="fi" name="name" value="{{ $staff->name }}" required></div>
  <div class="fg"><label class="fl">Email</label><input class="fi" type="email" name="email" value="{{ $staff->email }}" required></div>
  {{-- No password field here. Setting someone's password is its own action
       with its own audit entry — see the button below the form — so that it
       can never happen quietly while somebody is fixing a typo in a name. --}}
  <div class="fg"><label class="fl">Role</label>
    <select class="fi" name="role">
      @foreach($assignableRoles as $r)
      <option value="{{ $r }}" {{ $staff->role === $r ? 'selected' : '' }}>{{ \App\Models\Staff::ROLES[$r] }}</option>
      @endforeach
    </select>
  </div>
  <div class="modal-ft">
    <button type="button" class="btn btn-outline" onclick="closeStaffEditModal()">Cancel</button>
    <button type="submit" class="btn btn-green">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Save Changes
    </button>
  </div>
</form>

<div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-light)">
  <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Password</div>
  <p style="font-size:12.5px;color:var(--text-3);line-height:1.6;margin-bottom:11px">
    @if($staff->password_changed_at)
      Set by {{ $staff->name }} on {{ $staff->password_changed_at->format('M j, Y') }}. You cannot see it, and you do not need to.
    @else
      Still the temporary password issued to them.
    @endif
  </p>
  @if($staff->staff_id !== auth()->id())
  <form method="POST" action="{{ route('staff.reset-password', $staff) }}"
        onsubmit="return confirm('Issue a new temporary password for {{ $staff->name }}? Their current password stops working immediately.')">
    @csrf
    <button type="submit" class="btn btn-outline btn-sm">Issue a temporary password</button>
  </form>
  @else
  <a href="{{ route('password.edit') }}" class="btn btn-outline btn-sm">Change my password</a>
  @endif
</div>
