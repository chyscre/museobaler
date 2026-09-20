{{--
  The error screens.

  Laravel renders errors/{status}.blade.php for any HTTP error when one
  exists, and its own stark grey page otherwise. These say what happened in
  words a front-desk staffer can act on, carry the museum's look, and give
  a way back. None of them prints a stack trace, a file path or a query -
  what the browser sees on a 500 is this page whether APP_DEBUG is on or
  not in production, because AppServiceProvider forces it off there.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title') — Museo de Baler</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  @vite(['resources/css/app.css'])
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
    .er{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.18);width:100%;max-width:420px;padding:36px 30px;text-align:center}
    .er-code{font-family:'Young Serif',serif;font-size:13px;letter-spacing:.18em;color:#9ca3af;margin:0 0 14px}
    .er-icon{width:52px;height:52px;border-radius:14px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
    .er-icon svg{width:26px;height:26px;color:#64748b}
    .er h1{font-size:21px;color:#1a1a1a;margin:0 0 10px;line-height:1.3}
    .er p{font-size:13.5px;color:#6b7280;line-height:1.65;margin:0 0 14px}
    .er-actions{margin-top:22px;padding-top:20px;border-top:1px solid #f3f4f6;display:flex;gap:14px;justify-content:center;flex-wrap:wrap}
    .er-actions a,.er-actions button{background:none;border:none;color:#16a34a;font-family:'Instrument Sans',sans-serif;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
    .er-actions a:hover,.er-actions button:hover{text-decoration:underline}
    .er-actions .muted{color:#6b7280;font-weight:500}
  </style>
</head>
<body>
<div class="er" role="main">
  <p class="er-code">@yield('code')</p>
  <div class="er-icon">
    @yield('icon')
  </div>
  <h1>@yield('title')</h1>
  @yield('body')
  <div class="er-actions">
    @yield('actions')
  </div>
</div>
</body>
</html>
