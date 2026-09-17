@extends($layout)
@section('title', ($exhibit ? $exhibit->name : 'Background') . ' — Recognition Photos')

@push('styles')
<style>
.rp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px}
.rp-thumb{position:relative;border-radius:8px;overflow:hidden;background:var(--border-light);aspect-ratio:1}
.rp-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.rp-del{position:absolute;top:4px;right:4px;background:rgba(220,38,38,.85);border:none;border-radius:50%;width:24px;height:24px;color:#fff;cursor:pointer;font-size:13px;line-height:24px;text-align:center;padding:0}
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
    <a href="{{ route('recognition.index') }}" class="btn btn-outline btn-sm">← Back to Recognition</a>
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
      <label class="btn btn-green">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        Take a photo
        <input type="file" name="photos[]" accept="image/*" capture="environment" data-rp-input>
      </label>
      <label class="btn btn-outline">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Choose from gallery
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
    <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:12px">Photos</div>
    <div class="rp-grid" id="rpGrid">
      @forelse($photos as $p)
      <div class="rp-thumb" data-photo-id="{{ $p->training_image_id }}">
        <img src="{{ $p->url }}" alt="" loading="lazy">
        <form method="POST" action="{{ route('recognition.photo.destroy', $p) }}" data-rp-delete>
          @csrf @method('DELETE')
          <button type="submit" class="rp-del" title="Remove">×</button>
        </form>
      </div>
      @empty
      <p id="rpEmpty" style="font-size:13px;color:var(--text-3);grid-column:1/-1">No photos yet. Take the first one.</p>
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
    var d = document.createElement('div');
    d.className = 'rp-thumb'; d.dataset.photoId = p.id;
    d.innerHTML = '<img src="' + p.url + '" alt=""><form method="POST" action="' + deleteUrl.replace('__ID__', p.id) + '" data-rp-delete>' +
      '<input type="hidden" name="_token" value="' + csrf + '"><input type="hidden" name="_method" value="DELETE">' +
      '<button type="submit" class="rp-del" title="Remove">×</button></form>';
    grid.insertBefore(d, grid.firstChild);
  }

  Array.prototype.forEach.call(form.querySelectorAll('[data-rp-input]'), function (input) {
    input.addEventListener('change', async function () {
      if (!input.files.length) return;
      busy.style.display = 'block';
      busy.textContent = 'Uploading ' + input.files.length + ' photo' + (input.files.length > 1 ? 's' : '') + '…';
      try {
        var fd = new FormData();
        fd.append('_token', csrf);
        for (var i = 0; i < input.files.length; i++) fd.append('photos[]', await shrink(input.files[i]));
        var res = await fetch(form.action, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.ok) throw new Error(data.message || (data.errors && Object.values(data.errors)[0][0]) || 'Upload failed.');
        data.photos.forEach(addThumb);
        setCount(data.count);
        busy.textContent = 'Saved.';
      } catch (e) {
        busy.textContent = e.message;
      } finally {
        input.value = '';
        setTimeout(function () { busy.style.display = 'none'; }, 1500);
      }
    });
  });

  grid.addEventListener('submit', async function (ev) {
    var f = ev.target.closest('[data-rp-delete]'); if (!f) return;
    ev.preventDefault();
    if (!confirm('Remove this photo?')) return;
    var thumb = f.closest('.rp-thumb');
    var res = await fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (res.ok) {
      thumb.remove();
      setCount(grid.querySelectorAll('.rp-thumb').length);
    }
  });
})();
</script>
@endpush
