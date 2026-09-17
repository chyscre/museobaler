@extends('layouts.admin')
@section('title', 'QR Codes — Museo de Baler')

@push('styles')
<style>
@media print {
  .sidebar, .ph, .qr-toolbar, .sidebar-footer { display: none !important; }
  .main-content { margin: 0 !important; padding: 0 !important; }
  .qr-grid { grid-template-columns: repeat(3, 1fr) !important; gap: 12px !important; }
  .qr-card { break-inside: avoid; border: 1px solid #e5e7eb !important; box-shadow: none !important; }
  body, .layout { background: white !important; }
}
.qr-card {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: 14px;
  padding: 20px 16px 16px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}
.qr-name { font-family: 'Young Serif', serif; font-size: 13px; color: var(--text); line-height: 1.3; }
.qr-code { font-size: 11px; font-weight: 700; color: var(--green-dark); }
.qr-loc  { font-size: 11px; color: var(--text-3); }
.qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
</style>
@endpush

@section('content')
<div class="ph">
  <div class="ph-left">
    <h2>QR Codes</h2>
    <p>{{ $exhibits->count() }} active exhibits</p>
  </div>
  <div class="ph-right">
    <button class="btn btn-outline btn-sm" onclick="window.print()">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print All QR Codes
    </button>
    <a href="{{ route('exhibits.qr.download') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download All (ZIP)
    </a>
    <a href="{{ route('exhibits.index') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Back to Exhibits
    </a>
  </div>
</div>

<div class="card card-p-lg">
  <div class="qr-grid" id="qrGrid"></div>
</div>

{{-- Pass exhibit data to JS safely --}}
<script id="exhibit-data" type="application/json">{!! json_encode($exhibits->map(function($e) {
    return [
        'id'    => $e->exhibit_id,
        'code'  => $e->exhibit_code,
        'name'  => $e->name,
        'floor' => $e->floor ?? '',
        'hall'  => $e->hall  ?? '',
    ];
})->values()) !!}</script>
@endsection

@push('scripts')
<script src="{{ asset('js/qrcode.min.js') }}"></script>
<script>
(function() {
  var exhibits = JSON.parse(document.getElementById('exhibit-data').textContent);
  var grid = document.getElementById('qrGrid');
  // Absolute visitor deep-link base, resolved server-side so it stays correct on
  // any host or base path. index.php is named explicitly because Apache's
  // DirectoryIndex prefers index.html, which would skip the ?scan= handoff.
  var VISITOR_SCAN_URL = '{{ url("/visitor/index.php") }}';

  if (!exhibits || exhibits.length === 0) {
    grid.innerHTML = '<p style="color:var(--text-3);padding:20px">No active exhibits found.</p>';
    return;
  }

  exhibits.forEach(function(ex) {
    var scanUrl = VISITOR_SCAN_URL + '?scan=' + encodeURIComponent(ex.code);

    var card = document.createElement('div');
    card.className = 'qr-card';

    var canvas = document.createElement('canvas');
    canvas.id = 'qr-' + ex.id;
    card.appendChild(canvas);

    var nameEl = document.createElement('div');
    nameEl.className = 'qr-name';
    nameEl.textContent = ex.name;
    card.appendChild(nameEl);

    var codeEl = document.createElement('div');
    codeEl.className = 'qr-code';
    codeEl.textContent = ex.code;
    card.appendChild(codeEl);

    var locEl = document.createElement('div');
    locEl.className = 'qr-loc';
    locEl.textContent = [ex.floor, ex.hall].filter(Boolean).join(' · ');
    card.appendChild(locEl);

    grid.appendChild(card);

    QRCode.toCanvas(canvas, scanUrl, {
      width: 160,
      margin: 2,
      color: { dark: '#1a1a2e', light: '#ffffff' },
      errorCorrectionLevel: 'H'
    }, function(err) {
      if (err) canvas.parentElement.style.opacity = '0.4';
    });
  });
})();
</script>
@endpush
