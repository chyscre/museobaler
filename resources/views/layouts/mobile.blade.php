<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Museo de Baler')</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  @vite(['resources/css/app.css'])
  <style>
    /* The panel's layout grid assumes a sidebar and a wide main column, and
       neither is here. This is a single column with a bar on top - the whole
       screen is one job: check in, check out, see your own month. */
    body{background:var(--bg,#f8fafc);min-height:100vh}
    .m-bar{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:10px;padding:12px 16px;background:#fff;border-bottom:1px solid var(--border,#e5e7eb)}
    .m-brand{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#16a34a,#22c55e);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .m-brand svg{width:17px;height:17px;color:#fff}
    .m-who{flex:1;min-width:0}
    .m-name{font-size:13.5px;font-weight:700;color:var(--text,#1a1a1a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .m-role{font-size:11px;color:var(--text-3,#9ca3af)}
    .m-out{background:none;border:none;padding:6px;color:var(--text-3,#9ca3af);cursor:pointer;display:flex}
    .m-out svg{width:19px;height:19px}
    .m-body{padding:16px 16px 40px;max-width:560px;margin:0 auto}
    .m-body .card{padding:18px 16px}
    /* Tables are the one thing that cannot shrink to fit, so the month
       history scrolls sideways on its own instead of stretching the page. */
    .m-body .tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .m-body .tbl-scroll table{min-width:460px}
    .m-note{font-size:11.5px;color:var(--text-3,#9ca3af);text-align:center;line-height:1.6;margin-top:22px}
    .m-note a{color:var(--green-dark,#15803d)}
  </style>
  @stack('styles')
</head>
<body>

<div class="m-bar">
  <div class="m-brand">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
  </div>
  <div class="m-who">
    <div class="m-name">{{ auth()->user()->name }}</div>
    <div class="m-role">{{ auth()->user()->role_label }}</div>
  </div>
  <form method="POST" action="{{ route('logout') }}" style="margin:0">
    @csrf
    <button type="submit" class="m-out" title="Sign out">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </button>
  </form>
</div>

<div class="m-body">
  @if(session('success'))
    <div class="alert alert-success" style="margin-bottom:14px">{{ session('success') }}</div>
  @endif
  @if(session('error'))
    <div class="alert alert-error" style="margin-bottom:14px">{{ session('error') }}</div>
  @endif

  @yield('content')

  <p class="m-note">
    @hasSection('note')
      @yield('note')
    @else
      This is the phone view — checking in and out.<br>
    @endif
    The rest of the admin panel is on the office computer.<br>
    <a href="{{ route('password.edit') }}">Change my password</a>
  </p>
</div>

@stack('scripts')
</body>
</html>
