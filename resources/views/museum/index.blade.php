@extends('layouts.admin')
@section('title','Museum Info — Museo Baler')

@push('styles')
<style>
.info-section{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow-sm);margin-bottom:10px;overflow:hidden}
.info-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;cursor:pointer;user-select:none;transition:background .12s}
.info-hd:hover{background:#f9fafb}
.info-hd.open{background:var(--green-pale)}
.info-hd-left{display:flex;align-items:center;gap:10px}
.info-hd-title{font-size:13px;font-weight:700;color:var(--text)}
.info-hd-sub{font-size:11px;color:var(--text-3);margin-top:1px}
.info-body{display:none;padding:16px 18px;border-top:1px solid var(--border)}
.info-body.open{display:block}
.info-chevron{width:14px;height:14px;color:var(--text-3);transition:transform .2s;flex-shrink:0}

/* Hall rows */
.hall-row{background:var(--border-light);border:1px solid var(--border);border-radius:var(--r-sm);margin-bottom:8px;overflow:hidden}
.hall-hd{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;cursor:pointer;user-select:none;transition:background .12s}
.hall-hd:hover{background:#f0f0f0}
.hall-hd.open{background:var(--green-pale)}
.hall-bd{display:none;padding:10px 12px 12px;border-top:1px solid var(--border)}
.hall-bd.open{display:block}
.hall-view-row{display:flex;gap:8px;padding:4px 0;font-size:12.5px;border-bottom:1px solid var(--border-light)}
.hall-view-row:last-of-type{border-bottom:none}
.hall-view-lbl{width:80px;flex-shrink:0;font-size:10.5px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.04em;padding-top:2px}
.hall-view-val{color:var(--text-2);flex:1}
.btn-muted{background:var(--surface);color:var(--text-3);border:1.5px solid var(--border)}
.btn-muted:hover{border-color:var(--text-3);color:var(--text)}
</style>
@endpush

@section('content')

<div class="ph">
  <div class="ph-left"><h2>Museum Info</h2><p>Manage content shown in the visitor app</p></div>
  <div class="ph-right">
    <a href="{{ route('museum.map') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
      Floor Map
    </a>
    <button class="btn btn-green btn-sm" onclick="document.getElementById('museumForm').submit()">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Save All Changes
    </button>
  </div>
</div>

{{-- Preview strip --}}
<div style="background:linear-gradient(135deg,#2D5016,#4A7C2F);border-radius:var(--r);padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px">
  <div style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="width:18px;height:18px"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
  </div>
  <div>
    <div style="font-size:15px;font-weight:700;color:white" id="preview-name">{{ $info->name }}</div>
    <div style="font-size:11px;color:rgba(255,255,255,.7)" id="preview-tagline">{{ $info->tagline }}</div>
  </div>
</div>

<form id="museumForm" method="POST" action="{{ route('museum.update') }}" enctype="multipart/form-data">
  @csrf

  {{-- ── Section 1: Basic Information ── --}}
  <div class="info-section">
    <div class="info-hd open" onclick="toggleSection(this)">
      <div class="info-hd-left">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:var(--green-dark)"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
          <div class="info-hd-title">Basic Information</div>
          <div class="info-hd-sub">Name, tagline, and story</div>
        </div>
      </div>
      <svg class="info-chevron" style="transform:rotate(180deg)" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="info-body open">
      <div class="fi-row">
        <div class="fg"><label class="fl">Museum Name</label><input class="fi" name="name" value="{{ $info->name }}" oninput="document.getElementById('preview-name').textContent=this.value"></div>
        <div class="fg"><label class="fl">Tagline / Location</label><input class="fi" name="tagline" value="{{ $info->tagline }}" oninput="document.getElementById('preview-tagline').textContent=this.value"></div>
      </div>
      <div class="fg"><label class="fl">Our Story (first paragraph)</label><textarea class="fi" name="story" rows="4">{{ $info->story }}</textarea></div>
      <div class="fg" style="margin-bottom:0"><label class="fl">Second Paragraph</label><textarea class="fi" name="story2" rows="4">{{ $info->story2 }}</textarea></div>
    </div>
  </div>

  {{-- ── Section 2: Contact & Hours ── --}}
  <div class="info-section">
    <div class="info-hd" onclick="toggleSection(this)">
      <div class="info-hd-left">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:var(--green-dark)"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.96a16 16 0 0 0 6.13 6.13l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        <div>
          <div class="info-hd-title">Contact & Hours</div>
          <div class="info-hd-sub">Address, schedule, and contact details</div>
        </div>
      </div>
      <svg class="info-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="info-body">
      <div class="fg"><label class="fl">Address</label><input class="fi" name="address" value="{{ $info->address }}"></div>
      <div class="fi-row">
        <div class="fg"><label class="fl">Opening Hours</label><input class="fi" name="hours" value="{{ $info->hours }}"></div>
        <div class="fg"><label class="fl">Closed On</label><input class="fi" name="closed_on" value="{{ $info->closed_on }}"></div>
      </div>
      {{-- One number drives every screen that mentions admission: the desk
           register, the poster, the visitor app's sign-up and About screens. --}}
      <div class="fi-row">
        <div class="fg">
          <label class="fl">Admission Fee (PHP, per visitor)</label>
          <input class="fi" type="number" name="admission_fee" id="admissionFee" min="0" max="99999.99" step="0.01" required
                 value="{{ old('admission_fee', $info->admission_fee ?? \App\Models\MuseumInfo::DEFAULT_ADMISSION_FEE) }}"
                 oninput="previewAdmission()">
          @error('admission_fee')<div style="font-size:12px;color:var(--red);margin-top:4px">{{ $message }}</div>@enderror
        </div>
        <div class="fg">
          <label class="fl">Shown to visitors as</label>
          <div class="fi" id="admissionPreview" style="background:var(--border-light);color:var(--text-2);cursor:default">{{ $info->admission_sentence }}</div>
          <div style="font-size:11.5px;color:var(--text-3);margin-top:4px">Baler residents always enter free with a valid ID. Set 0 to make admission free for everyone.</div>
        </div>
      </div>
      <div class="fi-row" style="margin-bottom:0">
        <div class="fg" style="margin-bottom:0"><label class="fl">Phone</label><input class="fi" name="phone" value="{{ $info->phone }}"></div>
        <div class="fg" style="margin-bottom:0"><label class="fl">Email</label><input class="fi" name="email" value="{{ $info->email }}"></div>
      </div>
    </div>
  </div>

  {{-- ── Section: Report Branding ── --}}
  @php $brand = \App\Models\MuseumInfo::branding(); @endphp
  <div class="info-section">
    <div class="info-hd" onclick="toggleSection(this)">
      <div class="info-hd-left">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:var(--green-dark)"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        <div>
          <div class="info-hd-title">Report Branding</div>
          <div class="info-hd-sub">The letterhead printed at the top of every report</div>
        </div>
      </div>
      <svg class="info-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="info-body">
      {{-- The uploaded letterhead. When one is set it REPLACES the composed
           header below on every report and in every export, which is why it
           sits first and says so: an office that has its own banner should
           not have to work out why the logo it also uploaded is not
           printing. --}}
      <div class="fg" style="margin-bottom:18px">
        <label class="fl">Letterhead</label>
        <div id="headerPreviewBox" style="border:1.5px dashed var(--border);border-radius:8px;background:#fff;padding:10px;margin-bottom:8px;{{ $brand['header'] ? '' : 'display:none' }}">
          <img id="headerPreview" src="{{ $brand['header'] ?: '' }}" alt="" style="width:100%;height:auto;display:block">
        </div>
        <input class="fi" type="file" name="report_header_image" accept="image/png,image/jpeg,image/webp" onchange="previewHeader(this)">
        <div style="font-size:11.5px;color:var(--text-3);margin-top:4px">
          PNG, JPG or WEBP, up to 4 MB. A wide banner — around 1200×200 — prints sharpest.
          Upload the office letterhead here and it goes on every report, the spreadsheets and the Word exports.
          <strong>While a letterhead is set, the logo and museum name below are not printed.</strong>
        </div>
        @if($brand['header'])
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2);margin-top:6px;cursor:pointer">
            <input type="checkbox" name="remove_header" value="1"> Remove the letterhead and go back to the logo
          </label>
        @endif
        @error('report_header_image')<div style="font-size:12px;color:var(--red);margin-top:4px">{{ $message }}</div>@enderror
      </div>

      <div class="fi-row">
        <div class="fg">
          <label class="fl">Logo</label>
          <div style="display:flex;gap:14px;align-items:center">
            <div id="logoPreviewBox" style="width:72px;height:72px;border:1.5px dashed var(--border);border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
              @if($brand['logo'])
                <img id="logoPreview" src="{{ $brand['logo'] }}" alt="" style="max-width:100%;max-height:100%;object-fit:contain">
              @else
                <img id="logoPreview" src="" alt="" style="max-width:100%;max-height:100%;object-fit:contain;display:none">
                <span id="logoNone" style="font-size:11px;color:var(--text-4)">none</span>
              @endif
            </div>
            <div style="flex:1">
              <input class="fi" type="file" name="report_logo" accept="image/png,image/jpeg,image/webp" onchange="previewLogo(this)">
              <div style="font-size:11.5px;color:var(--text-3);margin-top:4px">PNG, JPG or WEBP, up to 2 MB. A square or wide logo on a plain background prints best.</div>
              @if($brand['logo'])
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2);margin-top:6px;cursor:pointer">
                  <input type="checkbox" name="remove_logo" value="1"> Remove the current logo
                </label>
              @endif
              @error('report_logo')<div style="font-size:12px;color:var(--red);margin-top:4px">{{ $message }}</div>@enderror
            </div>
          </div>
        </div>
      </div>
      <div style="font-size:12px;color:var(--text-3)">
        Applies to every printed report and the daily logbook. Save, then
        <a href="{{ route('reports.visitors') }}" target="_blank" style="color:var(--green-dark);font-weight:600">open a report</a> to check how it looks.
      </div>
    </div>
  </div>

  {{-- ── Section 3: Geofencing (auto attendance) ── --}}
  <div class="info-section">
    <div class="info-hd" onclick="toggleSection(this)">
      <div class="info-hd-left">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:var(--green-dark)"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <div>
          <div class="info-hd-title">Geofencing</div>
          <div class="info-hd-sub">GPS anchor point the visitor app uses for auto check-in</div>
        </div>
      </div>
      <svg class="info-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="info-body">
      <div style="font-size:11.5px;color:var(--text-3);margin-bottom:10px">
        {{-- This pin is not only the visitor app's: GeofenceService reads the
             same row for staff check-in, so an inaccurate pin here stops the
             whole team clocking in. Worth saying out loud on the screen that
             edits it. --}}
        One pin, two uses. A visitor is counted as "present" once their phone reports GPS coordinates within this radius of it, and staff can only check in or out from inside the same circle. Set it wrong and nobody can clock in.
      </div>
      <div class="fi-row">
        <div class="fg"><label class="fl">Latitude</label><input class="fi" name="latitude" type="text" inputmode="decimal" value="{{ $info->latitude }}" placeholder="e.g. 15.7604405"></div>
        <div class="fg"><label class="fl">Longitude</label><input class="fi" name="longitude" type="text" inputmode="decimal" value="{{ $info->longitude }}" placeholder="e.g. 121.5616958"></div>
      </div>
      {{-- Typing coordinates off Google Maps is how the pin ends up in the
           wrong car park. Standing at the entrance and pressing this is the
           accurate way to set it - and the only practical way to point the
           geofence at wherever the system is being tested from. --}}
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:-4px 0 12px">
        <button type="button" class="btn btn-outline btn-sm" id="useMyLocation">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8"/></svg>
          Use my current location
        </button>
        <span id="useMyLocationMsg" style="font-size:11.5px;color:var(--text-3)"></span>
      </div>
      <div class="fg" style="margin-bottom:0">
        <label class="fl">Geofence Radius (meters)</label>
        <input class="fi" name="geofence_radius_m" type="text" inputmode="numeric" value="{{ $info->geofence_radius_m ?? 150 }}" style="max-width:160px">
      </div>
    </div>
  </div>

  {{-- ── Section 4: Museum Halls ── --}}
  <div class="info-section">
    <div class="info-hd" onclick="toggleSection(this)">
      <div class="info-hd-left">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;color:var(--green-dark)"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <div>
          <div class="info-hd-title">Museum Halls</div>
          <div class="info-hd-sub">{{ $halls->count() }} hall{{ $halls->count() !== 1 ? 's' : '' }} configured</div>
        </div>
      </div>
      <svg class="info-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="info-body">
      <div id="hallsList">
        @foreach($halls as $hall)
        <div class="hall-row" data-id="{{ $hall->hall_id }}">
          <div class="hall-hd" onclick="toggleHall(this)">
            <div style="display:flex;align-items:center;gap:8px">
              <div>
                <div style="font-size:12.5px;font-weight:600;color:var(--text)">{{ $hall->name }}</div>
                <div style="font-size:11px;color:var(--text-3)">{{ $hall->floor }}{{ $hall->description ? ' · '.$hall->description : '' }}</div>
              </div>
            </div>
            <svg class="hall-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;color:var(--text-3);transition:transform .2s"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="hall-bd">
            {{-- Read-only --}}
            <div class="hall-view">
              <div class="hall-view-row"><span class="hall-view-lbl">Name</span><span class="hall-view-val hall-name-val">{{ $hall->name }}</span></div>
              <div class="hall-view-row"><span class="hall-view-lbl">Floor</span><span class="hall-view-val hall-floor-val">{{ $hall->floor }}</span></div>
              <div class="hall-view-row"><span class="hall-view-lbl">Description</span><span class="hall-view-val hall-desc-val">{{ $hall->description ?: '—' }}</span></div>
              <div class="hall-view-row"><span class="hall-view-lbl">Icon</span><span class="hall-view-val hall-icon-val">{{ $hall->icon ?: '—' }}</span></div>
              <div class="hall-view-row"><span class="hall-view-lbl">Sort</span><span class="hall-view-val hall-sort-val">{{ $hall->sort_order }}</span></div>
              <div style="display:flex;gap:6px;margin-top:10px">
                <button type="button" class="btn btn-green btn-xs" onclick="editHall(this)">Edit</button>
                <button type="button" class="btn btn-muted btn-xs" onclick="removeHall(this, {{ $hall->hall_id }})">Delete</button>
              </div>
            </div>
            {{-- Edit form --}}
            <div class="hall-edit" style="display:none">
              <div class="fi-row" style="margin-bottom:8px">
                <div class="fg"><label class="fl">Hall Name</label><input class="fi hall-name" value="{{ $hall->name }}"></div>
                <div class="fg"><label class="fl">Floor</label><select class="fi hall-floor">@foreach(\App\Models\MuseumHall::FLOORS as $f)<option @selected($hall->floor === $f)>{{ $f }}</option>@endforeach</select></div>
              </div>
              <div class="fi-row" style="margin-bottom:8px">
                <div class="fg"><label class="fl">Description</label><input class="fi hall-desc" value="{{ $hall->description }}"></div>
                <div class="fg"><label class="fl">Icon / Sort</label>
                  <div style="display:flex;gap:6px">
                    <input class="fi hall-icon" value="{{ $hall->icon }}" placeholder="history_edu" style="flex:1">
                    <input class="fi hall-sort" type="number" value="{{ $hall->sort_order }}" min="1" style="width:52px">
                  </div>
                </div>
              </div>
              <div style="display:flex;gap:6px">
                <button type="button" class="btn btn-green btn-xs" onclick="saveHallEdit(this)">Done</button>
                <button type="button" class="btn btn-outline btn-xs" onclick="cancelHallEdit(this)">Cancel</button>
              </div>
            </div>
          </div>
        </div>
        @endforeach
      </div>
      <button type="button" class="btn btn-outline btn-sm" style="margin-top:4px" onclick="addHall()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Hall
      </button>
      <input type="hidden" name="halls" id="hallsInput">
    </div>
  </div>

</form>
@endsection

@push('scripts')
<script>
// ── Admission: show the sentence visitors will read as the fee is typed ───
// Mirrors MuseumInfo::admissionSentence(); the server writes the real one.
// ── Report logo: show the chosen file before it is saved ─────────────────
function previewLogo(input) {
  var img = document.getElementById('logoPreview'), none = document.getElementById('logoNone');
  if (!input.files || !input.files[0]) return;
  img.src = URL.createObjectURL(input.files[0]);
  img.style.display = '';
  if (none) none.style.display = 'none';
}

// Shows the chosen letterhead before it is saved: it spans the page, and
// "wide enough" is not something anyone can judge from a filename.
function previewHeader(input) {
  var box = document.getElementById('headerPreviewBox'),
      img = document.getElementById('headerPreview');
  if (!input.files || !input.files[0]) return;
  img.src = URL.createObjectURL(input.files[0]);
  box.style.display = '';
}

function previewAdmission() {
  const fee = parseFloat(document.getElementById('admissionFee').value);
  const out = document.getElementById('admissionPreview');
  if (!out) return;
  out.textContent = (!isNaN(fee) && fee > 0)
    ? 'Baler residents enter free with a valid ID · Visitors ₱' + fee.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : 'Free for all visitors';
}

// ── Set the geofence pin from where this device is standing ───
(function () {
  const btn = document.getElementById('useMyLocation');
  const msg = document.getElementById('useMyLocationMsg');

  if (!btn) return;

  function say(text, tone) {
    msg.textContent = text;
    msg.style.color = tone === 'bad'  ? 'var(--red)'
                    : tone === 'good' ? 'var(--green-dark)'
                    : 'var(--text-3)';
  }

  btn.addEventListener('click', function () {
    if (!navigator.geolocation) {
      say('This browser cannot report a location.', 'bad');
      return;
    }

    // Geolocation is refused outside a secure context, so over plain http on
    // a LAN address this silently never calls back. Say so rather than
    // leaving the button looking broken.
    if (!window.isSecureContext) {
      say('Needs HTTPS (or localhost) to read a location.', 'bad');
      return;
    }

    btn.disabled = true;
    say('Getting your location…');

    navigator.geolocation.getCurrentPosition(
      function (pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        const acc = Math.round(pos.coords.accuracy);

        document.querySelector('input[name="latitude"]').value  = lat.toFixed(7);
        document.querySelector('input[name="longitude"]').value = lng.toFixed(7);

        btn.disabled = false;
        // Accuracy is worth showing: a fix vaguer than the radius would put
        // the pin somewhere the staff standing here are not.
        // A laptop has no GPS: its position is a Wi-Fi/IP guess that has
        // landed 1.8 km from the door. Warn hard, and point at the phone.
        say(acc > 100
          ? 'Filled in, but only accurate to about ' + acc + 'm — a computer guesses its position from Wi-Fi. Set the pin from a phone instead: My Attendance → Set museum pin.'
          : 'Filled in — accurate to about ' + acc + 'm. Save to apply.', acc > 100 ? 'bad' : 'good');
      },
      function (err) {
        btn.disabled = false;
        say(err.code === err.PERMISSION_DENIED
          ? 'Location permission was refused.'
          : 'Could not get a location fix. Try again outdoors.', 'bad');
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  });
})();

let hallCount = {{ $halls->count() }};

// ── Collect halls on submit ───────────────────────────────────
document.getElementById('museumForm').addEventListener('submit', function(){
  const rows = document.querySelectorAll('#hallsList .hall-row');
  const halls = Array.from(rows).map(r => ({
    id:    r.dataset.id || '',
    name:  r.querySelector('.hall-name').value,
    floor: r.querySelector('.hall-floor').value,
    desc:  r.querySelector('.hall-desc').value,
    icon:  r.querySelector('.hall-icon').value,
    sort:  r.querySelector('.hall-sort').value,
  }));
  document.getElementById('hallsInput').value = JSON.stringify(halls);
});

// ── Toggle info section ───────────────────────────────────────
function toggleSection(hd){
  const body = hd.nextElementSibling;
  const chevron = hd.querySelector('.info-chevron');
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  hd.classList.toggle('open', !isOpen);
  chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
}

// ── Toggle hall ───────────────────────────────────────────────
function toggleHall(hd){
  const body = hd.nextElementSibling;
  const chevron = hd.querySelector('.hall-chevron');
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  hd.classList.toggle('open', !isOpen);
  chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
}

// ── Hall edit / save / cancel ─────────────────────────────────
function editHall(btn){
  const bd = btn.closest('.hall-bd');
  bd.querySelector('.hall-view').style.display = 'none';
  bd.querySelector('.hall-edit').style.display = 'block';
}
function saveHallEdit(btn){
  const bd  = btn.closest('.hall-bd');
  const row = btn.closest('.hall-row');
  const name  = row.querySelector('.hall-name').value;
  const floor = row.querySelector('.hall-floor').value;
  const desc  = row.querySelector('.hall-desc').value;
  const icon  = row.querySelector('.hall-icon').value;
  const sort  = row.querySelector('.hall-sort').value;
  bd.querySelector('.hall-name-val').textContent  = name  || '—';
  bd.querySelector('.hall-floor-val').textContent = floor || '—';
  bd.querySelector('.hall-desc-val').textContent  = desc  || '—';
  bd.querySelector('.hall-icon-val').textContent  = icon  || '—';
  bd.querySelector('.hall-sort-val').textContent  = sort  || '—';
  // Update header subtitle
  const hd = row.querySelector('.hall-hd');
  hd.querySelector('div > div:first-child').textContent = name;
  hd.querySelector('div > div:last-child').textContent  = floor + (desc ? ' · '+desc : '');
  bd.querySelector('.hall-edit').style.display = 'none';
  bd.querySelector('.hall-view').style.display = 'block';
}
function cancelHallEdit(btn){
  const bd = btn.closest('.hall-bd');
  bd.querySelector('.hall-edit').style.display = 'none';
  bd.querySelector('.hall-view').style.display = 'block';
}

// ── Add hall ──────────────────────────────────────────────────
function addHall(){
  hallCount++;
  const div = document.createElement('div');
  div.className = 'hall-row';
  div.dataset.id = '';
  div.innerHTML = `
    <div class="hall-hd open" onclick="toggleHall(this)">
      <div style="display:flex;align-items:center;gap:8px">
        <div>
          <div style="font-size:12.5px;font-weight:600;color:var(--text)">New Hall</div>
          <div style="font-size:11px;color:var(--text-3)">Fill in details below</div>
        </div>
      </div>
      <svg class="hall-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;color:var(--text-3);transform:rotate(180deg)"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="hall-bd open">
      <div class="hall-view" style="display:none">
        <div class="hall-view-row"><span class="hall-view-lbl">Name</span><span class="hall-view-val hall-name-val">—</span></div>
        <div class="hall-view-row"><span class="hall-view-lbl">Floor</span><span class="hall-view-val hall-floor-val">—</span></div>
        <div class="hall-view-row"><span class="hall-view-lbl">Description</span><span class="hall-view-val hall-desc-val">—</span></div>
        <div class="hall-view-row"><span class="hall-view-lbl">Icon</span><span class="hall-view-val hall-icon-val">—</span></div>
        <div class="hall-view-row"><span class="hall-view-lbl">Sort</span><span class="hall-view-val hall-sort-val">—</span></div>
        <div style="display:flex;gap:6px;margin-top:10px">
          <button type="button" class="btn btn-green btn-xs" onclick="editHall(this)">Edit</button>
          <button type="button" class="btn btn-muted btn-xs" onclick="this.closest('.hall-row').remove()">Delete</button>
        </div>
      </div>
      <div class="hall-edit">
        <div class="fi-row" style="margin-bottom:8px">
          <div class="fg"><label class="fl">Hall Name</label><input class="fi hall-name" placeholder="e.g. Hall A"></div>
          <div class="fg"><label class="fl">Floor</label><select class="fi hall-floor">@foreach(\App\Models\MuseumHall::FLOORS as $f)<option>{{ $f }}</option>@endforeach</select></div>
        </div>
        <div class="fi-row" style="margin-bottom:8px">
          <div class="fg"><label class="fl">Description</label><input class="fi hall-desc" placeholder="Short description"></div>
          <div class="fg"><label class="fl">Icon / Sort</label>
            <div style="display:flex;gap:6px">
              <input class="fi hall-icon" placeholder="history_edu" style="flex:1">
              <input class="fi hall-sort" type="number" value="${hallCount}" min="1" style="width:52px">
            </div>
          </div>
        </div>
        <div style="display:flex;gap:6px">
          <button type="button" class="btn btn-green btn-xs" onclick="saveHallEdit(this)">Done</button>
          <button type="button" class="btn btn-muted btn-xs" onclick="this.closest('.hall-row').remove()">Delete</button>
        </div>
      </div>
    </div>`;
  document.getElementById('hallsList').appendChild(div);
  div.scrollIntoView({behavior:'smooth',block:'nearest'});
}

// ── Remove hall ───────────────────────────────────────────────
function removeHall(btn, id){
  if(!confirm('Delete this hall?')) return;
  btn.closest('.hall-row').remove();
}
</script>
@endpush
