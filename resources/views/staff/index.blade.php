@extends('layouts.admin')
@section('title','Staff — Museo de Baler')

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>Staff</h2>
    <p>Manage museum staff accounts</p>
  </div>
  <div class="ph-right">
    <button class="btn btn-outline btn-sm" onclick="window.print()">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Report
    </button>
    <button class="btn btn-green btn-sm" onclick="document.getElementById('addModal').classList.add('open')">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
      Add Staff
    </button>
  </div>
</div>

{{-- Handover panel. The only moment this password is readable anywhere:
     it was generated one request ago, flashed to this page, and never
     stored in plaintext. If it is lost before it reaches the staff member,
     the fix is to issue another one. --}}
@if(session('issued_credential'))
  @php
    $cred = session('issued_credential');
  @endphp
  <div class="card card-p-lg" style="margin-bottom:18px;border:1.5px solid #fde68a;background:#fffbeb">
    <div style="display:flex;align-items:flex-start;gap:12px">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:20px;height:20px;color:#b45309;flex-shrink:0;margin-top:2px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      <div style="flex:1;min-width:0">
        <h3 class="sec-title" style="margin-bottom:3px;color:#92400e">Temporary password for {{ $cred['name'] }}</h3>
        <p style="font-size:12.5px;color:#92400e;line-height:1.6;margin-bottom:13px">
          Write this down or hand it over now — it is not shown again and is
          not stored anywhere. {{ $cred['name'] }} will be asked to replace it
          the first time they sign in, after which nobody but them knows it.
        </p>

        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
          <code id="credPw" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:17px;font-weight:700;letter-spacing:.04em;background:#fff;border:1.5px solid #fcd34d;border-radius:9px;padding:10px 14px;color:#1a1a1a;user-select:all">{{ $cred['password'] }}</code>
          <button type="button" class="btn btn-outline btn-sm" onclick="copyCred()">Copy</button>
          <span id="credCopied" style="display:none;font-size:12px;font-weight:600;color:var(--green-dark)">Copied</span>
        </div>

        <p style="font-size:11.5px;color:#a16207;margin-top:11px">
          Sign-in email: <strong>{{ $cred['email'] }}</strong>
        </p>
      </div>
    </div>
  </div>
@endif

<div class="fbar">
  <div class="search-box">
    <svg class="si" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="text" id="staffSearch" placeholder="Search staff…" oninput="filterStaff()">
  </div>
  <x-fctl icon="shield">
    <select class="fsel" id="staffRole" onchange="filterStaff()">
      <option value="">All Roles</option>
      @foreach(\App\Models\Staff::ROLES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
    </select>
  </x-fctl>
  <x-fctl icon="status">
    <select class="fsel" id="staffStatus" onchange="filterStaff()">
      <option value="">All Status</option>
      <option value="Active">Active</option><option value="Inactive">Inactive</option>
    </select>
  </x-fctl>
  <span id="staffCount" class="fcount"></span>
</div>

<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Password</th><th>Created</th><th>Actions</th>
      </tr>
    </thead>
    <tbody id="staffTbody">
    @foreach($staff as $s)
    <tr data-name="{{ strtolower($s->name) }}" data-role="{{ $s->role }}" data-status="{{ $s->status ? 'Active' : 'Inactive' }}">
      <td>{{ $s->name }}</td>
      <td>{{ $s->email }}</td>
      <td>
        <span class="badge {{ $s->role === 'TourismHead' ? 'b-purple' : ($s->role === 'Administrator' ? 'b-gold' : 'b-gray') }}">
          {{ $s->role_label }}
        </span>
      </td>
      <td><span class="badge {{ $s->status ? 'b-green' : 'b-red' }}">{{ $s->status ? 'Active' : 'Inactive' }}</span></td>
      {{-- Which accounts are still on a password this office knows. Anything
           marked Temporary is an account whose actions cannot yet be pinned
           on one person. --}}
      <td>
        @if($s->must_change_password)
          <span class="badge b-gold" title="Still using the password issued to them — they will be asked to replace it at next sign-in">Temporary</span>
        @else
          <span class="badge b-green" title="Set by {{ $s->name }}. Nobody else knows it.">Their own</span>
          @if($s->password_changed_at)
            <div style="font-size:11px;color:var(--text-3);margin-top:3px">{{ $s->password_changed_at->format('M j, Y') }}</div>
          @endif
        @endif
      </td>
      <td>{{ $s->created_at ? \Carbon\Carbon::parse($s->created_at)->format('M j, Y') : '—' }}</td>
      <td>
        <div style="display:flex;gap:6px">
          <button class="btn btn-outline btn-xs" onclick="openStaffEditModal({{ $s->staff_id }})">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit
          </button>
          @if($s->staff_id !== auth()->id())
          <form method="POST" action="{{ route('staff.reset-password', $s) }}" style="display:inline"
                onsubmit="return confirm('Issue a new temporary password for {{ $s->name }}? Their current password stops working immediately.')">
            @csrf
            <button class="btn btn-outline btn-xs" title="For someone who is locked out. You will be shown the new password once.">Reset password</button>
          </form>
          @endif
          @if($s->staff_id !== auth()->id())
          <form method="POST" action="{{ route('staff.toggle', $s) }}" style="display:inline" onsubmit="return confirm('{{ $s->status ? 'Deactivate' : 'Activate' }} this staff member?')">
            @csrf
            <button class="btn {{ $s->status ? 'btn-red' : 'btn-green' }} btn-xs">
              {{ $s->status ? 'Deactivate' : 'Activate' }}
            </button>
          </form>
          @endif
        </div>
      </td>
    </tr>
    @endforeach
    </tbody>
  </table>
</div>

<!-- Add Staff Modal -->
<div class="overlay" id="addModal" @if($errors->any() && old('_form') === 'add_staff') style="display:flex" @endif>
  <div class="modal">
    <div class="modal-hd">
      <h3>Add Staff</h3>
      <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="{{ route('staff.store') }}">
      @csrf
      <input type="hidden" name="_form" value="add_staff">

      @if($errors->any() && old('_form') === 'add_staff')
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#b91c1c">
          <ul style="margin:0;padding-left:16px">
            @foreach($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <div class="fg"><label class="fl">Full Name</label><input class="fi" name="name" value="{{ old('name') }}" required placeholder="e.g. Maria Santos"></div>
      <div class="fg"><label class="fl">Email</label><input class="fi" type="email" name="email" value="{{ old('email') }}" required placeholder="staff@museobaler.ph"></div>
      {{-- No password box, deliberately. Anything typed here would be a
           password memorable enough to say out loud, it would never be
           changed, and the office would go on knowing a working credential
           for this account forever. One is generated on save instead and
           shown once, and the staff member replaces it at first sign-in. --}}
      <div class="fg">
        <label class="fl">Password</label>
        <div style="display:flex;gap:9px;align-items:flex-start;background:var(--border-light,#f1f5f9);border-radius:9px;padding:11px 13px">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:var(--text-3);flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          <div style="font-size:12px;color:var(--text-2);line-height:1.6">
            A temporary password is generated when you save, and shown to you
            once so you can hand it over. They must replace it with one of
            their own before they can use the system.
          </div>
        </div>
      </div>
      <div class="fg"><label class="fl">Role</label>
        {{-- Only the roles this account is allowed to hand out: a museum
             Administrator cannot mint another Administrator or a Tourism
             account. StaffController enforces the same list server-side. --}}
        <select class="fi" name="role">
          @foreach($assignableRoles as $r)
            <option value="{{ $r }}" {{ old('role', \App\Models\Staff::ROLE_ADMIN) === $r ? 'selected' : '' }}>{{ \App\Models\Staff::ROLES[$r] }}</option>
          @endforeach
        </select>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-green">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Staff Modal -->
<div class="overlay" id="staffEditModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-hd">
      <h3>Edit Staff</h3>
      <button class="modal-close" onclick="closeStaffEditModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div id="staffEditModalBody" style="min-height:100px;display:flex;align-items:center;justify-content:center">
      <div class="spinner"></div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
function copyCred(){
  const code = document.getElementById('credPw');
  if(!code) return;
  const done = () => {
    const tag = document.getElementById('credCopied');
    tag.style.display = 'inline';
    setTimeout(() => { tag.style.display = 'none'; }, 2000);
  };
  // The panel is served over the tunnel as well as over plain http on the
  // LAN, and the clipboard API is unavailable on the latter — so there is a
  // selection-based fallback rather than a button that silently does nothing.
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(code.textContent.trim()).then(done).catch(selectFallback);
  } else {
    selectFallback();
  }
  function selectFallback(){
    const range = document.createRange();
    range.selectNodeContents(code);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    try { document.execCommand('copy'); done(); } catch(e) {}
  }
}

function filterStaff(){
  const q=document.getElementById('staffSearch').value.toLowerCase();
  const role=document.getElementById('staffRole').value;
  const status=document.getElementById('staffStatus').value;
  const rows=Array.from(document.querySelectorAll('#staffTbody tr'));
  let visible=0;
  rows.forEach(r=>{
    const match=(!q||r.dataset.name.includes(q))&&(!role||r.dataset.role===role)&&(!status||r.dataset.status===status);
    r.style.display=match?'':'none';
    if(match)visible++;
  });
  document.getElementById('staffCount').textContent=visible+' staff member'+(visible!==1?'s':'');
}
filterStaff();
document.getElementById('addModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')});

@if($errors->any() && old('_form') === 'add_staff')
document.getElementById('addModal').classList.add('open');
@endif

function openStaffEditModal(id){
  const overlay=document.getElementById('staffEditModal');
  const body=document.getElementById('staffEditModalBody');
  body.innerHTML='<div class="spinner"></div>';
  overlay.classList.add('open');
  fetch('{{ url('/') }}/staff/'+id+'/edit-form')
    .then(r=>r.text())
    .then(html=>{ body.innerHTML=html; })
    .catch(()=>{ body.innerHTML='<p style="color:var(--red);padding:20px">Failed to load form.</p>'; });
}
function closeStaffEditModal(){
  document.getElementById('staffEditModal').classList.remove('open');
}
document.getElementById('staffEditModal').addEventListener('click',function(e){if(e.target===this)closeStaffEditModal()});
</script>
@endpush
