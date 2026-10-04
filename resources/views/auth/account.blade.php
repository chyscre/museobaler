@extends($layout)
@section('title', 'My Account — Museo de Baler')

@push('styles')
<style>
.acct{max-width:720px}
.acct-card{margin-bottom:14px;overflow:hidden}
/* The phone layout pads every .card; these carry their own padding inside. */
.acct .acct-card{padding:0}
.acct-hd{display:flex;align-items:center;gap:10px;padding:14px 18px;background:#fafafa;border-bottom:1px solid var(--border)}
.acct-hd svg{width:15px;height:15px;color:var(--green-dark);flex-shrink:0}
.acct-title{font-size:13px;font-weight:700;color:var(--text)}
.acct-sub{font-size:11px;color:var(--text-3);margin-top:1px}
.acct-body{padding:16px 18px}
.acct-who{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.acct-avatar{width:48px;height:48px;border-radius:50%;background:var(--green-pale);color:var(--green-dark);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0}
.acct-name{font-size:16px;font-weight:700;color:var(--text)}
.acct-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px 18px;margin:0}
.acct-grid dt{font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
.acct-grid dd{margin:0;font-size:13px;color:var(--text);overflow-wrap:anywhere}
.acct-note{font-size:12px;color:var(--text-3);margin-top:14px;line-height:1.55}
.acct-note a{color:var(--green-dark);font-weight:600}
.pw-wrap{position:relative}
.pw-wrap .fi{padding-right:58px}
.pw-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;color:var(--text-3);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:4px 6px}
.fe{font-size:12px;color:var(--red);margin-top:4px}
.fh{font-size:11.5px;color:var(--text-3);margin-top:4px;line-height:1.5}
.acct-save{display:flex;align-items:center;justify-content:flex-end;gap:12px;padding:12px 18px;border-top:1px solid var(--border);background:#fafafa}
</style>
@endpush

@section('content')
<div class="ph">
  <div class="ph-left"><h2>My Account</h2></div>
</div>

<div class="acct">

  {{-- ── Profile ──
       Read-only on purpose. Accounts are issued by the Tourism office and
       nobody edits their own name or sign-in email: the name is what the
       audit trail attributes every change to, and the email is the login. --}}
  <section class="card acct-card" aria-labelledby="acct-profile">
    <div class="acct-hd">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      <div>
        <div class="acct-title" id="acct-profile">Profile</div>
        <div class="acct-sub">Who this account belongs to</div>
      </div>
    </div>
    <div class="acct-body">
      <div class="acct-who">
        <div class="acct-avatar" aria-hidden="true">{{ strtoupper(substr($staff->name, 0, 2)) }}</div>
        <div>
          <div class="acct-name">{{ $staff->name }}</div>
          <span class="badge {{ $staff->isTourismHead() ? 'b-purple' : 'b-gold' }}">{{ $staff->role_label }}</span>
        </div>
      </div>
      <dl class="acct-grid">
        <div><dt>Sign-in email</dt><dd>{{ $staff->email }}</dd></div>
        <div><dt>Password last changed</dt><dd>{{ $staff->password_changed_at ? $staff->password_changed_at->format('M j, Y g:i A') : 'Not yet' }}</dd></div>
        <div><dt>Previous sign-in</dt><dd>
          @if($previousSignIn)
            {{ $previousSignIn->created_at->format('M j, Y g:i A') }}@if($previousSignIn->ip_address) <span style="color:var(--text-3)">from {{ $previousSignIn->ip_address }}</span>@endif
          @else
            None before this one
          @endif
        </dd></div>
      </dl>
      <p class="acct-note">
        @if($staff->isTourismHead())
          Names and sign-in emails are changed on the <a href="{{ route('staff.index') }}">Staff</a> page.
        @else
          Only the Tourism office can change your name or sign-in email. Ask them if either is wrong.
        @endif
        If you do not recognise the previous sign-in, change your password below.
      </p>
    </div>
  </section>

  {{-- ── Password ── --}}
  <section class="card acct-card" aria-labelledby="acct-password">
    <div class="acct-hd">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      <div>
        <div class="acct-title" id="acct-password">Password</div>
        <div class="acct-sub">Changing it signs out every other device using this account</div>
      </div>
    </div>
    <form method="POST" action="{{ route('password.update') }}">
      @csrf
      @method('PUT')
      <div class="acct-body">
        <div class="fg">
          <label class="fl" for="current_password">Current password</label>
          <div class="pw-wrap">
            <input class="fi" type="password" id="current_password" name="current_password" required autocomplete="current-password">
            <button type="button" class="pw-toggle" data-target="current_password">Show</button>
          </div>
          @error('current_password')<div class="fe">{{ $message }}</div>@enderror
        </div>
        <div class="fi-row" style="margin-bottom:0">
          <div class="fg" style="margin-bottom:0">
            <label class="fl" for="password">New password</label>
            <div class="pw-wrap">
              <input class="fi" type="password" id="password" name="password" required autocomplete="new-password">
              <button type="button" class="pw-toggle" data-target="password">Show</button>
            </div>
            @error('password')<div class="fe">{{ $message }}</div>@enderror
          </div>
          <div class="fg" style="margin-bottom:0">
            <label class="fl" for="password_confirmation">Repeat new password</label>
            <div class="pw-wrap">
              <input class="fi" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
              <button type="button" class="pw-toggle" data-target="password_confirmation">Show</button>
            </div>
          </div>
        </div>
        <p class="fh">{{ $hint }}</p>
      </div>
      <div class="acct-save">
        <button type="submit" class="btn btn-green">Change Password</button>
      </div>
    </form>
  </section>

</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.dataset.target);
    var shown = input.type === 'text';
    input.type = shown ? 'password' : 'text';
    btn.textContent = shown ? 'Show' : 'Hide';
  });
});
</script>
@endpush
