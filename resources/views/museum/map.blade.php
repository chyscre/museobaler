@extends('layouts.admin')
@section('title','Museum Map — Museo Baler')

@push('styles')
<style>
.story-path{animation:dash 1s linear infinite}
@keyframes dash{to{stroke-dashoffset:-40}}
.map-svg{display:block;background:#E8D9B8;font-family:sans-serif;user-select:none;-webkit-user-select:none;touch-action:none}
.pin{cursor:default}
.map-editing .pin{cursor:grab}
.map-editing .pin.dragging{cursor:grabbing}
.pin.highlight circle{stroke:#111;stroke-width:5}
.pin-label{pointer-events:none;font-weight:700}
.map-legend{display:flex;flex-wrap:wrap;gap:14px 22px;padding:10px 14px;border-top:1px solid var(--border);font-size:12px;color:#4b5563}
.map-legend span{display:inline-flex;align-items:center;gap:6px}
.map-legend i{display:inline-block;width:14px;height:14px;border:1px solid #2C1810;border-radius:2px}
.ex-row{display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f9f5f0;border-radius:8px;margin-bottom:5px;cursor:pointer}
.ex-row:hover{background:#f1e9df}
.ex-row .badge{margin-left:auto;font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px;white-space:nowrap}
.badge-placed{background:#dcfce7;color:#166534}
.badge-unplaced{background:#fef3c7;color:#92400e}
.badge-floor{background:#e5e7eb;color:#374151}
#map-toolbar{display:flex;align-items:center;gap:8px;margin-left:auto;padding-bottom:8px}
#map-status{font-size:12px;color:#6b7280}
.btn-sm{padding:6px 12px;font-size:12px;border-radius:6px;border:1.5px solid var(--border);background:#fff;font-weight:600;cursor:pointer}
.btn-sm.primary{background:var(--green);border-color:var(--green);color:#fff}
.btn-sm:disabled{opacity:.5;cursor:not-allowed}
</style>
@endpush

@section('content')
<div class="ph">
  <div class="ph-left"><h2>Museum Map</h2></div>
  <div class="ph-right">
    <a href="{{ route('museum.index') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Museum Info
    </a>
    <div class="mode-toggle">
      <button class="mode-btn active" id="mode-storyline" onclick="setMode('storyline')">Storyline Mode</button>
      <button class="mode-btn" id="mode-free" onclick="setMode('free')">Free Explore</button>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start">
  <div>
    <div style="background:#fff;border-radius:var(--r);border:1px solid var(--border)">
      <div style="display:flex;align-items:center;gap:2px;padding:10px 14px 0;background:#f9fafb;border-bottom:1px solid var(--border)">
        <button class="floor-tab active" onclick="setFloor('ground',this)">1st Floor</button>
        <button class="floor-tab" onclick="setFloor('second',this)">2nd Floor</button>
        <div id="map-toolbar">
          <span id="map-status"></span>
          <button class="btn-sm" id="btn-edit" onclick="startEditing()">Edit layout</button>
          <button class="btn-sm" id="btn-cancel" onclick="cancelEditing()" style="display:none">Cancel</button>
          <button class="btn-sm primary" id="btn-save" onclick="saveLayout()" style="display:none" disabled>Save layout</button>
        </div>
      </div>

      <!-- ── 1st Floor ─────────────────────────────────────────────────── -->
      <svg id="map-svg" class="map-svg" data-floor="ground" viewBox="0 0 1660 1000" width="100%">
        <!-- Outer walls -->
        <polygon points="235,35 570,35 570,10 1020,10 1020,55 1640,55 1640,945 1030,945 1030,980 640,980 640,945 10,945 10,125 235,125" fill="#D9C9A3" stroke="#2C1810" stroke-width="8" stroke-linejoin="round"/>
        <!-- Stairs block with comfort room -->
        <rect x="570" y="10" width="450" height="370" fill="#6E3A1E" stroke="#2C1810" stroke-width="5"/>
        <text x="795" y="110" text-anchor="middle" font-size="30" font-weight="700" fill="#fff" letter-spacing="3">STAIRS</text>
        <g stroke="#3A1E0E" stroke-width="3">
          <line x1="590" y1="200" x2="700" y2="200"/><line x1="590" y1="220" x2="700" y2="220"/><line x1="590" y1="240" x2="700" y2="240"/><line x1="590" y1="260" x2="700" y2="260"/><line x1="590" y1="280" x2="700" y2="280"/><line x1="590" y1="300" x2="700" y2="300"/><line x1="590" y1="320" x2="700" y2="320"/><line x1="590" y1="340" x2="700" y2="340"/>
          <line x1="890" y1="200" x2="1000" y2="200"/><line x1="890" y1="220" x2="1000" y2="220"/><line x1="890" y1="240" x2="1000" y2="240"/><line x1="890" y1="260" x2="1000" y2="260"/><line x1="890" y1="280" x2="1000" y2="280"/><line x1="890" y1="300" x2="1000" y2="300"/><line x1="890" y1="320" x2="1000" y2="320"/><line x1="890" y1="340" x2="1000" y2="340"/>
        </g>
        <rect x="710" y="190" width="170" height="150" fill="#EFE6D0" stroke="#2C1810" stroke-width="3"/>
        <text x="795" y="258" text-anchor="middle" font-size="19" font-weight="700" fill="#2C1810">COMFORT</text>
        <text x="795" y="284" text-anchor="middle" font-size="19" font-weight="700" fill="#2C1810">ROOM</text>
        <!-- Wall between the stairs and the right wing -->
        <line x1="1080" y1="55" x2="1080" y2="380" stroke="#2C1810" stroke-width="7"/>
        <line x1="1020" y1="380" x2="1080" y2="380" stroke="#2C1810" stroke-width="7"/>
        <!-- Entrance vestibule, info desk, exit -->
        <line x1="620" y1="775" x2="620" y2="945" stroke="#2C1810" stroke-width="7"/>
        <rect x="640" y="900" width="390" height="45" fill="#F3EBD9" stroke="none"/>
        <rect x="650" y="855" width="70" height="45" fill="#7B2D0A" stroke="#2C1810" stroke-width="3"/>
        <text transform="translate(740,900) rotate(-90)" text-anchor="start" font-size="18" font-weight="700" fill="#2C1810">INFO DESK</text>
        <text transform="translate(805,900) rotate(-90)" text-anchor="start" font-size="22" font-weight="700" fill="#2C1810" letter-spacing="2">ENTRANCE</text>
        <rect x="760" y="935" width="110" height="45" fill="#fff" stroke="#2C1810" stroke-width="3"/>
        <path d="M760,935 a55,55 0 0 1 55,-55" fill="none" stroke="#2C1810" stroke-width="2"/>
        <path d="M870,935 a55,55 0 0 0 -55,-55" fill="none" stroke="#2C1810" stroke-width="2"/>
        <line x1="1010" y1="900" x2="1010" y2="980" stroke="#2C1810" stroke-width="7"/>
        <rect x="945" y="935" width="60" height="45" fill="#fff" stroke="#2C1810" stroke-width="3"/>
        <text x="975" y="855" text-anchor="middle" font-size="20" font-weight="700" fill="#2C1810">EXIT</text>
        <!-- Staff room and side corridor -->
        <rect x="1520" y="380" width="60" height="290" fill="#F3EBD9" stroke="#2C1810" stroke-width="3"/>
        <rect x="1310" y="670" width="270" height="275" fill="#EFE6D0" stroke="#2C1810" stroke-width="4"/>
        <line x1="1310" y1="670" x2="1580" y2="945" stroke="#C0392B" stroke-width="2"/>
        <line x1="1580" y1="670" x2="1310" y2="945" stroke="#C0392B" stroke-width="2"/>
        <text x="1445" y="815" text-anchor="middle" font-size="22" font-weight="700" fill="#2C1810">For Staff Only</text>
        <!-- Left wing: glass cases -->
        <g fill="#A8BEC5" stroke="#2C1810" stroke-width="3">
          <rect x="262" y="47" width="55" height="55" transform="rotate(45 290 75)"/>
          <rect x="487" y="47" width="55" height="55" transform="rotate(45 515 75)"/>
          <rect x="105" y="130" width="60" height="60"/><rect x="170" y="130" width="60" height="60"/><rect x="235" y="130" width="60" height="60"/>
          <rect x="110" y="250" width="90" height="70"/>
          <rect x="310" y="380" width="60" height="60"/>
          <rect x="280" y="520" width="60" height="160"/>
          <rect x="165" y="640" width="75" height="70"/>
          <rect x="520" y="680" width="60" height="60"/>
          <rect x="380" y="790" width="150" height="80"/>
        </g>
        <!-- Left wing: wooden stands, statues -->
        <g fill="#7B2D0A" stroke="#2C1810" stroke-width="3">
          <rect x="40" y="400" width="60" height="290"/>
          <rect x="530" y="130" width="40" height="240"/>
          <rect x="700" y="380" width="210" height="70"/>
          <rect x="320" y="655" width="260" height="35"/>
          <rect x="420" y="905" width="220" height="30"/>
          <rect x="710" y="635" width="210" height="35"/>
          <rect x="1020" y="640" width="90" height="35"/>
          <rect x="860" y="770" width="110" height="30"/>
        </g>
        <rect x="270" y="390" width="40" height="40" fill="#8B1A1A" stroke="#2C1810" stroke-width="3"/>
        <rect x="580" y="655" width="50" height="35" fill="#2C1810"/>
        <rect x="990" y="645" width="30" height="30" fill="#2C1810"/>
        <rect x="780" y="450" width="50" height="50" fill="#2B5F8E" stroke="#2C1810" stroke-width="3"/>
        <rect x="1030" y="380" width="50" height="45" fill="#2B5F8E" stroke="#2C1810" stroke-width="3"/>
        <!-- Right wing: glass cases -->
        <g fill="#A8BEC5" stroke="#2C1810" stroke-width="3">
          <rect x="1180" y="50" width="140" height="75"/>
          <rect x="1280" y="130" width="40" height="130"/>
          <rect x="1150" y="230" width="60" height="60"/>
          <rect x="1440" y="150" width="60" height="50"/>
          <rect x="1150" y="480" width="90" height="80"/>
          <rect x="1300" y="540" width="70" height="100"/>
          <rect x="1100" y="720" width="100" height="140"/>
        </g>
        <!-- Right wing: wooden stands -->
        <g fill="#7B2D0A" stroke="#2C1810" stroke-width="3">
          <rect x="1340" y="50" width="40" height="200"/>
          <rect x="1480" y="160" width="60" height="100"/>
          <rect x="1200" y="360" width="100" height="60"/>
          <rect x="1350" y="360" width="60" height="60"/>
          <rect x="1060" y="912" width="160" height="30"/>
        </g>
        <rect x="1310" y="360" width="40" height="60" fill="#2C1810"/>
        <rect x="1570" y="350" width="40" height="40" fill="#8B1A1A" stroke="#2C1810" stroke-width="3"/>
        <!-- You are here (the framed plan by the entrance) -->
        <rect x="800" y="605" width="40" height="40" fill="#E03A2F" stroke="#2C1810" stroke-width="2"/>
        <text x="820" y="592" text-anchor="middle" font-size="17" font-weight="700" fill="#C0392B">YOU ARE HERE</text>
        <!-- Layers the script fills in -->
        <polyline class="story-path" data-path="ground" points="" fill="none" stroke="#D4A800" stroke-width="7" stroke-dasharray="18,14" stroke-linejoin="round"/>
        <g data-pins="ground"></g>
      </svg>

      <!-- ── 2nd Floor ─────────────────────────────────────────────────── -->
      <svg id="map-svg-2nd" class="map-svg" data-floor="second" viewBox="0 0 1770 1010" width="100%" style="display:none">
        <defs>
          <marker id="arr" markerWidth="8" markerHeight="8" refX="4" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 Z" fill="#C0392B"/></marker>
          <marker id="arr-rev" markerWidth="8" markerHeight="8" refX="4" refY="4" orient="auto-start-reverse"><path d="M0,0 L8,4 L0,8 Z" fill="#C0392B"/></marker>
        </defs>
        <!-- Outer walls -->
        <polygon points="10,20 640,20 640,65 1095,65 1095,20 1755,20 1755,990 975,990 975,965 755,965 755,990 10,990" fill="#D9C9A3" stroke="#2C1810" stroke-width="8" stroke-linejoin="round"/>
        <!-- Staircase block -->
        <rect x="640" y="65" width="455" height="370" fill="#6E3A1E" stroke="#2C1810" stroke-width="5"/>
        <rect x="640" y="65" width="455" height="170" fill="#5A2A14"/>
        <g stroke="#3A1E0E" stroke-width="3">
          <line x1="650" y1="250" x2="770" y2="250"/><line x1="650" y1="268" x2="770" y2="268"/><line x1="650" y1="286" x2="770" y2="286"/><line x1="650" y1="304" x2="770" y2="304"/><line x1="650" y1="322" x2="770" y2="322"/><line x1="650" y1="340" x2="770" y2="340"/><line x1="650" y1="358" x2="770" y2="358"/>
          <line x1="810" y1="250" x2="925" y2="250"/><line x1="810" y1="268" x2="925" y2="268"/><line x1="810" y1="286" x2="925" y2="286"/><line x1="810" y1="304" x2="925" y2="304"/><line x1="810" y1="322" x2="925" y2="322"/><line x1="810" y1="340" x2="925" y2="340"/><line x1="810" y1="358" x2="925" y2="358"/>
          <line x1="965" y1="250" x2="1085" y2="250"/><line x1="965" y1="268" x2="1085" y2="268"/><line x1="965" y1="286" x2="1085" y2="286"/><line x1="965" y1="304" x2="1085" y2="304"/><line x1="965" y1="322" x2="1085" y2="322"/><line x1="965" y1="340" x2="1085" y2="340"/><line x1="965" y1="358" x2="1085" y2="358"/>
        </g>
        <rect x="770" y="235" width="40" height="145" fill="#6E3A1E"/>
        <rect x="925" y="235" width="40" height="145" fill="#6E3A1E"/>
        <text x="867" y="160" text-anchor="middle" font-size="28" font-weight="700" fill="#fff" letter-spacing="3">STAIRS</text>
        <rect x="640" y="380" width="455" height="55" fill="#8B5A2B" stroke="#2C1810" stroke-width="3"/>
        <!-- Open atrium looking down on the 1st floor -->
        <path d="M335,455 L640,455 Q867,540 1095,455 L1415,455 L1415,730 L335,730 Z" fill="#C1533A" stroke="#2C1810" stroke-width="7" stroke-linejoin="round"/>
        <g fill="#2C1810">
          <rect x="318" y="440" width="35" height="35"/><rect x="1398" y="440" width="35" height="35"/>
          <rect x="318" y="715" width="35" height="35"/><rect x="1398" y="715" width="35" height="35"/>
          <rect x="648" y="715" width="35" height="35"/><rect x="1078" y="715" width="35" height="35"/>
          <rect x="623" y="440" width="35" height="35"/><rect x="1078" y="440" width="35" height="35"/>
        </g>
        <!-- Walkway direction across the gallery -->
        <line x1="395" y1="410" x2="1355" y2="410" stroke="#C0392B" stroke-width="3" marker-start="url(#arr-rev)" marker-end="url(#arr)"/>
        <!-- Wooden stands and artifacts -->
        <g fill="#7B2D0A" stroke="#2C1810" stroke-width="3">
          <rect x="65" y="35" width="550" height="25"/>
          <rect x="25" y="80" width="50" height="205"/>
          <rect x="140" y="200" width="95" height="95"/>
          <rect x="585" y="120" width="40" height="205"/>
          <rect x="110" y="455" width="65" height="220"/>
          <rect x="195" y="455" width="55" height="90"/>
          <rect x="195" y="555" width="60" height="120"/>
          <rect x="120" y="745" width="45" height="210"/>
          <rect x="200" y="825" width="115" height="40"/>
          <rect x="235" y="875" width="40" height="40"/>
          <rect x="325" y="755" width="40" height="60"/>
          <rect x="355" y="905" width="25" height="45"/>
          <rect x="465" y="855" width="140" height="25"/>
          <rect x="685" y="835" width="60" height="120"/>
          <rect x="985" y="835" width="50" height="120"/>
          <rect x="1105" y="835" width="150" height="40"/>
          <rect x="1280" y="835" width="65" height="40"/>
          <rect x="1295" y="885" width="40" height="40"/>
          <rect x="1485" y="875" width="40" height="40"/>
          <rect x="1585" y="755" width="60" height="200"/>
          <rect x="1535" y="515" width="60" height="130"/>
          <rect x="1515" y="415" width="140" height="40"/>
          <rect x="1670" y="210" width="45" height="205"/>
          <rect x="1455" y="160" width="240" height="35"/>
          <rect x="1145" y="30" width="270" height="25"/>
        </g>
        <!-- Glass storage -->
        <g fill="#A8BEC5" stroke="#2C1810" stroke-width="3">
          <rect x="1425" y="30" width="160" height="125" fill="#C8C0B0"/>
          <rect x="1195" y="210" width="140" height="115"/>
          <rect x="385" y="935" width="50" height="40"/>
        </g>
        <!-- Layers the script fills in -->
        <polyline class="story-path" data-path="second" points="" fill="none" stroke="#D4A800" stroke-width="7" stroke-dasharray="18,14" stroke-linejoin="round"/>
        <g data-pins="second"></g>
      </svg>

      <div class="map-legend">
        <span><i style="background:#7B2D0A"></i>Wooden stand / staircase / wooden artifact</span>
        <span><i style="background:#A8BEC5"></i>Glass storage</span>
        <span><i style="background:#2B5F8E"></i>Statue</span>
        <span><i style="background:#C1533A"></i>Open to below</span>
        <span><i style="background:#D4A800;border-radius:50%"></i>Exhibit pin (storyline number)</span>
        <span><i style="background:#4A7C2F;border-radius:50%"></i>Exhibit pin (free explore)</span>
      </div>
    </div>
    <p style="font-size:12px;color:#6b7280;margin:8px 2px 0">
      Which floor a pin appears on comes from the exhibit's <em>Floor</em> field in the Exhibits tab. Click <strong>Edit layout</strong> to drag pins into place, then save.
    </p>
  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="card card-p" id="mode-card-storyline">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:10px">Storyline Path</div>
      @forelse($storyline as $ex)
      <div class="ex-row" onclick="focusPin({{ $ex->exhibit_id }})">
        <div style="width:24px;height:24px;border-radius:50%;background:#fbbf24;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">{{ $ex->storyline_order }}</div>
        <div style="min-width:0">
          <div style="font-size:12.5px;font-weight:600;color:#3b2a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $ex->name }}</div>
          <div style="font-size:11px;color:#888">{{ $ex->hall }}</div>
        </div>
        <span class="badge" data-badge="{{ $ex->exhibit_id }}"></span>
      </div>
      @empty
      <p style="font-size:12px;color:#888">No storyline set. Add exhibits with a storyline order in the Exhibits tab.</p>
      @endforelse
    </div>

    <div class="card card-p" id="mode-card-free" style="display:none">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:10px">All Active Exhibits</div>
      @foreach($allExhibits as $ex)
      <div class="ex-row" onclick="focusPin({{ $ex->exhibit_id }})">
        <div style="width:8px;height:8px;border-radius:50%;background:#3b82f6;flex-shrink:0"></div>
        <div style="min-width:0">
          <div style="font-size:12.5px;font-weight:600;color:#3b2a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $ex->name }}</div>
          <div style="font-size:11px;color:#888">{{ $ex->hall }}</div>
        </div>
        <span class="badge" data-badge="{{ $ex->exhibit_id }}"></span>
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
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px">
        <span style="color:#374151">Storyline Steps</span><strong style="color:#d97706">{{ $storyline->count() }}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:8px 0;font-size:13px">
        <span style="color:#374151">Not yet placed</span><strong style="color:#d97706" id="unplaced-count">0</strong>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
// Pins come from the database; x/y are percentages of the floor plan, null
// when nobody has placed the exhibit yet.
const PINS      = @json($pins);
const SAVE_URL  = @json(route('museum.map.positions'));
const CSRF      = @json(csrf_token());

// Where unplaced exhibits get dropped, per floor, along the walking route.
// Just a starting point — the admin drags them to the right case and saves.
const DEFAULT_SPOTS = {
  ground: [[24,54],[12,72],[30,85],[48,72],[18,24],[28,40],[70,30],[74,50],[86,20],[80,65],[60,70],[8,45]],
  second: [[12,35],[30,20],[50,20],[68,20],[85,35],[90,60],[80,88],[60,90],[40,90],[20,88],[12,60],[50,55]],
};

let mode = 'storyline';
let floor = 'ground';
let editing = false;
let dirty = false;
let saved = {};           // id -> {x,y} as last stored, for Cancel
let placedFromDefault = new Set();

const svgs = { ground: document.getElementById('map-svg'), second: document.getElementById('map-svg-2nd') };

function assignDefaults() {
  const counters = { ground: 0, second: 0 };
  PINS.forEach(p => {
    if (p.x === null || p.y === null) {
      const spots = DEFAULT_SPOTS[p.floor];
      const s = spots[counters[p.floor] % spots.length];
      const lap = Math.floor(counters[p.floor] / spots.length);
      p.x = Math.min(98, s[0] + lap * 3);
      p.y = Math.min(98, s[1] + lap * 3);
      counters[p.floor]++;
      placedFromDefault.add(p.id);
    }
    saved[p.id] = { x: p.x, y: p.y };
  });
}

function vb(svg) { return svg.viewBox.baseVal; }
function toPx(svg, p) { const v = vb(svg); return { x: p.x / 100 * v.width, y: p.y / 100 * v.height }; }

function render() {
  ['ground', 'second'].forEach(f => {
    const svg = svgs[f];
    const layer = svg.querySelector('[data-pins]');
    layer.innerHTML = '';
    const v = vb(svg);
    const r = Math.round(v.width * 0.019);
    const pinsHere = PINS.filter(p => p.floor === f);

    pinsHere.forEach(p => {
      const { x, y } = toPx(svg, p);
      const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      g.setAttribute('class', 'pin');
      g.dataset.id = p.id;
      g.setAttribute('transform', `translate(${x},${y})`);
      const isStory = mode === 'storyline';
      const fill = isStory && p.storyline > 0 ? '#D4A800' : (isStory ? '#9ca3af' : '#4A7C2F');
      // "EXH-009" reads as "9" on the pin; anything without digits gets a dot
      const codeNum = String(parseInt((p.code || '').replace(/\D/g, ''), 10) || '') || '•';
      const label = isStory && p.storyline > 0 ? p.storyline : codeNum;
      const nameShort = p.name.length > 22 ? p.name.slice(0, 21) + '…' : p.name;
      g.innerHTML =
        `<title>${escapeHtml(p.name)}${p.hall ? ' — ' + escapeHtml(p.hall) : ''}</title>` +
        `<circle r="${r}" fill="${fill}" stroke="#fff" stroke-width="5"/>` +
        `<text class="pin-label" y="${Math.round(r * 0.38)}" text-anchor="middle" font-size="${Math.round(r * 1.05)}" fill="#fff">${escapeHtml(String(label))}</text>` +
        `<text class="pin-label" y="${r + 24}" text-anchor="middle" font-size="19" fill="#2C1810" stroke="#E8D9B8" stroke-width="6" paint-order="stroke">${escapeHtml(nameShort)}</text>`;
      layer.appendChild(g);
      attachDrag(g, p, svg);
    });

    // Storyline path through this floor's pins, in order
    const path = svg.querySelector('[data-path]');
    const pts = pinsHere.filter(p => p.storyline > 0).sort((a, b) => a.storyline - b.storyline)
      .map(p => { const c = toPx(svg, p); return `${c.x},${c.y}`; });
    path.setAttribute('points', pts.join(' '));
    path.style.display = (mode === 'storyline' && pts.length > 1) ? 'block' : 'none';
  });

  // Sidebar badges + unplaced count
  document.querySelectorAll('[data-badge]').forEach(b => {
    const p = PINS.find(x => x.id == b.dataset.badge);
    if (!p) return;
    const fl = p.floor === 'second' ? '2F' : '1F';
    if (placedFromDefault.has(p.id)) { b.className = 'badge badge-unplaced'; b.textContent = fl + ' · not placed'; }
    else { b.className = 'badge badge-floor'; b.textContent = fl; }
  });
  document.getElementById('unplaced-count').textContent = placedFromDefault.size;
}

function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// ── Dragging ──────────────────────────────────────────────────────────────
function attachDrag(g, p, svg) {
  g.addEventListener('pointerdown', e => {
    if (!editing) return;
    e.preventDefault();
    g.setPointerCapture(e.pointerId);
    g.classList.add('dragging');
    g.parentNode.appendChild(g); // bring to front
    const move = ev => {
      const pt = clientToPercent(svg, ev.clientX, ev.clientY);
      p.x = pt.x; p.y = pt.y;
      const c = toPx(svg, p);
      g.setAttribute('transform', `translate(${c.x},${c.y})`);
      redrawPath(svg);
    };
    const up = () => {
      g.classList.remove('dragging');
      g.removeEventListener('pointermove', move);
      g.removeEventListener('pointerup', up);
      g.removeEventListener('pointercancel', up);
      placedFromDefault.delete(p.id);
      markDirty();
      render();
    };
    g.addEventListener('pointermove', move);
    g.addEventListener('pointerup', up);
    g.addEventListener('pointercancel', up);
  });
}

function clientToPercent(svg, cx, cy) {
  const pt = svg.createSVGPoint(); pt.x = cx; pt.y = cy;
  const loc = pt.matrixTransform(svg.getScreenCTM().inverse());
  const v = vb(svg);
  return {
    x: Math.min(99, Math.max(1, +(loc.x / v.width * 100).toFixed(2))),
    y: Math.min(99, Math.max(1, +(loc.y / v.height * 100).toFixed(2))),
  };
}

function redrawPath(svg) {
  const f = svg.dataset.floor;
  const path = svg.querySelector('[data-path]');
  const pts = PINS.filter(p => p.floor === f && p.storyline > 0).sort((a, b) => a.storyline - b.storyline)
    .map(p => { const c = toPx(svg, p); return `${c.x},${c.y}`; });
  path.setAttribute('points', pts.join(' '));
}

// ── Edit / save ───────────────────────────────────────────────────────────
function startEditing() {
  editing = true;
  document.body.classList.add('map-editing');
  document.getElementById('btn-edit').style.display = 'none';
  document.getElementById('btn-cancel').style.display = '';
  document.getElementById('btn-save').style.display = '';
  setStatus('Drag pins into place.');
  // Exhibits dropped at a default spot count as changes worth saving
  if (placedFromDefault.size) markDirty();
}
function cancelEditing() {
  PINS.forEach(p => { p.x = saved[p.id].x; p.y = saved[p.id].y; });
  stopEditing('');
  render();
}
function stopEditing(msg) {
  editing = false; dirty = false;
  document.body.classList.remove('map-editing');
  document.getElementById('btn-edit').style.display = '';
  document.getElementById('btn-cancel').style.display = 'none';
  document.getElementById('btn-save').style.display = 'none';
  document.getElementById('btn-save').disabled = true;
  setStatus(msg);
}
function markDirty() {
  dirty = true;
  document.getElementById('btn-save').disabled = false;
  setStatus('Unsaved changes');
}
function setStatus(t) { document.getElementById('map-status').textContent = t; }

async function saveLayout() {
  const btn = document.getElementById('btn-save');
  btn.disabled = true; setStatus('Saving…');
  try {
    const res = await fetch(SAVE_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify({ positions: PINS.map(p => ({ id: p.id, x: p.x, y: p.y })) }),
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    PINS.forEach(p => { saved[p.id] = { x: p.x, y: p.y }; });
    placedFromDefault.clear();
    stopEditing('Layout saved.');
    render();
  } catch (e) {
    btn.disabled = false;
    setStatus('Save failed — ' + e.message);
  }
}

// ── Mode / floor / focus ──────────────────────────────────────────────────
function setMode(m) {
  mode = m;
  document.getElementById('mode-storyline').classList.toggle('active', m === 'storyline');
  document.getElementById('mode-free').classList.toggle('active', m === 'free');
  document.getElementById('mode-card-storyline').style.display = m === 'storyline' ? 'block' : 'none';
  document.getElementById('mode-card-free').style.display = m === 'free' ? 'block' : 'none';
  render();
}
function setFloor(f, btn) {
  floor = f;
  document.querySelectorAll('.floor-tab').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  else document.querySelectorAll('.floor-tab')[f === 'ground' ? 0 : 1].classList.add('active');
  svgs.ground.style.display = f === 'ground' ? 'block' : 'none';
  svgs.second.style.display = f === 'second' ? 'block' : 'none';
}
function focusPin(id) {
  const p = PINS.find(x => x.id === id);
  if (!p) return;
  if (p.floor !== floor) setFloor(p.floor);
  document.querySelectorAll('.pin.highlight').forEach(g => g.classList.remove('highlight'));
  const g = svgs[p.floor].querySelector(`.pin[data-id="${id}"]`);
  if (g) { g.classList.add('highlight'); g.parentNode.appendChild(g); setTimeout(() => g.classList.remove('highlight'), 2000); }
}

assignDefaults();
render();
</script>
@endpush
