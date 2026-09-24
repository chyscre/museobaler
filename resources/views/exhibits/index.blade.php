@extends('layouts.admin')
@section('title','Exhibits — Museo de Baler')

@push('styles')
<style>
.ex-card{cursor:pointer}


.btn-muted{background:var(--surface);color:var(--text-3);border:1.5px solid var(--border)}
.btn-muted:hover{border-color:var(--text-3);color:var(--text)}
.ex-stale{position:absolute;top:10px;right:10px;z-index:2;display:inline-flex;align-items:center;gap:4px;background:#b45309;color:#fff;border-radius:20px;padding:3px 9px;font-size:10.5px;font-weight:700;letter-spacing:.02em}
.ex-chip{display:inline-flex;align-items:center;background:var(--border-light);border-radius:4px;padding:2px 8px;font-size:11px;color:var(--text-3);font-weight:500}
/* The exhibit modal is a working surface, not a dialog: editing an exhibit
   means a form, a picture, translations and a gallery at once, and at 660px
   those stacked into a column nobody could see the end of. Wide, with the
   picture beside the fields rather than above them. */
#exModal .modal{max-width:1080px}
.ex-two{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;align-items:start}
.ex-side{position:sticky;top:0}
/* Exhibit photos are portrait more often than not; show the whole of it. */
.ex-side-img{width:100%;max-height:340px;object-fit:contain;background:var(--border-light);border-radius:var(--r-sm);display:block}
@media (max-width:1000px){.ex-two{grid-template-columns:1fr}.ex-side{position:static}}
/* modal view/edit toggle */
#exModal .view-mode{display:block}
#exModal .edit-mode{display:none}
#exModal.editing .view-mode{display:none}
#exModal.editing .edit-mode{display:block}
/* Translations & audio review (public/js/exhibit-ai.js) */
.ai-title{font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em;margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.ai-section{background:var(--border-light);border-radius:var(--r-sm);padding:12px 14px;margin-bottom:14px}
.ai-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ai-head-left{display:flex;align-items:center;gap:8px}
.ai-head-right{display:flex;align-items:center;gap:6px}
.ai-status{font-size:12.5px;padding:8px 10px;border-radius:var(--r-sm);margin-top:10px}
.ai-status-info{color:var(--text-3)}
.ai-status-ok{color:var(--green-dark)}
.ai-status-warn{color:#b45309}
.ai-status-error{color:#b91c1c}
.ai-status.ai-status-ok{background:var(--green-pale)}
.ai-status.ai-status-warn{background:#fffbeb}
.ai-status.ai-status-error{background:var(--red-pale)}
.ai-cards{display:grid;gap:10px;margin-top:10px}
.ai-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:12px 14px}
.ai-card .fg{margin-bottom:8px}
.ai-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.ai-card-lang{font-size:13px;font-weight:700;color:var(--text)}
.ai-audio-row{display:flex;align-items:center;gap:8px}
.ai-audio{flex:1;min-width:0}
.ai-upload{position:relative;overflow:hidden;font-size:11.5px;color:var(--text-3);cursor:pointer;border:1px dashed var(--border);border-radius:var(--r-sm);padding:4px 8px;white-space:nowrap}
.ai-upload input{position:absolute;inset:0;opacity:0;cursor:pointer}
.ai-audio-note{font-size:11.5px;margin-top:6px}
.ai-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:10px}
.ai-hint{font-size:11.5px;color:var(--text-3)}
.ai-add{font-size:11.5px;color:var(--text-3);display:flex;align-items:center;gap:6px}
.ai-section [hidden]{display:none!important}
.ex-vh{font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.ex-vt{font-size:13px;color:var(--text-2);line-height:1.7;white-space:pre-line}
</style>
@endpush

@section('content')
@php
  // Which form bounced back with errors: the Add modal, or an edit / translation /
  // gallery form that also lands on this page. Files never survive the bounce.
  $addFailed = $errors->any() && old('_form') === 'add_exhibit';

  // The translation cards as they were when the add form bounced. Narration
  // drafts are still parked on the server, so they come back playable too.
  $restoredCards = [];
  foreach ((array) old('t_code', []) as $i => $code) {
      $draft = old("t_audio_draft.$i");
      $restoredCards[] = [
          'language_code' => $code,
          'title'         => old("t_title.$i"),
          'description'   => old("t_desc.$i"),
          'fun_facts'     => old("t_facts.$i"),
          'draft'         => $draft,
          'audio_url'     => \App\Http\Controllers\ExhibitAiController::draftPath($draft)
                                 ? asset(\App\Http\Controllers\ExhibitAiController::DRAFT_DIR . "/$draft.wav") : null,
      ];
  }
@endphp

@if($errors->any() && !$addFailed)
  <div class="alert alert-error">
    <strong>That change was not saved.</strong>
    <ul style="margin:6px 0 0;padding-left:16px">
      @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
@endif

<div class="ph">
  <div class="ph-left">
    <h2>Exhibit</h2>
  </div>
  <div class="ph-right">
    <button class="btn btn-outline btn-sm" onclick="window.location='{{ route('exhibits.qr.panel') }}'">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      All QR Codes
    </button>
    <button class="btn btn-outline btn-sm" onclick="window.location='{{ route('recognition.index') }}'">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
      Recognition
    </button>
    <button class="btn btn-green btn-sm" onclick="document.getElementById('addModal').classList.add('open')">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Exhibit
    </button>
  </div>
</div>

@if($staleAudioTotal)
  {{-- The file plays either way, so nothing about a stale narration shows
       itself; it has to be said here or not at all. --}}
  <div class="alert alert-error" style="display:flex;gap:9px;align-items:flex-start;background:#fffbeb;border:1px solid #fcd34d;color:#92400e">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;flex-shrink:0;margin-top:1px"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M22 9l-6 6"/><path d="M16 9l6 6"/></svg>
    <div>
      <strong>{{ $staleAudioTotal }} audio guide(s) read text that has since been rewritten.</strong>
      Visitors hear the old description. Open each exhibit marked below and press <em>Narrate again</em>, then save.
    </div>
  </div>
@endif

<div class="fbar">
  <div class="search-box">
    <svg class="si" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="text" id="exSearch" placeholder="Search exhibits…" oninput="filterExhibits()">
  </div>
  <x-fctl icon="floor">
    <select class="fsel" id="exFloor" onchange="filterExhibits()">
      <option value="">All Floors</option>
      <option>Ground Floor</option><option>2nd Floor</option>
    </select>
  </x-fctl>
  <x-fctl icon="hall">
    <select class="fsel" id="exHall" onchange="filterExhibits()">
      <option value="">All Halls</option>
      @foreach($halls as $h)<option>{{ $h->name }}</option>@endforeach
    </select>
  </x-fctl>
  <x-fctl icon="tag">
    <select class="fsel" id="exCat" onchange="filterExhibits()">
      <option value="">All Categories</option>
      @foreach($categories as $cat)
      <option>{{ $cat->name }}</option>
      @endforeach
    </select>
  </x-fctl>
  <x-fctl icon="status">
    <select class="fsel" id="exStatus" onchange="filterExhibits()">
      <option value="">All Status</option>
      <option value="1">Active</option><option value="0">Archived</option>
    </select>
  </x-fctl>
  <x-fctl icon="sort">
    <select class="fsel" id="exSort" onchange="filterExhibits()">
      <option value="storyline">Sort: Storyline</option>
      <option value="name">Sort: Name A–Z</option>
      <option value="scans">Sort: Most Scanned</option>
    </select>
  </x-fctl>
  <span id="exCount" class="fcount"></span>
</div>

<div class="ex-grid" id="exGrid">
@foreach($exhibits as $ex)
<div class="ex-card {{ $ex->status ? '' : 'archived' }}"
     data-id="{{ $ex->exhibit_id }}"
     data-name="{{ strtolower($ex->name) }}"
     data-floor="{{ $ex->floor }}"
     data-hall="{{ $ex->hall }}"
     data-cat="{{ $ex->category?->name }}"
     data-status="{{ $ex->status ? 1 : 0 }}"
     data-order="{{ $ex->storyline_order }}"
     data-scans="{{ $ex->scans_count }}"
     onclick="openExModal({{ $ex->exhibit_id }})">
  {{-- The whole card is the picture; the name sits on it, gallery-poster
       style. Details ride along in small type so the grid stays a wall of
       images rather than a list of forms. --}}
  <div class="ex-thumb {{ $ex->image ? '' : 'no-image' }}">
    @if($ex->image)
      <img src="{{ $ex->image_url }}" alt="" loading="lazy">
    @endif
    <span class="ex-code">{{ $ex->exhibit_code }}</span>
    @if(!$ex->status)<span class="arch-tag">Archived</span>@endif
    @if($ex->stale_audio)
      <span class="ex-stale" title="{{ $ex->stale_audio }} audio guide(s) read the older text - narrate again">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:11px;height:11px"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M22 9l-6 6"/><path d="M16 9l6 6"/></svg>
        Old audio
      </span>
    @endif
    <div class="ex-overlay">
      <div class="ex-title">{{ $ex->name }}</div>
      <div class="ex-meta">
        <span>{{ $ex->floor }} · {{ $ex->hall }}</span>
        @if($ex->category)<span>{{ $ex->category->name }}</span>@endif
        <span>{{ number_format($ex->scans_count) }} scans</span>
      </div>
    </div>
  </div>
</div>
@endforeach
</div>

{{-- ── Add Exhibit Modal ── --}}
<div class="overlay" id="addModal">
  <div class="modal modal-lg">
    <div class="modal-hd">
      <h3>Add Exhibit</h3>
      <button class="modal-close" onclick="document.getElementById('addModal').classList.remove('open')">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <form method="POST" action="{{ route('exhibits.store') }}" enctype="multipart/form-data">
      @csrf
      <input type="hidden" name="_form" value="add_exhibit">

      {{-- A refused save used to bounce back to a closed modal with nothing
           said, so "Add Exhibit" looked like it did nothing. --}}
      @if($addFailed)
        <div class="alert alert-error" style="margin-bottom:14px">
          <strong>The exhibit was not saved.</strong>
          <ul style="margin:6px 0 0;padding-left:16px">
            @foreach($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <div class="fi-row">
        <div class="fg"><label class="fl">Exhibit Code</label><input class="fi" name="exhibit_code" required placeholder="e.g. EXH-009" value="{{ $addFailed ? old('exhibit_code') : '' }}"></div>
        <div class="fg"><label class="fl">Category</label>
          <select class="fi" name="category_id">
            <option value="">— None —</option>
            @foreach($categories as $cat)
            <option value="{{ $cat->category_id }}" @selected($addFailed && old('category_id') == $cat->category_id)>{{ $cat->name }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="fg"><label class="fl">Name</label><input class="fi" name="name" required placeholder="Exhibit name" value="{{ $addFailed ? old('name') : '' }}"></div>
      <div class="fi-row">
        <div class="fg"><label class="fl">Hall</label>
          {{-- Halls come from the Museum Info page; the floor is the hall's. --}}
          <select class="fi" name="hall_id">
            <option value="">No hall yet</option>
            @foreach($halls as $h)
            <option value="{{ $h->hall_id }}" @selected($addFailed && (int) old('hall_id') === $h->hall_id)>{{ $h->name }} · {{ $h->floor }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="fg"><label class="fl">Authors</label><input class="fi" name="authors" placeholder="e.g. Dr. Juan Dela Cruz" value="{{ $addFailed ? old('authors') : '' }}"></div>
      <div class="fg"><label class="fl">Description</label><textarea class="fi" name="description" rows="3" data-autogrow placeholder="Exhibit description…">{{ $addFailed ? old('description') : '' }}</textarea></div>
      <div class="fg"><label class="fl">Fun Facts <span style="font-weight:400;text-transform:none">(one per line)</span></label><textarea class="fi" name="fun_facts" rows="3" data-autogrow placeholder="Enter each fun fact on a new line…">{{ $addFailed ? old('fun_facts') : '' }}</textarea></div>

      {{-- Translations & audio: drafted by the AI from the fields above,
           read and corrected here, saved with the exhibit. See
           public/js/exhibit-ai.js. On a refused save the cards come back
           from old() so nothing typed or narrated is lost. --}}
      <div class="ai-title">Translations &amp; Audio Guide</div>
      <div class="ai-section" data-ai-root
           data-translate-url="{{ route('exhibits.ai.translate') }}"
           data-narrate-url="{{ route('exhibits.ai.narrate') }}"
           data-name-field="#addModal input[name=name]"
           data-desc-field="#addModal textarea[name=description]"
           data-facts-field="#addModal textarea[name=fun_facts]"
           data-source-language="{{ $addFailed ? old('source_language', 'en') : 'en' }}"
           data-existing='@json($addFailed ? $restoredCards : [])'></div>

      <div class="fi-row">
        <div class="fg"><label class="fl">Languages</label><input class="fi" name="languages" value="{{ $addFailed ? old('languages', 'Filipino,English') : 'Filipino,English' }}"></div>
        <div class="fg"><label class="fl">Storyline Order</label><input class="fi" type="number" name="storyline_order" value="{{ $addFailed ? old('storyline_order') : '' }}" min="1" placeholder="Leave blank to add it last (#{{ $nextOrder }})"></div>
      </div>
      <div class="fg">
        <label class="fl">Image <span style="font-weight:400;text-transform:none">(JPG, PNG, GIF or WebP, up to 10 MB)</span></label>
        <input class="fi" type="file" name="image" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp">
        @if($addFailed)
        <div style="font-size:12px;color:var(--text-3);margin-top:4px">If you attached a picture, choose it again — files are not kept when the form is sent back.</div>
        @endif
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-green">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Exhibit
        </button>
      </div>
    </form>
  </div>
</div>

{{-- ── Exhibit Detail / Edit Modal ── --}}
<div class="overlay" id="exModal">
  <div class="modal modal-lg" style="padding:0;overflow:hidden">

    {{-- Header — always visible --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;border-bottom:1px solid var(--border)">
      <div>
        <h3 id="exModalTitle" style="font-size:17px;line-height:1.2">Exhibit</h3>
        <div id="exModalCode" style="font-size:11.5px;color:var(--text-3);margin-top:2px"></div>
      </div>
      <div style="display:flex;align-items:center;gap:6px">
        {{-- View mode actions --}}
        <div class="view-mode" style="display:flex;gap:6px;align-items:stretch">
          <button class="btn btn-green btn-sm" id="exEditBtn" onclick="switchToEdit()" style="height:32px;min-width:64px;justify-content:center;padding:0 14px;font-size:12px">Edit</button>
          <a id="exQrBtn" href="#" class="btn btn-outline btn-sm" style="height:32px;min-width:64px;justify-content:center;padding:0 14px;font-size:12px">QR Code</a>
          <form id="exArchiveForm" method="POST" style="display:flex">
            @csrf
            <button id="exArchiveBtn" class="btn btn-outline btn-sm" type="submit" style="height:32px;min-width:64px;justify-content:center;padding:0 14px;font-size:12px">Archive</button>
          </form>
        </div>
        {{-- Edit mode back button --}}
        <button class="btn btn-outline btn-sm edit-mode" onclick="switchToView()" style="height:32px;min-width:64px;justify-content:center;padding:0 14px;font-size:12px">Back</button>
        {{-- Close --}}
        <button class="btn btn-outline btn-sm" onclick="closeExModal()" style="height:32px;width:32px;min-width:32px;justify-content:center;padding:0;font-size:14px">×</button>
      </div>
    </div>

    {{-- View mode body --}}
    <div class="view-mode" id="exViewBody" style="overflow-y:auto;max-height:calc(90vh - 70px)">
      <div style="display:flex;align-items:center;justify-content:center;padding:40px">
        <div class="spinner"></div>
      </div>
    </div>

    {{-- Edit mode body --}}
    <div class="edit-mode" id="exEditBody" style="overflow-y:auto;max-height:calc(90vh - 70px)">
    </div>

  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/qrcode.min.js') }}"></script>
<script src="{{ asset('js/exhibit-ai.js') }}?v=3"></script>
<script>
// ── Filter ────────────────────────────────────────────────────
function filterExhibits(){
  const q=document.getElementById('exSearch').value.toLowerCase();
  const floor=document.getElementById('exFloor').value.toLowerCase();
  const hall=document.getElementById('exHall').value.toLowerCase();
  const cat=document.getElementById('exCat').value.toLowerCase();
  const status=document.getElementById('exStatus').value;
  const sort=document.getElementById('exSort').value;
  const grid=document.getElementById('exGrid');
  const cards=Array.from(grid.querySelectorAll('.ex-card'));
  let visible=0;
  cards.forEach(c=>{
    const match=(!q||c.dataset.name.includes(q))&&(!floor||c.dataset.floor.toLowerCase()===floor)&&(!hall||c.dataset.hall.toLowerCase()===hall)&&(!cat||c.dataset.cat.toLowerCase()===cat)&&(status===''||c.dataset.status===status);
    c.style.display=match?'':'none';
    if(match)visible++;
  });
  const vis=cards.filter(c=>c.style.display!=='none');
  vis.sort((a,b)=>{
    if(sort==='name')return a.dataset.name.localeCompare(b.dataset.name);
    if(sort==='scans')return parseInt(b.dataset.scans||0)-parseInt(a.dataset.scans||0);
    return parseInt(a.dataset.order||0)-parseInt(b.dataset.order||0);
  });
  vis.forEach(c=>grid.appendChild(c));
  document.getElementById('exCount').textContent=visible+' exhibit'+(visible!==1?'s':'');
}
filterExhibits();
document.getElementById('addModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open')});
@if($addFailed)
document.getElementById('addModal').classList.add('open');
@endif
document.getElementById('exModal').addEventListener('click',function(e){if(e.target===this)closeExModal()});

// ── State ─────────────────────────────────────────────────────
let _currentId = null;
let _editLoaded = false;

// ── Open modal ────────────────────────────────────────────────
function openExModal(id){
  _currentId = id;
  _editLoaded = false;

  const modal = document.getElementById('exModal');
  modal.classList.remove('editing');
  modal.classList.add('open');

  // Reset view body to spinner
  document.getElementById('exViewBody').innerHTML =
    '<div style="display:flex;align-items:center;justify-content:center;padding:40px"><div class="spinner"></div></div>';
  document.getElementById('exEditBody').innerHTML = '';

  // Load read-only data
  fetch('{{ url('/') }}/exhibits/'+id+'/modal')
    .then(r=>r.json())
    .then(d=>renderView(d))
    .catch(()=>{
      document.getElementById('exViewBody').innerHTML =
        '<p style="color:var(--red);padding:20px;text-align:center">Failed to load.</p>';
    });
}

function closeExModal(){
  document.getElementById('exModal').classList.remove('open','editing');
  _currentId = null;
}

// ── Render read-only view ─────────────────────────────────────
function renderView(d){
  const id = d.exhibit_id;
  const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const chip = v => `<span class="ex-chip">${v}</span>`;

  // Update header
  document.getElementById('exModalTitle').textContent = d.name;
  document.getElementById('exModalCode').textContent  = d.exhibit_code;

  // Wire QR link — opens QR modal for this exhibit
  document.getElementById('exQrBtn').onclick = function(e) {
    e.preventDefault();
    openQrModal(d.exhibit_id, d.name, d.exhibit_code, d.floor, d.hall);
  };

  // Wire archive/restore form
  const archForm = document.getElementById('exArchiveForm');
  const archBtn  = document.getElementById('exArchiveBtn');
  if(d.status){
    archForm.action = '{{ url('/') }}/exhibits/'+id+'/archive';
    archForm.onsubmit = ()=>confirm('Archive this exhibit?');
    archBtn.textContent = 'Archive';
    archBtn.className = 'btn btn-outline btn-sm';
  } else {
    archForm.action = '{{ url('/') }}/exhibits/'+id+'/restore';
    archForm.onsubmit = null;
    archBtn.textContent = 'Restore';
    archBtn.className = 'btn btn-outline btn-sm';
  }

  const statusBadge = d.status
    ? '<span class="badge b-green">Active</span>'
    : '<span class="badge b-red">Archived</span>';
  const catBadge = d.category
    ? `<span class="badge b-gold">${esc(d.category)}</span>`
    : '<span style="color:var(--text-4)">—</span>';

  const transHtml = d.translations && d.translations.length
    ? d.translations.map(t=>`
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-light)">
          <div style="min-width:90px">
            <div style="font-size:12.5px;font-weight:600;color:var(--text)">${esc(t.language_label)}</div>
            <span class="badge b-gray" style="font-size:10px;margin-top:2px">${esc(t.language_code)}</span>
          </div>
          <div style="flex:1">
            ${t.title?`<div style="font-size:12px;color:var(--text-3);margin-bottom:4px">${esc(t.title)}</div>`:''}
            ${t.audio_file
              ?`<audio controls style="height:26px;width:100%"><source src="${t.audio_url||'/storage/audio/'+esc(t.audio_file)}"></audio>`
              :'<span style="font-size:11px;color:var(--text-4)">No audio file</span>'}
          </div>
        </div>`).join('')
    : '<p style="font-size:13px;color:var(--text-3);padding:8px 0">No translations added yet.</p>';

  document.getElementById('exViewBody').innerHTML = `
    <div style="padding:18px 22px">
      <div class="ex-two">
        <div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
            ${catBadge}
            ${chip(esc(d.floor)+' · '+esc(d.hall))}
            ${d.authors ? chip(esc(d.authors)) : ''}
            ${d.languages ? chip(esc(d.languages)) : ''}
            ${d.storyline_order ? chip('Story #'+d.storyline_order) : ''}
            ${statusBadge}
            <span class="ex-chip" style="color:var(--green-dark);font-weight:700">${(d.scans_count||0).toLocaleString()} scans</span>
          </div>

          ${d.description ? `
            <div style="margin-bottom:14px">
              <div class="ex-vh">Description</div>
              <div class="ex-vt">${esc(d.description)}</div>
            </div>` : ''}

          ${d.fun_facts ? `
            <div style="margin-bottom:14px">
              <div class="ex-vh">Fun Facts</div>
              <div class="ex-vt">${esc(d.fun_facts)}</div>
            </div>` : ''}

          <div>
            <div class="ex-vh">Translations &amp; Audio</div>
            ${transHtml}
          </div>
        </div>

        <div class="ex-side">
          ${d.image ? `<img src="/exhibit-image/${encodeURIComponent(d.image)}" class="ex-side-img" alt="">` : ''}
          ${d.qr_url ? `
            <div style="margin-top:12px;text-align:center">
              <img src="${esc(d.qr_url)}?t=${Date.now()}" style="width:120px;height:120px;background:#fff;border:1px solid var(--border);border-radius:6px" alt="QR code for ${esc(d.exhibit_code)}">
              <div style="margin-top:6px"><a class="btn btn-outline btn-xs" href="${esc(d.qr_url)}?download=1">Download QR</a></div>
            </div>` : ''}
        </div>
      </div>
    </div>`;
}

// ── Switch to edit mode ───────────────────────────────────────
function switchToEdit(){
  const modal = document.getElementById('exModal');
  modal.classList.add('editing');

  if(_editLoaded) return;
  _editLoaded = true;

  const editBody = document.getElementById('exEditBody');
  editBody.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:40px"><div class="spinner"></div></div>';

  fetch('{{ url('/') }}/exhibits/'+_currentId+'/edit-form')
    .then(r=>r.text())
    .then(html=>{
      editBody.innerHTML = html;
      // The edit form carries its own translations & audio review section.
      if (window.ExhibitAI) window.ExhibitAI.mountAll(editBody);
      if (window.autogrow) window.autogrow(editBody);
      // Wire the form's cancel buttons back to view mode
      editBody.querySelectorAll('button[onclick*="closeEditModal"], button[onclick*="cancelHallEdit"]').forEach(btn=>{
        btn.removeAttribute('onclick');
        btn.addEventListener('click', switchToView);
      });
      // On save, mark edit as stale so it reloads next time
      const form = editBody.querySelector('form');
      if(form) form.addEventListener('submit', ()=>{ _editLoaded = false; });
    })
    .catch(()=>{
      editBody.innerHTML = '<p style="color:var(--red);padding:20px;text-align:center">Failed to load edit form.</p>';
    });
}

// ── Switch back to view mode ──────────────────────────────────
function switchToView(){
  document.getElementById('exModal').classList.remove('editing');
}

// Legacy aliases
function openEditModal(id){ _currentId=id; switchToEdit(); }
function closeEditModal(){ switchToView(); }

// ── QR Modal ──────────────────────────────────────────────────
function openQrModal(id, name, code, floor, hall) {
  const modal = document.getElementById('qrModal');
  document.getElementById('qrModalName').textContent = name;
  document.getElementById('qrModalCode').textContent = code;
  document.getElementById('qrModalLoc').textContent  = (floor || '') + (floor && hall ? ' · ' : '') + (hall || '');

  // Clear previous QR
  const wrap = document.getElementById('qrCanvas');
  wrap.innerHTML = '';

  const scanUrl = '{{ url("/visitor/index.php") }}' + '?scan=' + encodeURIComponent(code);

  const canvas = document.createElement('canvas');
  wrap.appendChild(canvas);

  QRCode.toCanvas(canvas, scanUrl, {
    width: 220,
    margin: 2,
    color: { dark: '#1a1a2e', light: '#ffffff' },
    errorCorrectionLevel: 'H'
  });

  modal.classList.add('open');
}

function closeQrModal() {
  document.getElementById('qrModal').classList.remove('open');
}

function printSingleQr() {
  const name = document.getElementById('qrModalName').textContent;
  const code = document.getElementById('qrModalCode').textContent;
  const loc  = document.getElementById('qrModalLoc').textContent;
  const canvas = document.querySelector('#qrCanvas canvas');
  if (!canvas) return;
  const imgSrc = canvas.toDataURL('image/png');

  const w = window.open('', '_blank');
  w.document.write(`<!DOCTYPE html><html><head>
    <title>QR \u2014 ${code}</title>
    <link href="https://fonts.googleapis.com/css2?family=Young+Serif&family=Instrument+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:'Instrument Sans',sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;background:#fff;padding:32px}
      .card{border:1px solid #e5e7eb;border-radius:16px;padding:32px 40px;text-align:center;max-width:320px;width:100%}
      img{width:220px;height:220px;display:block;margin:0 auto}
      .name{font-family:'Young Serif',serif;font-size:18px;color:#1a1a1a;margin-top:16px;line-height:1.3}
      .code{font-size:13px;color:#16a34a;font-weight:700;margin-top:6px}
      .loc{font-size:12px;color:#9ca3af;margin-top:4px}
    </style>
  </head><body>
    <div class="card">
      <img src="${imgSrc}" width="220" height="220">
      <div class="name">${name}</div>
      <div class="code">${code}</div>
      <div class="loc">${loc}</div>
    </div>
    <script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};};<\/script>
  </body></html>`);
  w.document.close();
}

document.getElementById('qrModal').addEventListener('click', function(e) {
  if (e.target === this) closeQrModal();
});
</script>

{{-- QR Modal --}}
<div class="overlay" id="qrModal">
  <div class="modal" style="max-width:380px;text-align:center;padding:0;overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border)">
      <div style="text-align:left">
        <div id="qrModalName" style="font-family:'Young Serif',serif;font-size:16px;color:var(--text)"></div>
        <div id="qrModalCode" style="font-size:11px;color:var(--green-dark);font-weight:700;margin-top:2px"></div>
      </div>
      <button class="btn btn-outline btn-sm" onclick="closeQrModal()" style="width:30px;height:30px;min-width:30px;padding:0;justify-content:center">×</button>
    </div>
    <div style="padding:24px;display:flex;flex-direction:column;align-items:center;gap:12px">
      <div id="qrCanvas"></div>
      <div id="qrModalLoc" style="font-size:12px;color:var(--text-3)"></div>
    </div>
    <div style="padding:0 20px 20px;display:flex;gap:8px;justify-content:center">
      <button class="btn btn-green btn-sm" onclick="printSingleQr()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print QR
      </button>
      <button class="btn btn-outline btn-sm" onclick="closeQrModal()">Close</button>
    </div>
  </div>
</div>
@endpush
