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
    /* The letterhead. The uploaded banner, or failing that the logo and the
       museum name, come from Museum Info → Report Branding; the layout only
       decides where they sit. */
    .head { border-bottom: 2px solid #1c1917; padding-bottom: 16px; margin-bottom: 22px; display: flex; gap: 18px; align-items: center; }
    .head .logo { width: 64px; height: 64px; flex-shrink: 0; object-fit: contain; }
    /* The uploaded letterhead spans the sheet, so the head stops being a
       flex row and the title drops underneath it - which is where a banner
       that already carries the office name expects it. */
    .head.banner-head { display: block; border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
    .head .banner { width: 100%; height: auto; display: block; }
    .head-alt { border-bottom: 2px solid #1c1917; padding-bottom: 14px; margin-bottom: 22px; }
    .head-alt h1 { font-family: 'Young Serif', serif; font-size: 22px; font-weight: 400; }
    .head-alt .meta { font-size: 12px; color: #57534e; margin-top: 4px; }
    .head .txt { flex: 1; min-width: 0; }
    .head .name { font-size: 11px; font-weight: 700; color: #57534e; text-transform: uppercase; letter-spacing: .08em; }
    .head h1 { font-family: 'Young Serif', serif; font-size: 22px; font-weight: 400; margin-top: 2px; }
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
    .bar { max-width: 900px; margin: 0 auto 16px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .bar button, .bar a {
      padding: 9px 16px; font-size: 13px; font-weight: 600; font-family: inherit;
      border: 1.5px solid #d6d3d1; border-radius: 8px; background: #fff;
      color: #1c1917; cursor: pointer; text-decoration: none;
    }
    .bar .spacer { flex: 1; }

    /* Save is the one that produces the file, so it is the filled one.
       Print is the other thing you might do with the page, and it is green
       to tell the two apart at a glance. */
    .bar a.go { background: #1c1917; border-color: #1c1917; color: #fff; font-weight: 700; }
    .bar a.go:hover { background: #292524; border-color: #292524; }
    .bar button.print { background: #16a34a; border-color: #16a34a; color: #fff; }
    .bar button.print:hover { background: #15803d; border-color: #15803d; }

    .bar .fmt { display: flex; align-items: center; gap: 8px; }
    .bar .fmt span { font-size: 11px; font-weight: 700; color: #78716c;
                     text-transform: uppercase; letter-spacing: .06em; }
    .bar .fmt select {
      padding: 9px 30px 9px 13px; font-size: 13px; font-weight: 600; font-family: inherit;
      border: 1.5px solid #d6d3d1; border-radius: 8px; background: #fff;
      color: #1c1917; cursor: pointer; appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2378716c' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 9px center; background-size: 13px;
    }
    .bar .fmt select:hover { border-color: #a8a29e; }
    .bar .fmt select:focus { outline: 2px solid #16a34a; outline-offset: 1px; }

    /* -- Preview -------------------------------------------------------- */
    .prev { max-width: 900px; margin: 0 auto 16px; background: #fff;
            border: 1.5px solid #e7e5e4; border-radius: 10px; overflow: hidden; }
    .prev .hd { padding: 11px 16px; border-bottom: 1.5px solid #e7e5e4; }
    .prev .hd b { font-size: 12px; }
    .prev .body { padding: 16px; max-height: 460px; overflow: auto; background: #fafaf9; }
    .prev .body.pdf { padding: 0; max-height: none; }
    .prev iframe { width: 100%; height: 560px; border: 0; display: block; background: #fff; }
    .prev .loading { font-size: 12px; color: #78716c; padding: 26px; text-align: center; }
    .prev pre { font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace;
                font-size: 11px; line-height: 1.7; white-space: pre; margin: 0; }
    .prev table { width: 100%; border-collapse: collapse; background: #fff; }
    .prev th { background: #1c1917; color: #fff; font-size: 10px; text-transform: uppercase;
               letter-spacing: .05em; padding: 6px 8px; text-align: left; position: sticky; top: 0; }
    .prev td { padding: 5px 8px; font-size: 11.5px; border-bottom: 1px solid #f5f5f4; }
    .prev .sheet-note { font-size: 11.5px; color: #78716c; margin-bottom: 10px; }
    .prev .more { font-size: 11.5px; color: #78716c; padding: 9px 2px 0; }

    @media print {
      body { background: #fff; padding: 0; font-size: 11px; }
      .sheet { box-shadow: none; padding: 0; max-width: none; }
      .bar, .prev { display: none; }
      tr { page-break-inside: avoid; }
    }
  </style>
</head>
<body>
  {{-- $pdf is set by PdfExporter. mPDF draws the letterhead and the footer
       itself, as page furniture, so they repeat past page one; drawing them
       here as well would print a second copy at the top of page one. --}}
  @unless($pdf ?? false)
    <div class="bar">
      <a href="{{ url()->previous() }}">Back</a>
      @yield('actions')
      <span class="spacer"></span>

      {{-- Save, Print and the format picker come from the downloads partial
           so they sit in the order the museum asked for. The poster has no
           formats to offer, so it keeps a plain Print of its own. --}}
      @hasSection('downloads')
        @yield('downloads')
      @else
        <button type="button" class="print" onclick="window.print()">Print</button>
      @endif
    </div>

    @hasSection('downloads')
      <div class="prev">
        <div class="hd"><b>Preview — <span id="prevWhat"></span></b></div>
        <div class="body" id="prevBody"></div>
      </div>
    @endif
  @endunless

  @php $brand = \App\Models\MuseumInfo::branding(); @endphp
  <div class="sheet">
    @unless($pdf ?? false)
      <div class="head{{ $brand['header'] ? ' banner-head' : '' }}">
        @if($brand['header'])
          <img class="banner" src="{{ $brand['header'] }}" alt="">
        @else
          @if($brand['logo'])
            <img class="logo" src="{{ $brand['logo'] }}" alt="">
          @endif
          <div class="txt">
            <div class="name">{{ $brand['name'] }}</div>
            <h1>@yield('report-title')</h1>
            <div class="meta">@yield('report-meta')</div>
          </div>
        @endif
      </div>

      @if($brand['header'])
        <div class="head-alt">
          <h1>@yield('report-title')</h1>
          <div class="meta">@yield('report-meta')</div>
        </div>
      @endif
    @endunless

    @yield('content')
  </div>
</body>
</html>
