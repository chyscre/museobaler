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
    /* ── Split sign-in: identity on the left, the form on the right, one deep
       green field behind both. The green is the admin palette's own
       --green-dark/#14532d family rather than a new colour. ── */
    :root{
      --ink:#16291f;            /* deep, desaturated green — a dark neutral with a green cast */
      --ink-deep:#0d1712;
      --ink-soft:#1c3327;
      --accent:#22c55e;         /* --green      */
      --accent-dark:#16a34a;    /* --green-dark */
      --on-ink:#ffffff;
      --on-ink-dim:rgba(255,255,255,.62);
      --field:rgba(255,255,255,.05);
      --field-line:rgba(255,255,255,.22);
    }
    *{box-sizing:border-box}
    /* app.css paints html #D6CFC4, and a background on <html> stops the body's
       own background propagating to the canvas — so the deep green is set here
       too, or a pale band shows through on over-scroll. */
    html{background:var(--ink-deep)}
    body{
      margin:0;min-height:100vh;padding:24px;
      display:flex;align-items:center;justify-content:center;
      font-family:'Instrument Sans',sans-serif;
      background:
        radial-gradient(1000px 560px at 14% 16%, rgba(34,197,94,.07) 0%, transparent 60%),
        linear-gradient(145deg, var(--ink-deep) 0%, var(--ink) 52%, #101d17 100%);
    }
    .shell{
      width:100%;max-width:1040px;min-height:560px;
      display:grid;grid-template-columns:1fr 1px 1fr;align-items:stretch;
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.10);
      border-radius:22px;
      box-shadow:0 30px 80px rgba(0,0,0,.42);
      overflow:hidden;
      backdrop-filter:blur(2px);
    }

    /* ── LEFT: the mark and the wordmark, side by side ── */
    .identity{
      display:flex;align-items:center;justify-content:center;gap:22px;
      padding:56px 48px;
    }
    .identity img.seal{width:120px;height:120px;flex-shrink:0;display:block}
    .identity img.wordmark{max-width:260px;width:100%;height:auto;display:block;min-width:0}

    .divider{background:linear-gradient(180deg,transparent,rgba(255,255,255,.22) 18%,rgba(255,255,255,.22) 82%,transparent)}

    /* ── RIGHT: the form ── */
    .panel{padding:56px 60px;display:flex;flex-direction:column;justify-content:center}
    .panel h1{
      font-family:'Young Serif',serif;font-weight:400;
      font-size:34px;line-height:1.15;color:var(--on-ink);margin:0 0 6px;
    }
    .panel .sub{
      font-size:11.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;
      color:var(--on-ink-dim);margin:0 0 30px;
    }
    .fg{margin-bottom:14px}
    label{
      display:block;font-size:10.5px;font-weight:700;letter-spacing:.12em;
      text-transform:uppercase;color:var(--on-ink-dim);margin-bottom:7px;
    }
    .control{position:relative;display:flex;align-items:center}
    .control>svg{
      position:absolute;left:14px;width:17px;height:17px;
      color:rgba(255,255,255,.45);pointer-events:none;
    }
    .control input{
      width:100%;padding:13px 44px 13px 44px;
      border-radius:10px;border:1.5px solid var(--field-line);
      background:var(--field);color:var(--on-ink);
      font-family:'Instrument Sans',sans-serif;font-size:13.5px;
      outline:none;transition:border-color .15s, background .15s;
    }
    .control input::placeholder{color:rgba(255,255,255,.5)}   /* .38 measured 3.4:1 — under AA */
    .control input:focus{border-color:var(--accent);background:rgba(255,255,255,.09)}
    /* Chrome paints its own near-white autofill background, which would put
       dark-on-dark text in these fields. */
    .control input:-webkit-autofill,
    .control input:-webkit-autofill:focus{
      -webkit-text-fill-color:var(--on-ink);
      -webkit-box-shadow:0 0 0 1000px var(--ink-soft) inset;
      caret-color:var(--on-ink);
    }
    .pw-toggle{
      position:absolute;right:12px;display:flex;align-items:center;
      background:none;border:none;padding:4px;cursor:pointer;color:rgba(255,255,255,.5);
    }
    .pw-toggle:hover{color:rgba(255,255,255,.85)}
    .pw-toggle svg{width:16px;height:16px}

    .btn-login{
      width:100%;margin-top:14px;padding:14px;
      background:linear-gradient(135deg,var(--accent-dark),var(--accent));
      color:#fff;border:none;border-radius:10px;
      font-family:'Instrument Sans',sans-serif;font-size:14px;font-weight:700;
      letter-spacing:.02em;cursor:pointer;
      display:flex;align-items:center;justify-content:center;gap:9px;
      box-shadow:0 8px 22px rgba(34,197,94,.22);
      transition:transform .12s, box-shadow .15s, opacity .15s;
    }
    .btn-login:hover{transform:translateY(-1px);box-shadow:0 12px 28px rgba(34,197,94,.30)}
    .btn-login:active{transform:translateY(0)}
    .btn-login svg{width:16px;height:16px}

    /* Near-opaque red rather than a translucent wash: a 16% red tint over the
       green panel came out olive, which does not read as an error at all. */
    .error-box{
      color:#fecaca;font-size:12.5px;margin-bottom:14px;
      background:#4c1d1d;border:1px solid #b91c1c;
      border-radius:10px;padding:11px 14px;
    }
    .lockout-box{
      margin-bottom:14px;background:#4c1d1d;
      border:1.5px solid #b91c1c;border-radius:12px;padding:16px 18px;
    }
    .footer-note{
      margin-top:30px;padding-top:20px;border-top:1px solid rgba(255,255,255,.12);
      font-size:11px;color:rgba(255,255,255,.58);text-align:center;line-height:1.7;
    }

    /* ── Stacked below the split ── */
    @media(max-width:860px){
      body{padding:0}
      .shell{
        grid-template-columns:1fr;grid-template-rows:auto 1px 1fr;
        min-height:100vh;border-radius:0;border:none;max-width:none;
      }
      .identity{padding:38px 28px 30px;gap:18px}
      .identity img.seal{width:76px;height:76px}
      .identity img.wordmark{max-width:190px}
      .divider{background:rgba(255,255,255,.16)}
      .panel{padding:34px 26px 44px}
      .panel h1{font-size:28px}
    }
    @media(max-width:380px){
      .identity{flex-direction:column;text-align:center}
    }
  </style>
</head>
<body>
<div class="shell">

  <!-- ── IDENTITY ── -->
  <div class="identity">
    <img class="seal" src="{{ asset('images/logo/baler-seal.png') }}" alt="Seal of the Municipality of Baler, Aurora">
    <img class="wordmark" src="{{ asset('images/logo/museo-wordmark.png') }}" alt="Museo de Baler">
  </div>

  <div class="divider"></div>

  <!-- ── FORM ── -->
  <div class="panel">
    <h1>Welcome</h1>
    <p class="sub">Please log in to continue</p>

    @if(session('lockout_seconds'))
      <div class="lockout-box" id="lockoutBox">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
          <div style="display:flex;align-items:center;gap:8px;flex:1">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:#fecaca;flex-shrink:0"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span style="font-size:12px;color:#fecaca;font-weight:600">Too many attempts — try again in</span>
          </div>
          <div id="countdown-display" style="font-size:18px;font-weight:800;color:#fecaca;font-variant-numeric:tabular-nums;letter-spacing:1px;flex-shrink:0">
            <span id="cd-min">01</span>:<span id="cd-sec">00</span>
          </div>
        </div>
        <div style="margin-top:8px;height:3px;background:rgba(252,165,165,.3);border-radius:4px;overflow:hidden">
          <div id="cd-bar" style="height:100%;background:#f87171;border-radius:4px;transition:width 1s linear;width:100%"></div>
        </div>
      </div>
    @elseif($errors->any())
      <div class="error-box">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login.post') }}">
      @csrf
      <div class="fg">
        <label for="email">Email</label>
        <div class="control">
          <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="admin@museobaler.ph" required autofocus autocomplete="email">
        </div>
      </div>
      <div class="fg">
        <label for="password">Password</label>
        <div class="control">
          <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
          <button type="button" class="pw-toggle" onclick="togglePw()" id="pwToggle" aria-label="Show password">
            <svg id="pwEye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      {{-- SECURITY: Remember me removed for admin panel.
           Persistent login tokens are inappropriate for an admin system —
           they keep a session alive for weeks and increase the attack window
           if a device is lost or stolen. Admins must re-authenticate each session. --}}

      <button type="submit" class="btn-login">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Sign In
      </button>
    </form>

    <div class="footer-note">Authorized personnel only</div>
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
  var btn = document.getElementById('pwToggle');
  if (inp.type === 'password') {
    inp.type = 'text';
    btn.setAttribute('aria-label', 'Hide password');
    eye.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
  } else {
    inp.type = 'password';
    btn.setAttribute('aria-label', 'Show password');
    eye.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  }
}
</script>
</body>
</html>
