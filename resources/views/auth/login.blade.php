<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Museo de Baler — Admin Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  @vite(['resources/css/app.css'])
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
    .card{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.18);width:100%;max-width:900px;display:grid;grid-template-columns:1fr 420px;overflow:hidden;min-height:560px}
    .left{background:linear-gradient(145deg,#f0fdf4,#dcfce7,#bbf7d0);padding:56px 60px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center}
    .brand-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#16a34a,#22c55e);display:flex;align-items:center;justify-content:center;margin-bottom:24px;box-shadow:0 8px 24px rgba(34,197,94,.25)}
    .brand-icon svg{width:26px;height:26px;color:#fff}
    .left h2{font-size:32px;color:#14532d;line-height:1.2;margin-bottom:8px}
    .left-sub{font-size:14px;color:#16a34a;font-weight:600;letter-spacing:.04em;margin:0}
    .right{background:#fff;padding:48px 44px;display:flex;flex-direction:column;justify-content:center}
    .right h3{font-size:26px;color:#1a1a1a;margin-bottom:6px}
    .right .sub{font-size:13px;color:#6b7280;margin-bottom:28px}
    .fg{margin-bottom:16px}
    label{display:block;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
    .input-wrap{display:flex;align-items:center;gap:10px}
    .input-wrap>svg{width:18px;height:18px;color:#9ca3af;flex-shrink:0}
    .input-wrap input{flex:1;padding:11px 40px 11px 14px;border-radius:9px;border:1.5px solid #e5e7eb;font-size:13px;color:#1a1a1a;background:#fafafa;outline:none;transition:border-color .15s;font-family:'Instrument Sans',sans-serif}
    .input-wrap input:focus{border-color:#22c55e}
    .pw-wrap{position:relative;flex:1}
    .pw-wrap input{width:100%;padding:11px 40px 11px 14px;border-radius:9px;border:1.5px solid #e5e7eb;font-size:13px;color:#1a1a1a;background:#fafafa;outline:none;transition:border-color .15s;font-family:'Instrument Sans',sans-serif}
    .pw-wrap input:focus{border-color:#22c55e}
    .pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:#9ca3af;background:none;border:none;display:flex;align-items:center}
    .pw-toggle svg{width:16px;height:16px}
    .row{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
    .remember{display:flex;align-items:center;gap:7px;font-size:12.5px;color:#374151;cursor:pointer}
    .remember input{accent-color:#22c55e;width:14px;height:14px}
    .btn-login{width:100%;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;border:none;border-radius:9px;padding:13px;font-family:'Instrument Sans',sans-serif;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 16px rgba(34,197,94,.28);transition:opacity .15s}
    .btn-login:hover{opacity:.9}
    .btn-login svg{width:16px;height:16px}
    .error-box{color:#b91c1c;font-size:12.5px;margin-bottom:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px}
    .lockout-box{margin-bottom:14px;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:12px;padding:16px 18px}
    .footer-note{margin-top:28px;padding-top:22px;border-top:1px solid #f3f4f6;font-size:11.5px;color:#9ca3af;text-align:center;line-height:1.6}
    @media(max-width:700px){.card{grid-template-columns:1fr}.left{display:none}}
  </style>
</head>
<body>
<div class="card">
  <!-- LEFT PANEL -->
  <div class="left">
    <div class="brand-icon">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    </div>
    <h2>Museo de Baler</h2>
    <p class="left-sub">Admin Panel</p>
  </div>

  <!-- RIGHT PANEL -->
  <div class="right">
    <h3>Welcome back</h3>
    <p class="sub">Sign in to your admin account to continue.</p>

    @if(session('lockout_seconds'))
      <div class="lockout-box" id="lockoutBox">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
          <div style="display:flex;align-items:center;gap:8px;flex:1">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:#b91c1c;flex-shrink:0"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span style="font-size:12px;color:#b91c1c;font-weight:600">Too many attempts — try again in</span>
          </div>
          <div id="countdown-display" style="font-size:18px;font-weight:800;color:#b91c1c;font-variant-numeric:tabular-nums;letter-spacing:1px;flex-shrink:0">
            <span id="cd-min">01</span>:<span id="cd-sec">00</span>
          </div>
        </div>
        <div style="margin-top:8px;height:3px;background:#fecaca;border-radius:4px;overflow:hidden">
          <div id="cd-bar" style="height:100%;background:#ef4444;border-radius:4px;transition:width 1s linear;width:100%"></div>
        </div>
      </div>
    @elseif($errors->any())
      <div class="error-box">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login.post') }}">
      @csrf
      <div class="fg">
        <label>Email</label>
        <div class="input-wrap">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <input type="email" name="email" value="{{ old('email') }}" placeholder="admin@museobaler.ph" required autofocus autocomplete="email">
        </div>
      </div>
      <div class="fg">
        <label>Password</label>
        <div class="input-wrap">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <div class="pw-wrap">
            <input type="password" name="password" id="password" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="pw-toggle" onclick="togglePw()" id="pwToggle">
              <svg id="pwEye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="row">
        {{-- SECURITY: Remember me removed for admin panel.
             Persistent login tokens are inappropriate for an admin system —
             they keep a session alive for weeks and increase the attack window
             if a device is lost or stolen. Admins must re-authenticate each session. --}}
      </div>
      <button type="submit" class="btn-login">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Sign In
      </button>
    </form>

    <div class="footer-note">Museo de Baler Admin Panel<br>Authorized personnel only</div>
  </div>
</div>

<script>
lucide.createIcons();

@if(session('lockout_seconds'))
(function() {
  var total = {{ session('lockout_seconds') }};
  var remaining = total;
  var minEl  = document.getElementById('cd-min');
  var secEl  = document.getElementById('cd-sec');
  var barEl  = document.getElementById('cd-bar');
  var btnEl  = document.querySelector('.btn-login');
  var formEl = document.querySelector('form');

  // Disable the login button while locked out
  if (btnEl) { btnEl.disabled = true; btnEl.style.opacity = '0.45'; btnEl.style.cursor = 'not-allowed'; }
  if (formEl) formEl.addEventListener('submit', function(e){ if(remaining > 0) e.preventDefault(); });

  function pad(n){ return n < 10 ? '0'+n : ''+n; }

  function tick() {
    var m = Math.floor(remaining / 60);
    var s = remaining % 60;
    if (minEl) minEl.textContent = pad(m);
    if (secEl) secEl.textContent = pad(s);
    if (barEl) barEl.style.width = ((remaining / total) * 100) + '%';

    if (remaining <= 0) {
      // Unlock — reload the page so the form is usable again
      window.location.reload();
      return;
    }
    remaining--;
    setTimeout(tick, 1000);
  }

  tick();
})();
@endif

function togglePw() {
  var inp = document.getElementById('password');
  var eye = document.getElementById('pwEye');
  if (inp.type === 'password') {
    inp.type = 'text';
    eye.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
  } else {
    inp.type = 'password';
    eye.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  }
}
</script>
</body>
</html>
