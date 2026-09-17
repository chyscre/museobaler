<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Open this on a computer — Museo de Baler</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  @vite(['resources/css/app.css'])
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
    .dk{background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.18);width:100%;max-width:400px;padding:36px 30px;text-align:center}
    .dk-icon{width:52px;height:52px;border-radius:14px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
    .dk-icon svg{width:26px;height:26px;color:#64748b}
    .dk h1{font-size:21px;color:#1a1a1a;margin:0 0 10px;line-height:1.3}
    .dk p{font-size:13.5px;color:#6b7280;line-height:1.65;margin:0 0 14px}
    .dk form{margin-top:22px;padding-top:20px;border-top:1px solid #f3f4f6}
    .dk button{background:none;border:none;color:#6b7280;font-family:'Instrument Sans',sans-serif;font-size:12.5px;cursor:pointer}
    .dk button:hover{color:#16a34a}
  </style>
</head>
<body>
<div class="dk">
  <div class="dk-icon">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
  </div>
  <h1>Open this on a computer</h1>
  <p>
    The admin panel is built for a desktop screen — the records, the reports
    and the exhibit editor do not fit on a phone.
  </p>
  <p>
    Sign in again from the office computer and everything will be where you
    expect it.
  </p>
  <form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">Sign out</button>
  </form>
</div>
</body>
</html>
