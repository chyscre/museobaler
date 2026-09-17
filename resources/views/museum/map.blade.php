@extends('layouts.admin')
@section('title','Museum Map — Museo Baler')

@push('styles')
<style>
.story-path{animation:dash 1s linear infinite}
@keyframes dash{to{stroke-dashoffset:-20}}
</style>
@endpush

@section('content')
<div class="ph">
  <div class="ph-left"><h2>Museum Map</h2><p>Floor plan and exhibit locations</p></div>
  <div class="ph-right">
    <div class="mode-toggle">
      <button class="mode-btn active" id="mode-storyline" onclick="setMode('storyline')">Storyline Mode</button>
      <button class="mode-btn" id="mode-free" onclick="setMode('free')">Free Explore</button>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start">
  <div>
    <div style="background:#fff;border-radius:var(--r);border:1px solid var(--border)">
      <div style="display:flex;gap:2px;padding:10px 14px 0;background:#f9fafb;border-bottom:1px solid var(--border)">
        <button class="floor-tab active" onclick="setFloor('ground',this)">1st Floor</button>
        <button class="floor-tab" onclick="setFloor('second',this)">2nd Floor</button>
      </div>
      <!-- Ground Floor SVG -->
      <svg id="map-svg" viewBox="0 0 438 470" width="100%" style="display:block;background:#E8D9B8;font-family:sans-serif">
        <defs>
          <marker id="arr" markerWidth="6" markerHeight="6" refX="3" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 Z" fill="#5C1A0A"/></marker>
        </defs>
        <polygon points="120,8 430,8 430,465 8,465 8,140 120,140 120,8" fill="#D9C9A3" stroke="#2C1810" stroke-width="3" stroke-linejoin="round"/>
        <line x1="120" y1="148" x2="430" y2="148" stroke="#2C1810" stroke-width="2"/>
        <rect x="168" y="14" width="108" height="90" fill="#8B1A1A" stroke="#2C1810" stroke-width="1.5"/>
        <text x="222" y="59" text-anchor="middle" dominant-baseline="middle" font-size="9" font-weight="700" fill="rgba(255,255,255,0.9)">STAIRS</text>
        <rect x="168" y="108" width="108" height="40" fill="#C8B89A" stroke="#2C1810" stroke-width="1.5"/>
        <text x="222" y="128" text-anchor="middle" font-size="7.5" font-weight="700" fill="#2C1810">COMFORT ROOM</text>
        <rect x="122" y="14" width="38" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="122" y="46" width="38" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="286" y="14" width="40" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="286" y="46" width="40" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="336" y="14" width="86" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="336" y="46" width="86" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="336" y="78" width="86" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="14" y="158" width="56" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="14" y="204" width="56" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="14" y="250" width="56" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="82" y="192" width="210" height="52" fill="#7B2D0A" stroke="#2C1810" stroke-width="2"/>
        <rect x="182" y="258" width="24" height="24" fill="#2B5F8E" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="306" y="158" width="56" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="306" y="204" width="56" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="306" y="250" width="56" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="372" y="158" width="50" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="372" y="204" width="50" height="36" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.5"/>
        <line x1="8" y1="306" x2="430" y2="306" stroke="#2C1810" stroke-width="2"/>
        <rect x="14" y="316" width="52" height="30" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="76" y="316" width="52" height="30" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="14" y="356" width="70" height="32" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.5"/>
        <text x="49" y="375" text-anchor="middle" font-size="7" font-weight="700" fill="white">INFO DESK</text>
        <rect x="170" y="450" width="80" height="12" fill="#2C1810"/>
        <text x="210" y="460" text-anchor="middle" font-size="7" font-weight="700" fill="white">ENTRANCE</text>
        <rect x="322" y="314" width="100" height="96" fill="#C8B89A" stroke="#2C1810" stroke-width="1.5"/>
        <text x="372" y="365" text-anchor="middle" font-size="7" font-weight="700" fill="#2C1810">For Staff Only</text>
        <!-- Storyline nodes -->
        <g id="story-nodes-1">
          <polyline points="187,218 40,331 102,331" fill="none" stroke="#D4A800" stroke-width="2.5" stroke-dasharray="6,4" class="story-path"/>
          <circle cx="187" cy="218" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="187" y="222" text-anchor="middle" font-size="10" font-weight="700" fill="white">1</text>
          <circle cx="40" cy="331" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="40" y="335" text-anchor="middle" font-size="10" font-weight="700" fill="white">2</text>
          <circle cx="102" cy="331" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="102" y="335" text-anchor="middle" font-size="10" font-weight="700" fill="white">3</text>
        </g>
        <g id="free-nodes-1" style="display:none">
          <circle cx="187" cy="218" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="187" y="222" text-anchor="middle" font-size="8" font-weight="700" fill="white">E1</text>
          <circle cx="40" cy="331" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="40" y="335" text-anchor="middle" font-size="8" font-weight="700" fill="white">E2</text>
          <circle cx="102" cy="331" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="102" y="335" text-anchor="middle" font-size="8" font-weight="700" fill="white">E3</text>
        </g>
      </svg>
      <!-- 2nd Floor SVG -->
      <svg id="map-svg-2nd" viewBox="0 0 500 420" width="100%" style="display:none;background:#E8D9B8;font-family:sans-serif">
        <rect x="6" y="6" width="488" height="408" fill="#D9C9A3" stroke="#2C1810" stroke-width="3"/>
        <line x1="6" y1="120" x2="494" y2="120" stroke="#2C1810" stroke-width="2"/>
        <rect x="10" y="10" width="178" height="106" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="196" y="10" width="118" height="26" fill="#8B1A1A" stroke="#2C1810" stroke-width="2"/>
        <rect x="196" y="10" width="28" height="106" fill="#8B1A1A" stroke="#2C1810" stroke-width="2"/>
        <rect x="286" y="10" width="28" height="106" fill="#8B1A1A" stroke="#2C1810" stroke-width="2"/>
        <text x="255" y="23" text-anchor="middle" dominant-baseline="middle" font-size="9" font-weight="700" fill="rgba(255,255,255,0.9)">STAIRS</text>
        <rect x="224" y="36" width="62" height="80" fill="#C8B89A" stroke="#2C1810" stroke-width="1.5"/>
        <text x="255" y="76" text-anchor="middle" font-size="7.5" font-weight="700" fill="#2C1810">COMFORT ROOM</text>
        <rect x="322" y="10" width="168" height="106" fill="#C8B89A" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="332" y="18" width="68" height="28" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="410" y="18" width="72" height="28" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="332" y="54" width="68" height="28" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="410" y="54" width="72" height="28" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="332" y="88" width="150" height="24" fill="#A8BEC5" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="10" y="124" width="480" height="158" fill="#8B1A1A" stroke="#2C1810" stroke-width="2.5"/>
        <text x="250" y="198" text-anchor="middle" font-size="15" font-weight="700" fill="rgba(255,255,255,0.95)">MAIN EXHIBITION HALL</text>
        <line x1="6" y1="290" x2="494" y2="290" stroke="#2C1810" stroke-width="2"/>
        <rect x="10" y="298" width="58" height="22" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="76" y="298" width="58" height="22" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="142" y="298" width="58" height="22" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="208" y="298" width="58" height="22" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="274" y="298" width="58" height="22" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="340" y="298" width="58" height="22" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="406" y="298" width="80" height="22" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="10" y="330" width="90" height="72" fill="#C8B89A" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="396" y="330" width="90" height="72" fill="#C8B89A" stroke="#2C1810" stroke-width="1.5"/>
        <rect x="112" y="330" width="70" height="30" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="194" y="330" width="110" height="30" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <rect x="316" y="330" width="70" height="30" fill="#7B2D0A" stroke="#2C1810" stroke-width="1.2"/>
        <g id="story-nodes-2">
          <polyline points="99,63 40,310 107,310 249,345 351,345" fill="none" stroke="#D4A800" stroke-width="2.5" stroke-dasharray="6,4" class="story-path"/>
          <circle cx="99" cy="63" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="99" y="67" text-anchor="middle" font-size="10" font-weight="700" fill="white">4</text>
          <circle cx="40" cy="310" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="40" y="314" text-anchor="middle" font-size="10" font-weight="700" fill="white">5</text>
          <circle cx="107" cy="310" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="107" y="314" text-anchor="middle" font-size="10" font-weight="700" fill="white">6</text>
          <circle cx="249" cy="345" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="249" y="349" text-anchor="middle" font-size="10" font-weight="700" fill="white">7</text>
          <circle cx="351" cy="345" r="13" fill="#D4A800" stroke="white" stroke-width="2.5"/>
          <text x="351" y="349" text-anchor="middle" font-size="10" font-weight="700" fill="white">8</text>
        </g>
        <g id="free-nodes-2" style="display:none">
          <circle cx="99" cy="63" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="99" y="67" text-anchor="middle" font-size="8" font-weight="700" fill="white">E4</text>
          <circle cx="40" cy="310" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="40" y="314" text-anchor="middle" font-size="8" font-weight="700" fill="white">E5</text>
          <circle cx="107" cy="310" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="107" y="314" text-anchor="middle" font-size="8" font-weight="700" fill="white">E6</text>
          <circle cx="249" cy="345" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="249" y="349" text-anchor="middle" font-size="8" font-weight="700" fill="white">E7</text>
          <circle cx="351" cy="345" r="12" fill="#4A7C2F" stroke="white" stroke-width="2"/>
          <text x="351" y="349" text-anchor="middle" font-size="8" font-weight="700" fill="white">E8</text>
        </g>
      </svg>
    </div>
  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="card card-p" id="mode-card-storyline">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:10px">Storyline Path</div>
      @forelse($storyline as $ex)
      <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f9f5f0;border-radius:8px;margin-bottom:5px">
        <div style="width:24px;height:24px;border-radius:50%;background:#fbbf24;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">{{ $ex->storyline_order }}</div>
        <div>
          <div style="font-size:12.5px;font-weight:600;color:#3b2a1a">{{ $ex->name }}</div>
          <div style="font-size:11px;color:#888">{{ $ex->hall }}</div>
        </div>
      </div>
      @empty
      <p style="font-size:12px;color:#888">No storyline set. Add exhibits with a storyline order in the Exhibits tab.</p>
      @endforelse
    </div>

    <div class="card card-p" id="mode-card-free" style="display:none">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:10px">All Active Exhibits</div>
      @foreach($allExhibits as $ex)
      <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f9f5f0;border-radius:8px;margin-bottom:5px">
        <div style="width:8px;height:8px;border-radius:50%;background:#3b82f6;flex-shrink:0"></div>
        <div>
          <div style="font-size:12.5px;font-weight:600;color:#3b2a1a">{{ $ex->name }}</div>
          <div style="font-size:11px;color:#888">{{ $ex->hall }}</div>
        </div>
      </div>
      @endforeach
    </div>

    <div class="card card-p">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px">Map Summary</div>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px">
        <span style="color:#374151">Active Exhibits</span><strong style="color:#16a34a">{{ $activeCount }}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px">
        <span style="color:#374151">Archived</span><strong style="color:#9ca3af">{{ $archivedCount }}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px">
        <span style="color:#374151">Storyline Steps</span><strong style="color:#d97706">{{ $storyline->count() }}</strong>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
function setMode(mode){
  document.getElementById('mode-storyline').classList.toggle('active', mode==='storyline');
  document.getElementById('mode-free').classList.toggle('active', mode==='free');
  document.getElementById('mode-card-storyline').style.display = mode==='storyline' ? 'block' : 'none';
  document.getElementById('mode-card-free').style.display = mode==='free' ? 'block' : 'none';
  document.getElementById('story-nodes-1').style.display = mode==='storyline' ? 'block' : 'none';
  document.getElementById('free-nodes-1').style.display  = mode==='free'      ? 'block' : 'none';
  document.getElementById('story-nodes-2').style.display = mode==='storyline' ? 'block' : 'none';
  document.getElementById('free-nodes-2').style.display  = mode==='free'      ? 'block' : 'none';
}
function setFloor(floor, btn){
  document.querySelectorAll('.floor-tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('map-svg').style.display     = floor==='ground' ? 'block' : 'none';
  document.getElementById('map-svg-2nd').style.display = floor==='second' ? 'block' : 'none';
}
</script>
@endpush
