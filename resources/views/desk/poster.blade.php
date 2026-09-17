@extends('layouts.print')
@section('title', 'Entrance Poster — Museo de Baler')
@section('report-title', 'Entrance Poster')
@section('report-meta', 'Print on A4, put it where visitors queue')

@section('content')
<div style="text-align:center;padding:20px 0 10px">
  <p style="font-size:13px;color:#78716c;margin-bottom:26px">
    Printed at actual size this fills most of an A4 sheet. Mount it at the entrance,
    beside the queue rather than on the desk itself, so visitors can register while
    they wait instead of waiting to be typed in.
  </p>
</div>

{{-- Everything below is the poster itself. --}}
<div class="poster">
  <div class="poster-kicker">{{ $museum->name ?? 'Museo de Baler' }}</div>
  <h1 class="poster-title">Welcome</h1>
  <p class="poster-lede">Scan to sign in before you go inside</p>

  <img src="{{ $qr }}" alt="Registration QR code" class="poster-qr">

  <ol class="poster-steps">
    <li>Open your phone camera and point it at the code</li>
    <li>Tap the link, then fill in your name and where you are from</li>
    <li>Show your screen at the desk</li>
  </ol>

  <p class="poster-fallback">
    No phone? No problem — the staff at the desk will sign you in.
  </p>

  <div class="poster-fee">
    {{ \App\Models\MuseumInfo::admissionSentence(\App\Models\MuseumInfo::admissionFee()) }}
  </div>
</div>

<p class="note" style="text-align:center">
  Code points to <code>{{ $registerUrl }}</code> — generated from the address you are
  using right now. If the museum's address changes, reprint this page rather than
  editing it, so the code always matches.
</p>

@push('styles')
<style>
  .poster {
    border: 3px solid #1c1917; border-radius: 18px;
    padding: 44px 36px; text-align: center; max-width: 620px; margin: 0 auto;
  }
  .poster-kicker {
    font-size: 13px; font-weight: 700; letter-spacing: .18em;
    text-transform: uppercase; color: #78716c; margin-bottom: 10px;
  }
  .poster-title {
    font-family: 'Young Serif', serif; font-size: 56px; font-weight: 400;
    line-height: 1; margin-bottom: 10px;
  }
  .poster-lede { font-size: 19px; color: #44403c; margin-bottom: 28px; }
  .poster-qr {
    width: 300px; height: 300px; display: block; margin: 0 auto 26px;
    border: 1px solid #e7e5e4;
  }
  .poster-steps {
    text-align: left; display: inline-block; font-size: 16px; line-height: 1.9;
    color: #292524; margin-bottom: 22px; padding-left: 22px;
  }
  .poster-fallback { font-size: 15px; color: #57534e; margin-bottom: 22px; }
  .poster-fee {
    font-size: 15px; font-weight: 600; color: #166534;
    background: #f0fdf4; border-radius: 10px; padding: 12px 16px;
  }
  @media print {
    /* The poster is the only thing that should reach the paper. */
    .head, .note, .poster + p, body > .sheet > div:first-child { display: none !important; }
    .poster { border-width: 4px; padding: 60px 40px; max-width: none; }
    .poster-title { font-size: 72px; }
    .poster-qr { width: 360px; height: 360px; }
  }
</style>
@endpush
@endsection
