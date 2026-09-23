@extends($layout)
@section('title', ($exhibit ? $exhibit->name : 'Background') . ' — Recognition Photos')

@push('styles')
<style>
.rp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px}
.rp-thumb{position:relative;border-radius:8px;overflow:hidden;background:var(--border-light);aspect-ratio:1}
.rp-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.rp-del{position:absolute;top:4px;right:4px;background:rgba(220,38,38,.85);border:none;border-radius:50%;width:24px;height:24px;color:#fff;cursor:pointer;font-size:13px;line-height:24px;text-align:center;padding:0}
/* Select mode: the x gives way to a tick box on every photo; tapping the
   photo toggles it. Big enough for a thumb. */
.rp-pick{display:none;position:absolute;inset:0;cursor:pointer}
.rp-pick input{position:absolute;top:6px;left:6px;width:22px;height:22px;margin:0;accent-color:var(--green-dark)}
.rp-grid.selecting .rp-pick{display:block}
.rp-grid.selecting .rp-del{display:none}
.rp-grid.selecting .rp-thumb:has(input:checked){outline:3px solid var(--green);outline-offset:-3px}
.rp-grid.selecting .rp-thumb:has(input:checked) img{opacity:.6}
.rp-count{font-family:'Young Serif',serif;font-size:34px;line-height:1;color:var(--text)}
.rp-shoot{display:flex;flex-direction:column;gap:10px;margin-top:14px}
.rp-shoot label.btn{cursor:pointer;justify-content:center}
.rp-shoot input[type=file]{display:none}
.rp-tips{font-size:12.5px;color:var(--text-3);line-height:1.6;margin:0;padding-left:18px}
.rp-tips li{margin-bottom:3px}
.rp-busy{display:none;font-size:13px;color:var(--text-3);text-align:center;margin-top:10px}
@media (min-width:700px){.rp-cols{display:grid;grid-template-columns:1fr 1.4fr;gap:20px;align-items:start}}
</style>
@endpush

@section('note')
  This is the phone view — taking recognition photos.<br>
@endsection

@section('content')
@php
  $target = $exhibit ? route('recognition.photos.upload', $exhibit) : route('recognition.background.upload');
  $count  = $photos->count();
  $tone   = $count >= $goodPhotos ? 'b-green' : ($count >= $minPhotos ? 'b-gold' : 'b-red');
  $word   = $count >= $goodPhotos ? 'Good' : ($count >= $minPhotos ? 'Enough, more is better' : 'Not enough yet');
@endphp

@if($layout === 'layouts.admin')
<div class="ph">
  <div class="ph-left">
    <h2>{{ $exhibit ? $exhibit->name : 'Background photos' }}</h2>
    <p>{{ $exhibit ? 'Recognition photos · ' . $exhibit->exhibit_code : 'What the camera sees when it is not pointed at an exhibit' }}</p>
  </div>
  <div class="ph-right">
    <a href="{{ route('recognition.index') }}" class="btn btn-outline btn-sm">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
      Back to Recognition
    </a>
  </div>
</div>
@else
<div style="margin-bottom:14px">
  <div style="font-family:'Young Serif',serif;font-size:19px;color:var(--text)">{{ $exhibit ? $exhibit->name : 'Background photos' }}</div>
  <div style="font-size:12.5px;color:var(--text-3)">{{ $exhibit ? $exhibit->exhibit_code : 'Walls, floors, cases, people' }}</div>
</div>
@endif

@if($errors->any())
  <div class="alert alert-error">{{ $errors->first() }}</div>
@endif

<div class="rp-cols">
  <div class="card card-p" style="margin-bottom:16px">
    <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px">
      <div>
        <div class="rp-count" id="rpCount">{{ $count }}</div>
        <div style="font-size:12.5px;color:var(--text-3);margin-top:4px">photos · aim for {{ $goodPhotos }}+</div>
      </div>
      <span class="badge {{ $tone }}" id="rpBadge">{{ $word }}</span>
    </div>

    <form method="POST" action="{{ $target }}" enctype="multipart/form-data" id="rpForm" class="rp-shoot">
      @csrf
      {{-- One button: the file picker. On a phone the picker itself offers
           the camera as well as the gallery, so a separate "take a photo"
           button only got in the way of uploading a batch. --}}
      <label class="btn btn-green">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Upload photos
        <input type="file" name="photos[]" accept="image/*" multiple data-rp-input>
      </label>
      <noscript><button type="submit" class="btn btn-outline">Upload</button></noscript>
    </form>
    <div class="rp-busy" id="rpBusy">Uploading…</div>

    <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border-light)">
      <div style="font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:6px">How to shoot</div>
      @if($exhibit)
      <ul class="rp-tips">
        <li>Stand where a visitor would stand, phone at chest height.</li>
        <li>Walk around it: front, both sides, closer, further, a little tilted.</li>
        <li>Use the room's own lighting. Do not turn on the flash.</li>
        <li>A few blurry or off-centre shots are fine — the camera will be, too.</li>
        <li>Keep other exhibits out of the frame as much as you can.</li>
      </ul>
      @else
      <ul class="rp-tips">
        <li>Walk the halls and shoot what is <strong>not</strong> an exhibit: walls, floor, doorways, empty cases, the ceiling, people's backs.</li>
        <li>Include the space right beside each exhibit, without the exhibit in it.</li>
        <li>These teach the model to say "nothing here" instead of guessing.</li>
      </ul>
      @endif
    </div>
  </div>

  <div class="card card-p">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <div style="font-size:14px;font-weight:700;color:var(--text)">Photos</div>
      {{-- Two buttons. Select toggles tick boxes on the photos (and turns
           into Select all / Cancel); Remove is "Remove all" outside select
           mode and "Remove (n)" inside it. Without JS the form removes all. --}}
      <div style="display:{{ $count ? 'flex' : 'none' }};gap:6px;flex-wrap:wrap" id="rpTools">
        <button type="button" class="btn btn-outline btn-xs" id="rpSelectBtn">Select</button>
        <button type="button" class="btn btn-outline btn-xs" id="rpCancelBtn" style="display:none">Cancel</button>
        <form method="POST" action="{{ $exhibit ? route('recognition.photos.remove', $exhibit) : route('recognition.background.remove') }}" id="rpRemoveForm" style="margin:0">
          @csrf <input type="hidden" name="all" value="1">
          <button type="submit" class="btn btn-red btn-xs" id="rpRemoveBtn">Remove all</button>
        </form>
      </div>
    </div>
    <div class="rp-grid" id="rpGrid">
      @forelse($photos as $p)
      <div class="rp-thumb" data-photo-id="{{ $p->training_image_id }}">
        <img src="{{ $p->url }}" alt="" loading="lazy">
        <label class="rp-pick"><input type="checkbox" value="{{ $p->training_image_id }}"></label>
        <form method="POST" action="{{ route('recognition.photo.destroy', $p) }}" data-rp-delete>
          @csrf @method('DELETE')
          <button type="submit" class="rp-del" title="Remove">×</button>
        </form>
      </div>
      @empty
      <p id="rpEmpty" style="font-size:13px;color:var(--text-3);grid-column:1/-1">No photos yet. Upload the first ones.</p>
      @endforelse
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
  var form   = document.getElementById('rpForm');
  var grid   = document.getElementById('rpGrid');
  var busy   = document.getElementById('rpBusy');
  var countEl= document.getElementById('rpCount');
  var badge  = document.getElementById('rpBadge');
  var MIN = {{ (int) $minPhotos }}, GOOD = {{ (int) $goodPhotos }};
  var csrf = document.querySelector('meta[name="csrf-token"]');
  csrf = csrf ? csrf.getAttribute('content') : form.querySelector('[name=_token]').value;
  var deleteUrl = @json(route('recognition.photo.destroy', ['photo' => '__ID__']));

  function setCount(n) {
    countEl.textContent = n;
    badge.className = 'badge ' + (n >= GOOD ? 'b-green' : n >= MIN ? 'b-gold' : 'b-red');
    badge.textContent = n >= GOOD ? 'Good' : n >= MIN ? 'Enough, more is better' : 'Not enough yet';
  }

  // Phone shots are 3-5 MB each. Shrinking them here before the upload
  // makes the round trip a second instead of ten on museum wifi; the
  // server shrinks again anyway, so a browser that cannot do it loses nothing.
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!file.type.match(/^image\/(jpeg|png|webp)$/) || !window.createImageBitmap) return resolve(file);
      createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
        var max = 800, s = Math.min(1, max / Math.max(bmp.width, bmp.height));
        var c = document.createElement('canvas');
        c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        c.toBlob(function (b) { resolve(b ? new File([b], 'photo.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.85);
      }).catch(function () { resolve(file); });
    });
  }

  function addThumb(p) {
    var empty = document.getElementById('rpEmpty'); if (empty) empty.remove();
    var tools = document.getElementById('rpTools'); if (tools) tools.style.display = 'flex';
    var d = document.createElement('div');
    d.className = 'rp-thumb'; d.dataset.photoId = p.id;
    d.innerHTML = '<img src="' + p.url + '" alt=""><label class="rp-pick"><input type="checkbox" value="' + p.id + '"></label>' +
      '<form method="POST" action="' + deleteUrl.replace('__ID__', p.id) + '" data-rp-delete>' +
      '<input type="hidden" name="_token" value="' + csrf + '"><input type="hidden" name="_method" value="DELETE">' +
      '<button type="submit" class="rp-del" title="Remove">×</button></form>';
    grid.insertBefore(d, grid.firstChild);
  }

  // Status line. Stays up until the next action - a message that vanishes
  // after a second is a message nobody can check against what happened.
  function status(msg, kind) {
    busy.style.display = 'block';
    busy.textContent = msg;
    busy.style.color = kind === 'error' ? '#b91c1c' : kind === 'ok' ? 'var(--green-dark)' : '';
  }

  // Uploads go in batches of ten. PHP takes at most twenty files in one
  // request (max_file_uploads) and silently drops the rest, which is how
  // "uploading 31" once became twenty photos with a "Saved" on top.
  var BATCH = 10;

  Array.prototype.forEach.call(form.querySelectorAll('[data-rp-input]'), function (input) {
    input.addEventListener('change', async function () {
      var files = Array.prototype.slice.call(input.files);
      input.value = '';
      if (!files.length) return;
      var total = files.length, added = 0, count = null;
      try {
        for (var start = 0; start < total; start += BATCH) {
          var batch = files.slice(start, start + BATCH);
          status('Uploading ' + Math.min(start + batch.length, total) + ' of ' + total + '…');
          var fd = new FormData();
          fd.append('_token', csrf);
          for (var i = 0; i < batch.length; i++) fd.append('photos[]', await shrink(batch[i]));
          var res = await fetch(form.action, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
          var data = await res.json().catch(function () { return {}; });
          if (!res.ok || !data.ok) throw new Error(data.message || (data.errors && Object.values(data.errors)[0][0]) || 'The server refused the upload.');
          data.photos.forEach(addThumb);
          added += data.photos.length;
          count = data.count;
          setCount(count);
          if (data.photos.length !== batch.length) throw new Error('The server kept ' + data.photos.length + ' of ' + batch.length + ' in that batch.');
        }
        status('Added ' + added + ' photo' + (added === 1 ? '' : 's') + '. This set now has ' + count + '.', 'ok');
      } catch (e) {
        status('Added ' + added + ' of ' + total + ', then stopped: ' + e.message + ' Try the rest again.', 'error');
      }
    });
  });

  // ── Select / Remove ──────────────────────────────────────────────────────
  // Outside select mode: Select, Remove all. Inside: Select all, Cancel,
  // Remove (n) - the same Remove button, counting what is ticked.
  var selectBtn  = document.getElementById('rpSelectBtn');
  var cancelBtn  = document.getElementById('rpCancelBtn');
  var removeForm = document.getElementById('rpRemoveForm');
  var removeBtn  = document.getElementById('rpRemoveBtn');
  var removeUrl  = removeForm ? removeForm.action : null;

  function selecting() { return grid.classList.contains('selecting'); }
  function picked() { return Array.prototype.map.call(grid.querySelectorAll('.rp-pick input:checked'), function (i) { return i.value; }); }
  function refresh() {
    var n = picked().length;
    if (selecting()) {
      removeBtn.textContent = 'Remove (' + n + ')';
      removeBtn.disabled = n === 0;
    } else {
      removeBtn.textContent = 'Remove all';
      removeBtn.disabled = false;
    }
  }
  function setSelecting(on) {
    grid.classList.toggle('selecting', on);
    selectBtn.textContent = on ? 'Select all' : 'Select';
    cancelBtn.style.display = on ? '' : 'none';
    if (!on) grid.querySelectorAll('.rp-pick input').forEach(function (i) { i.checked = false; });
    refresh();
  }
  async function removeMany(body, what) {
    var fd = new FormData(); fd.append('_token', csrf);
    Object.keys(body).forEach(function (k) {
      if (Array.isArray(body[k])) body[k].forEach(function (v) { fd.append(k + '[]', v); }); else fd.append(k, body[k]);
    });
    status('Removing ' + what + '…');
    var res = await fetch(removeUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.ok) { status(data.message || 'Could not remove. Nothing was changed.', 'error'); return; }
    if (body.all) grid.querySelectorAll('.rp-thumb').forEach(function (t) { t.remove(); });
    else body.ids.forEach(function (id) { var t = grid.querySelector('.rp-thumb[data-photo-id="' + id + '"]'); if (t) t.remove(); });
    setCount(data.count);
    if (!data.count) {
      grid.innerHTML = '<p id="rpEmpty" style="font-size:13px;color:var(--text-3);grid-column:1/-1">No photos yet. Upload the first ones.</p>';
      document.getElementById('rpTools').style.display = 'none';
    }
    setSelecting(false);
    status('Removed ' + data.removed + ' photo' + (data.removed === 1 ? '' : 's') + '. This set now has ' + data.count + '.', 'ok');
  }

  if (selectBtn) {
    selectBtn.addEventListener('click', function () {
      if (!selecting()) return setSelecting(true);
      grid.querySelectorAll('.rp-pick input').forEach(function (i) { i.checked = true; });
      refresh();
    });
    cancelBtn.addEventListener('click', function () { setSelecting(false); });
    grid.addEventListener('change', function (ev) { if (ev.target.matches('.rp-pick input')) refresh(); });
    removeForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (selecting()) {
        var ids = picked(); if (!ids.length) return;
        if (!confirm('Remove ' + ids.length + ' selected photo' + (ids.length === 1 ? '' : 's') + '?')) return;
        removeMany({ ids: ids }, ids.length + ' photos');
      } else {
        var n = grid.querySelectorAll('.rp-thumb').length;
        if (!confirm('Remove all ' + n + ' photos for this set? The next training will not know it until new photos are taken.')) return;
        removeMany({ all: 1 }, 'all photos');
      }
    });
  }

  grid.addEventListener('submit', async function (ev) {
    var f = ev.target.closest('[data-rp-delete]'); if (!f) return;
    ev.preventDefault();
    if (!confirm('Remove this photo?')) return;
    var thumb = f.closest('.rp-thumb');
    var res = await fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (res.ok) {
      thumb.remove();
      var left = grid.querySelectorAll('.rp-thumb').length;
      setCount(left);
      status('Removed 1 photo. This set now has ' + left + '.', 'ok');
    } else {
      status('Could not remove that photo. Nothing was changed.', 'error');
    }
  });
})();
</script>
@endpush
