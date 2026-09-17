<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'Report — Museo de Baler')</title>
  <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* Printed straight from the browser rather than generated as a PDF:
       one less dependency to install on the museum's machine, and the
       museum prints these on paper anyway. */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Instrument Sans', system-ui, sans-serif;
      background: #f6f5f1; color: #1c1917; font-size: 13px; padding: 28px;
    }
    .sheet {
      max-width: 900px; margin: 0 auto; background: #fff;
      padding: 40px; border-radius: 8px; box-shadow: 0 2px 16px rgba(0,0,0,.06);
    }
    .head { border-bottom: 2px solid #1c1917; padding-bottom: 16px; margin-bottom: 22px; }
    .head h1 { font-family: 'Young Serif', serif; font-size: 22px; font-weight: 400; }
    .head .org { font-size: 12px; color: #78716c; margin-top: 3px; }
    .head .meta { font-size: 12px; color: #57534e; margin-top: 10px; }
    h2 { font-size: 14px; font-weight: 700; margin: 24px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    th {
      text-align: left; font-size: 10px; font-weight: 700; color: #78716c;
      text-transform: uppercase; letter-spacing: .06em;
      padding: 7px 8px; border-bottom: 1.5px solid #1c1917;
    }
    td { padding: 7px 8px; border-bottom: 1px solid #e7e5e4; font-size: 12px; }
    tfoot td { font-weight: 700; border-top: 1.5px solid #1c1917; border-bottom: none; }
    .tot { display: flex; gap: 28px; flex-wrap: wrap; margin: 18px 0; }
    .tot div span { display: block; }
    .tot .k { font-size: 10px; color: #78716c; text-transform: uppercase; letter-spacing: .06em; }
    .tot .v { font-size: 20px; font-weight: 700; margin-top: 2px; }
    .tag { display: inline-block; padding: 1px 7px; border-radius: 99px; font-size: 10px; font-weight: 600; }
    .t-green { background: #f0fdf4; color: #166534; }
    .t-gold  { background: #fffbeb; color: #92400e; }
    .t-red   { background: #fef2f2; color: #991b1b; }
    .t-gray  { background: #f5f5f4; color: #57534e; }
    .sign { margin-top: 46px; display: flex; gap: 60px; }
    .sign div { flex: 1; }
    .sign .line { border-top: 1px solid #1c1917; margin-bottom: 5px; }
    .sign .role { font-size: 11px; color: #78716c; }
    .note { font-size: 11px; color: #78716c; margin-top: 14px; line-height: 1.6; }
    .bar { max-width: 900px; margin: 0 auto 16px; display: flex; gap: 8px; }
    .bar button, .bar a {
      padding: 9px 16px; font-size: 13px; font-weight: 600; font-family: inherit;
      border: 1.5px solid #d6d3d1; border-radius: 8px; background: #fff;
      color: #1c1917; cursor: pointer; text-decoration: none;
    }
    @media print {
      body { background: #fff; padding: 0; font-size: 11px; }
      .sheet { box-shadow: none; padding: 0; max-width: none; }
      .bar { display: none; }
      tr { page-break-inside: avoid; }
    }
  </style>
</head>
<body>
  <div class="bar">
    <button onclick="window.print()">Print</button>
    <a href="{{ url()->previous() }}">Back</a>
    @yield('actions')
  </div>

  <div class="sheet">
    <div class="head">
      <h1>@yield('report-title')</h1>
      <div class="org">Museo de Baler · Municipal Tourism Office</div>
      <div class="meta">@yield('report-meta')</div>
    </div>

    @yield('content')
  </div>
</body>
</html>
